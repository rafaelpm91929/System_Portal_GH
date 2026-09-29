// =========================================================================
// JS INFRAESTRUCTURA - ENGINE DUAL 3D / 2D INTERACTIVE DRAG & DROP 42U RACKS
// =========================================================================

let siteScene3D = null;
let siteCamera3D = null;
let siteRenderer3D = null;
let siteControls3D = null;
let transformControls3D = null;
let raycaster3D = null;
let mouseVector3D = null;
let selectedObject3D = null;
let boxHelper3D = null;
let siteAnimFrameId = null;
let isSite3DInit = false;
let currentSiteMode = '3d';
let active3DContainerId = 'siteCanvas3DContainer';

// Estado de Arrastre 2D Interno Canvas y Paleta Flotante
let draggedMountedItem = null;
let selectedMountedRackItem = null; // { rackKey, itemIndex, item, canvasId }
let dragStartPos = { x: 0, y: 0 };
let hasDraggedSignificantDistance = false;
let mouseCanvasPos = { x: 0, y: 0 };
let currentPaletteDraggedTool = null;
let currentPaletteDragHover = null; // { canvasId, rackKey, rackIndex, slotFromTop, uHeight, tipoTool }

function getComponentUHeight(tipoTool, nombre = '') {
    if (!tipoTool && !nombre) return 1;
    let t = tipoTool;
    let n = nombre;
    if (typeof t === 'string' && t.startsWith('inv:')) {
        const invKey = t.replace('inv:', '');
        const eq = (window.equiposInventarioMap && window.equiposInventarioMap[invKey]) ? window.equiposInventarioMap[invKey] : null;
        if (eq) {
            t = eq.tipo || '';
            n = eq.label || '';
        }
    }
    const combined = (String(t || '') + ' ' + String(n || '')).toLowerCase();
    if (combined.includes('ups_1') || combined.includes('industronic')) return 3; // UPS 1 Industronic (3U)
    if (combined.includes('ups_2') || combined.includes('apc') || combined.includes('smart-ups') || combined.includes('smart_ups')) return 3; // UPS 2 APC Smart-UPS 3000 (3U)
    if (combined.includes('servidor_2') || combined.includes('servidor 2')) return 3; // Servidor 2 Modular Chasis (3U)
    if (combined.includes('ups')) return 3; // UPS 3U
    if (combined.includes('buffalo')) return 2; // NAS Buffalo TeraStation (2U)
    if (combined.includes('datto')) return 1; // NAS Datto SIRIS BCDR es ESTRICTAMENTE 1U (1 rendija)
    if (combined.includes('servidor') || combined.includes('qnap') || combined.includes('btac')) return 1; // 1U
    if (combined.includes('switch') || combined.includes('udm') || combined.includes('ont') || combined.includes('fortinet') || combined.includes('mcm')) return 1;
    if (combined.includes('pdu') || combined.includes('tierra')) return 1;
    if (combined.includes('nas')) return 2; // Fallback para NAS genérico no especificado
    return 1;
}

// DETERMINAR SI EL EQUIPO ES TIPO TORRE DE MEDIA ANCHURA (PERMITE 2 EN EL ANCHO DE LA BANDEJA 3U)
function isHalfWidthItem(tipoTool, nombre = '') {
    let t = tipoTool;
    let n = nombre;
    if (typeof t === 'string' && t.startsWith('inv:')) {
        const invKey = t.replace('inv:', '');
        const eq = (window.equiposInventarioMap && window.equiposInventarioMap[invKey]) ? window.equiposInventarioMap[invKey] : null;
        if (eq) {
            t = eq.tipo || '';
            n = eq.label || '';
        }
    }
    const combined = (String(t || '') + ' ' + String(n || '')).toLowerCase();
    return combined.includes('ups_1') || combined.includes('industronic') ||
           combined.includes('ups_2') || combined.includes('apc') || combined.includes('smart-ups') || combined.includes('smart_ups') ||
           combined.includes('servidor_2') || combined.includes('servidor 2') ||
           combined.includes('buffalo');
}

// CALCULAR POSICIÓN Y LÍMITES 2D EXACTOS DE UN EQUIPO EN EL CANVAS (CONSIDERANDO ANCHO COMPLETO O MEDIA ANCHURA)
function getItemBounds2D(item, rackStartX, rW, startY, slotH, isCompact) {
    const uHeight = getComponentUHeight(item.type, item.name);
    const eqY = startY + ((20 - item.u) * slotH);
    const eqH = slotH * (uHeight - 0.1);
    const innerX = rackStartX + 18;
    const innerW = rW - 36;

    if (isHalfWidthItem(item.type, item.name)) {
        const halfW = (innerW - 8) / 2;
        const itemX = (item.slotPos === 'right') ? (innerX + halfW + 6) : (innerX + 2);
        return { x: itemX, y: eqY, w: halfW, h: eqH, isHalf: true, innerX, innerW };
    }

    return { x: innerX, y: eqY, w: innerW, h: eqH, isHalf: false, innerX, innerW };
}

let siteRacksData = {};

/**
 * GESTIÓN DE ESPACIO Y OCUPACIÓN DE RENDIJAS (U) EN RACKS (U1 A U20)
 * =========================================================================
 * Cada rack cuenta con 20 rendijas disponibles (U1 a U20).
 * Un equipo colocado en targetU con altura uHeight ocupa las unidades:
 * [targetU, targetU - 1, ..., targetU - uHeight + 1].
 */
function getOccupiedUSlots(rackKey, excludeItem = null) {
    const rack = siteRacksData[rackKey];
    if (!rack || !Array.isArray(rack.items)) return new Set();
    const occupied = new Set();
    rack.items.forEach(it => {
        if (!it) return;
        if (excludeItem && it === excludeItem) return;
        const uH = getComponentUHeight(it.type, it.name);
        const baseU = parseInt(it.u, 10);
        if (isNaN(baseU)) return;
        for (let k = 0; k < uH; k++) {
            const occupiedU = baseU - k;
            if (occupiedU >= 1 && occupiedU <= 20) {
                occupied.add(occupiedU);
            }
        }
    });
    return occupied;
}

function isSlotRangeFree(rackKey, targetU, uHeight, excludeItem = null, incomingTool = '', incomingName = '') {
    const uH = parseInt(uHeight, 10) || 1;
    const baseU = parseInt(targetU, 10);
    if (isNaN(baseU)) return false;
    if (baseU > 20 || (baseU - uH + 1) < 1) return false;

    const rack = siteRacksData[rackKey];
    if (!rack || !Array.isArray(rack.items)) return true;

    const isIncomingHalf = isHalfWidthItem(incomingTool, incomingName);

    // Conjunto de unidades U que ocuparía este equipo: [baseU, baseU - 1, ..., baseU - uH + 1]
    const targetSlots = new Set();
    for (let k = 0; k < uH; k++) {
        targetSlots.add(baseU - k);
    }

    const overlappingItems = [];
    rack.items.forEach(it => {
        if (!it || it === excludeItem) return;
        const itH = getComponentUHeight(it.type, it.name);
        const itBaseU = parseInt(it.u, 10);
        if (isNaN(itBaseU)) return;

        let overlaps = false;
        for (let k = 0; k < itH; k++) {
            if (targetSlots.has(itBaseU - k)) {
                overlaps = true;
                break;
            }
        }
        if (overlaps) {
            overlappingItems.push(it);
        }
    });

    if (overlappingItems.length === 0) {
        return true; // Rendijas totalmente libres
    }

    // Si el equipo entrante es de media anchura (ej. UPS 1, UPS 2, Servidor 2)
    // se permite colocar 2 en el ancho de las rendijas si coinciden en la misma bandeja baseU y no hay otro dispositivo incompatible
    if (isIncomingHalf) {
        if (overlappingItems.length === 1) {
            const existing = overlappingItems[0];
            const existingH = getComponentUHeight(existing.type, existing.name);
            const existingBaseU = parseInt(existing.u, 10);

            if (isHalfWidthItem(existing.type, existing.name) && existingBaseU === baseU && existingH === uH) {
                return true; // Se permite poner 2 en el largo de las 3 rendijas
            }
        }
    }

    return false;
}

function findNextFreeSlot(rackKey, uHeight, excludeItem = null, incomingTool = '', incomingName = '') {
    const uH = parseInt(uHeight, 10) || 1;
    for (let u = 20; u >= uH; u--) {
        if (isSlotRangeFree(rackKey, u, uH, excludeItem, incomingTool, incomingName)) {
            return u;
        }
    }
    return null;
}

/**
 * FUNCIÓN DE RESTRICCIÓN (CLAMPING / BOUNDING BOX & SNAP TO GRID)
 * =========================================================================
 * Calcula las coordenadas seguras (x, y) de un componente dentro de un mapa 2D
 * sincronizado con una escena 3D, garantizando que el objeto jamás desborde los 
 * límites del grid/escenario ni en (0,0) ni en (anchoMax, profundidadMax).
 */
function calcularPosicionSeguraComponente(deseadaX, deseadaY, componente, limitesMapa, opciones = {}) {
    const anchoBase = componente.w || componente.ancho || 50;
    const altoBase = componente.h || componente.largo || componente.profundidad || 50;
    const escala = componente.scale || componente.escala || 1.0;
    const rotacionGrados = componente.rot || componente.rotacion || 0;

    const effW = anchoBase * escala;
    const effH = altoBase * escala;

    const rad = (rotacionGrados * Math.PI) / 180;
    const aabbW = Math.abs(effW * Math.cos(rad)) + Math.abs(effH * Math.sin(rad));
    const aabbH = Math.abs(effW * Math.sin(rad)) + Math.abs(effH * Math.cos(rad));

    const anchoMax = limitesMapa.anchoMax || 1000;
    const profundidadMax = limitesMapa.profundidadMax || 600;
    const padding = limitesMapa.padding || 0;

    const usarSnapGrid = opciones.usarSnapGrid !== false;
    const tamanoGrid = opciones.tamanoGrid || 10;
    const pivot = opciones.pivot || 'top-left';

    let minX, maxX, minY, maxY;

    if (pivot === 'center') {
        minX = (aabbW / 2) + padding;
        maxX = anchoMax - (aabbW / 2) - padding;
        minY = (aabbH / 2) + padding;
        maxY = profundidadMax - (aabbH / 2) - padding;
    } else {
        minX = padding;
        maxX = anchoMax - aabbW - padding;
        minY = padding;
        maxY = profundidadMax - aabbH - padding;
    }

    if (maxX < minX) maxX = minX;
    if (maxY < minY) maxY = minY;

    let clampedX = Math.max(minX, Math.min(maxX, deseadaX));
    let clampedY = Math.max(minY, Math.min(maxY, deseadaY));

    if (usarSnapGrid && tamanoGrid > 0) {
        let snappedX = Math.round(clampedX / tamanoGrid) * tamanoGrid;
        let snappedY = Math.round(clampedY / tamanoGrid) * tamanoGrid;

        clampedX = Math.max(minX, Math.min(maxX, snappedX));
        clampedY = Math.max(minY, Math.min(maxY, snappedY));
    }

    return {
        x: clampedX,
        y: clampedY,
        aabbW: aabbW,
        aabbH: aabbH
    };
}

document.addEventListener('DOMContentLoaded', () => {
    // Si existen datos guardados en la BD MySQL, restaurarlos de manera segura
    try {
        let data = window.savedSiteRacksData;
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(err) {}
        }
        if (data && typeof data === 'object') {
            if (data.racks && typeof data.racks === 'object' && Object.keys(data.racks).length > 0) {
                siteRacksData = data.racks;
            } else if (data.rack1) {
                siteRacksData = data;
            }

            if (data.floorplan && Array.isArray(data.floorplan)) {
                siteFloorPlanObjects = data.floorplan;
            }
        }

        if (window.savedSiteFloorPlanData) {
            let fp = window.savedSiteFloorPlanData;
            if (typeof fp === 'string') {
                try { fp = JSON.parse(fp); } catch(err) {}
            }
            if (Array.isArray(fp) && fp.length > 0) {
                siteFloorPlanObjects = fp;
            }
        }
    } catch (e) {
        console.warn('Advertencia leyendo datos guardados SITE:', e);
    }

    if (document.getElementById('siteView3D')) {
        let initialMode = '3d';
        if (window.modoUrl === 'configurar' || window.modoUrl === 'config') {
            initialMode = 'config';
        } else if (window.modoUrl === '2d') {
            initialMode = '2d';
        }
        cambiarModoSite(initialMode);
        asegurarInicializacion3D();
    }
    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');
    initSite2DCanvasDragSystem();
    initSiteFloorPlan2DCanvas();
    drawSiteFloorPlan2D();
});

function asegurarInicializacion3D() {
    if (typeof THREE !== 'undefined') {
        if (document.getElementById('siteView3D') && (!siteScene3D || !siteRenderer3D)) {
            initSite3DScene('siteCanvas3DContainer');
        }
    } else {
        setTimeout(asegurarInicializacion3D, 150);
    }
}

function cambiarModoSite(modo) {
    currentSiteMode = modo || '3d';
    const view3D = document.getElementById('siteView3D');
    const view2D = document.getElementById('siteView2D');
    const viewConfig = document.getElementById('siteViewConfig');

    const btn3D = document.getElementById('btnSiteMode3D');
    const btn2D = document.getElementById('btnSiteMode2D');
    const btnConfig = document.getElementById('btnSiteModeConfig');

    if (view3D) view3D.style.setProperty('display', 'none', 'important');
    if (view2D) view2D.style.setProperty('display', 'none', 'important');
    if (viewConfig) viewConfig.style.setProperty('display', 'none', 'important');

    [btn3D, btn2D, btnConfig].forEach(btn => {
        if (btn) {
            btn.classList.remove('btn-site-active', 'bg-info', 'bg-warning', 'bg-success', 'text-dark', 'text-white');
            btn.classList.add('text-secondary');
        }
    });

    if (modo === '3d') {
        if (view3D) view3D.style.setProperty('display', 'block', 'important');
        if (btn3D) {
            btn3D.classList.remove('text-secondary');
            btn3D.classList.add('btn-site-active', 'bg-info', 'text-dark');
        }
        setTimeout(() => {
            moverRender3DAContenedor('siteCanvas3DContainer');
        }, 50);
    } else if (modo === '2d') {
        if (view2D) view2D.style.setProperty('display', 'block', 'important');
        if (btn2D) {
            btn2D.classList.remove('text-secondary');
            btn2D.classList.add('btn-site-active', 'bg-warning', 'text-dark');
        }
        setTimeout(() => {
            cambiarSubVista2D(currentSubVista2D || 'floorplan');
        }, 50);
    } else if (modo === 'config') {
        if (viewConfig) viewConfig.style.setProperty('display', 'block', 'important');
        if (btnConfig) {
            btnConfig.classList.remove('text-secondary');
            btnConfig.classList.add('btn-site-active', 'bg-success', 'text-dark');
        }
        setTimeout(() => {
            cambiarConfigSubVista(currentConfigSubVista || 'floorplan');
        }, 50);
    }
}

function mostrarPaleta(tipo) {
    const palPlano = document.getElementById('paletteObjetosPlano');
    const palRack = document.getElementById('paletteComponentesRack');
    const tabPlano = document.getElementById('btnTabPaletaPlano');
    const tabRack = document.getElementById('btnTabPaletaRack');

    if (!palPlano || !palRack) return;

    if (tipo === 'plano') {
        palPlano.style.display = 'block';
        palRack.style.display = 'none';
        if (tabPlano) tabPlano.className = 'btn btn-xs rounded-pill px-2 py-0.5 fw-bold text-dark bg-warning shadow-sm';
        if (tabRack) tabRack.className = 'btn btn-xs rounded-pill px-2 py-0.5 fw-bold text-secondary';
    } else {
        palPlano.style.display = 'none';
        palRack.style.display = 'block';
        if (tabPlano) tabPlano.className = 'btn btn-xs rounded-pill px-2 py-0.5 fw-bold text-secondary';
        if (tabRack) tabRack.className = 'btn btn-xs rounded-pill px-2 py-0.5 fw-bold text-dark bg-info shadow-sm';
    }
}

function agregarObjetoPlanoDirecto(toolType) {
    if (toolType === 'rack') {
        if (typeof agregarNuevoRack2D === 'function') agregarNuevoRack2D();
        return;
    }
    const namesMap = {
        'minisplit': 'MINISPLIT INVERTER 18°C ❄️',
        'extintor': 'EXTINTOR SOLKAFLAM 🧯',
        'puerta': 'PUERTA ACCESO SITE 🚪'
    };
    const newObj = {
        id: `obj_${Date.now()}`,
        name: namesMap[toolType] || toolType.toUpperCase(),
        type: toolType,
        x: 160 + Math.floor(Math.random() * 120),
        y: 160 + Math.floor(Math.random() * 120),
        z: toolType === 'minisplit' ? 60 : (toolType === 'extintor' ? 30 : 0),
        w: toolType === 'minisplit' ? 100 : (toolType === 'extintor' ? 36 : 90),
        h: toolType === 'minisplit' ? 28 : (toolType === 'extintor' ? 36 : 18),
        prof: 50,
        rot: 0,
        scale: 1.0,
        color: toolType === 'minisplit' ? '#38bdf8' : (toolType === 'extintor' ? '#ef4444' : '#22c55e')
    };

    siteFloorPlanObjects.push(newObj);
    selectedFloorPlanObj = newObj;

    if (typeof sincronizarPanelEditorObjeto2D === 'function') sincronizarPanelEditorObjeto2D();
    if (typeof drawSiteFloorPlan2D === 'function') drawSiteFloorPlan2D();
    if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
}

function cambiarSubVista2D(sub) {
    currentSubVista2D = sub || 'floorplan';
    const subFP = document.getElementById('subView2DFloorPlan');
    const subElev = document.getElementById('subView2DElevation');
    const btnFP = document.getElementById('btnSub2DFloorPlan');
    const btnElev = document.getElementById('btnSub2DElevation');

    if (subFP) {
        subFP.style.display = 'none';
        subFP.style.setProperty('display', 'none', 'important');
    }
    if (subElev) {
        subElev.style.display = 'none';
        subElev.style.setProperty('display', 'none', 'important');
    }

    if (btnFP) btnFP.className = 'btn btn-sm rounded-pill px-3 py-1.5 fw-bold text-secondary';
    if (btnElev) btnElev.className = 'btn btn-sm rounded-pill px-3 py-1.5 fw-bold text-secondary';

    if (sub === 'floorplan') {
        if (subFP) {
            subFP.style.display = 'flex';
            subFP.style.setProperty('display', 'flex', 'important');
        }
        if (btnFP) btnFP.className = 'btn btn-sm rounded-pill px-3 py-1.5 fw-bold text-dark bg-warning';
        mostrarPaleta('plano');
        initSiteFloorPlan2DCanvas();
        drawSiteFloorPlan2D();
    } else {
        if (subElev) {
            subElev.style.display = 'flex';
            subElev.style.setProperty('display', 'flex', 'important');
        }
        if (btnElev) btnElev.className = 'btn btn-sm rounded-pill px-3 py-1.5 fw-bold text-dark bg-warning';
        mostrarPaleta('rack');
    }
}

let currentConfigSubVista = 'floorplan';

function cambiarConfigSubVista(modo) {
    currentConfigSubVista = modo || 'floorplan';
    const colFP = document.getElementById('colConfigFloorPlan');
    const colElev = document.getElementById('colConfigElevation');
    const col3D = document.getElementById('colConfig3D');

    const btnAll = document.getElementById('btnConfigSubAll');
    const btnFP = document.getElementById('btnConfigSubFP');
    const btnElev = document.getElementById('btnConfigSubElev');
    const btn3D = document.getElementById('btnConfigSub3D');

    [btnAll, btnFP, btnElev, btn3D].forEach(btn => {
        if (btn) btn.className = 'btn btn-xs rounded-pill px-3 py-1 fw-bold text-secondary';
    });

    [colFP, colElev, col3D].forEach(col => {
        if (col) {
            col.style.display = 'none';
            col.style.setProperty('display', 'none', 'important');
            col.classList.remove('d-block', 'col-lg-4', 'col-lg-12');
            col.classList.add('d-none');
        }
    });

    if (modo === 'all') {
        if (btnAll) btnAll.className = 'btn btn-xs rounded-pill px-3 py-1 fw-bold text-dark bg-warning shadow-sm';
        [colFP, colElev, col3D].forEach(col => {
            if (col) {
                col.style.display = 'block';
                col.style.setProperty('display', 'block', 'important');
                col.classList.remove('d-none');
                col.classList.add('col-12', 'col-lg-4', 'd-block');
            }
        });
        mostrarPaleta('rack');
    } else if (modo === 'floorplan') {
        if (btnFP) btnFP.className = 'btn btn-xs rounded-pill px-3 py-1 fw-bold text-dark bg-warning shadow-sm';
        if (colFP) {
            colFP.style.display = 'block';
            colFP.style.setProperty('display', 'block', 'important');
            colFP.classList.remove('d-none');
            colFP.classList.add('col-12', 'col-lg-12', 'd-block');
        }
        mostrarPaleta('plano');
    } else if (modo === 'elevation') {
        if (btnElev) btnElev.className = 'btn btn-xs rounded-pill px-3 py-1 fw-bold text-dark bg-warning shadow-sm';
        if (colElev) {
            colElev.style.display = 'block';
            colElev.style.setProperty('display', 'block', 'important');
            colElev.classList.remove('d-none');
            colElev.classList.add('col-12', 'col-lg-12', 'd-block');
        }
        mostrarPaleta('rack');
    } else if (modo === '3d') {
        if (btn3D) btn3D.className = 'btn btn-xs rounded-pill px-3 py-1 fw-bold text-dark bg-warning shadow-sm';
        if (col3D) {
            col3D.style.display = 'block';
            col3D.style.setProperty('display', 'block', 'important');
            col3D.classList.remove('d-none');
            col3D.classList.add('col-12', 'col-lg-12', 'd-block');
        }
        mostrarPaleta('plano');
    }

    setTimeout(() => {
        if (modo === 'floorplan' || modo === 'all') {
            if (typeof initSiteFloorPlan2DCanvas === 'function') initSiteFloorPlan2DCanvas();
            if (typeof drawSiteFloorPlan2D === 'function') drawSiteFloorPlan2D();
        }
        if (modo === 'elevation' || modo === 'all') {
            if (typeof drawSite2DRackElevation === 'function') {
                drawSite2DRackElevation('siteCanvas2DConfig');
            }
        }
        if (modo === '3d' || modo === 'all') {
            if (typeof moverRender3DAContenedor === 'function') {
                moverRender3DAContenedor('siteCanvas3DContainerConfig');
            }
        }
        if (typeof onSiteWindowResize === 'function') onSiteWindowResize();
    }, 50);
}

// MOVER EL CANVAS WEBGL 3D DE MANERA FLUIDA ENTRE CONTENEDORES SIN PERDER CONTEXTO O CONGELARSE
function moverRender3DAContenedor(targetContainerId) {
    if (typeof THREE === 'undefined') return;

    if (!siteScene3D || !siteRenderer3D) {
        initSite3DScene(targetContainerId);
        return;
    }

    active3DContainerId = targetContainerId;
    const container = document.getElementById(targetContainerId);
    if (!container) return;

    // Eliminar cualquier spinner de carga remanente
    const spinners = container.querySelectorAll('.spinner-border, .py-5');
    spinners.forEach(sp => sp.remove());

    if (siteRenderer3D.domElement.parentElement !== container) {
        const overlay = container.querySelector('#site3DControlOverlay');
        container.innerHTML = '';
        if (overlay) container.appendChild(overlay);
        container.appendChild(siteRenderer3D.domElement);
    }

    onSiteWindowResize();
    sincronizarEscena3DDesde2D();
}

// CONSTANTES DE ESCALADO Y CONVERSIÓN DE COORDENADAS PLANO 2D (1060x580) A SALA 3D (50x50m)
// Delimitación 2D en site_2d.js: X de 60 a 1000 (ancho 940), Y de 30 a 545 (alto 515)
// Sala 3D en Three.js: X de -25 a +25 (ancho 50), Z de -25 a +25 (largo 50)
const SITE_3D_CENTER_2D_X = 530; // (60 + 1000) / 2
const SITE_3D_CENTER_2D_Y = 287.5; // (30 + 545) / 2
const SITE_3D_SCALE_FACTOR_X = 940 / 48; // ~19.58 píxeles por unidad 3D
const SITE_3D_SCALE_FACTOR_Z = 515 / 48; // ~10.73 píxeles por unidad 3D

function pos2DTo3DX(x, w = 0) {
    const centerX = x + (w / 2);
    return (centerX - SITE_3D_CENTER_2D_X) / SITE_3D_SCALE_FACTOR_X;
}
function pos2DTo3DZ(y, h = 0) {
    const centerY = y + (h / 2);
    return (centerY - SITE_3D_CENTER_2D_Y) / SITE_3D_SCALE_FACTOR_Z;
}

function pos3DTo2DX(posX) {
    return Math.round(posX * SITE_3D_SCALE_FACTOR_X + SITE_3D_CENTER_2D_X);
}
function pos3DTo2DY(posZ) {
    return Math.round(posZ * SITE_3D_SCALE_FACTOR_Z + SITE_3D_CENTER_2D_Y);
}

// SINCRONIZACIÓN EN TIEMPO REAL 3D DESDE LOS DATOS 2D (SISTEMA UNIFICADO DE 3 VISTAS)
function sincronizarEscena3DDesde2D() {
    if (typeof THREE === 'undefined' || !siteScene3D) return;

    // 1. Limpiar objetos 3D dinámicos creados previamente
    const toRemove = [];
    siteScene3D.children.forEach(child => {
        if (child.userData && (child.userData.isRack || child.userData.isEquip || child.userData.id)) {
            toRemove.push(child);
        }
    });
    toRemove.forEach(obj => siteScene3D.remove(obj));

    // Si existen objetos definidos en el plano 2D estilo Canva, sincronizarlos
    if (typeof window !== 'undefined' && window.site2DObjects && Array.isArray(window.site2DObjects)) {
        siteFloorPlanObjects = window.site2DObjects;
    }

    // 2. Asegurar correspondencia 1 a 1 de Racks entre Plano 2D y datos de Bastidores
    if (siteFloorPlanObjects && Array.isArray(siteFloorPlanObjects)) {
        siteFloorPlanObjects.forEach(obj => {
            if (obj.type === 'rack' && !siteRacksData[obj.id]) {
                siteRacksData[obj.id] = {
                    title: obj.name,
                    items: [
                        { u: 1, name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' }
                    ]
                };
            }
        });

        // 3. Renderizar cada elemento de siteFloorPlanObjects en su posición y rotación 3D exacta
        siteFloorPlanObjects.forEach(obj => {
            if (!obj || obj.type === 'cota' || obj.type === 'linea_cota') return;

            const objW = obj.w || 90;
            const objH = obj.h || 130;
            const posX = pos2DTo3DX(obj.x, objW);
            const posY = (obj.z || 0) / 10;
            const posZ = pos2DTo3DZ(obj.y, objH);
            const rotRad = -((obj.rot || 0) * Math.PI) / 180; // Negativo para coincidir sentido horario 2D (Y hacia abajo) con 3D (Y hacia arriba)

            if (obj.type === 'rack') {
                const rackData = siteRacksData[obj.id] || { title: obj.name, items: [] };
                const rackMesh = crearRackCompleto3D(posX, posY, posZ, rackData, obj);
                if (rackMesh) {
                    rackMesh.rotation.y = rotRad;
                    if (obj.scale) rackMesh.scale.set(obj.scale, obj.scale, obj.scale);
                }
            } else if (obj.type === 'minisplit') {
                const acMesh = crearMinisplit3D(posX, posY + 16, posZ, { title: obj.name, id: obj.id });
                if (acMesh) {
                    acMesh.userData.id = obj.id;
                    // En 2D rot=90 apunta hacia la derecha, orientar el flujo de aire al frente relativo
                    acMesh.rotation.y = rotRad - (Math.PI / 2);
                    if (obj.scale) acMesh.scale.set(obj.scale, obj.scale, obj.scale);
                }
            } else if (obj.type === 'extintor') {
                const extMesh = crearExtintor3D(posX, posY, posZ, obj, 0xdc2626);
                if (extMesh) {
                    extMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'extintor_verde') {
                const extMesh = crearExtintor3D(posX, posY, posZ, obj, 0x16a34a);
                if (extMesh) {
                    extMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'libreta' || obj.type === 'bitacora') {
                const libMesh = crearBitacora3D(posX, posY, posZ, obj);
                if (libMesh) {
                    libMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'detector_humo') {
                const humoMesh = crearDetectorHumo3D(posX, posY, posZ, obj);
                if (humoMesh) {
                    humoMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'termometro_digital') {
                const termMesh = crearTermometroDigital3D(posX, posY, posZ, obj);
                if (termMesh) {
                    termMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'camara_seguridad' || obj.type === 'camara' || obj.type === 'cctv') {
                const camMesh = crearCamaraSeguridad3D(posX, posY, posZ, obj);
                if (camMesh) {
                    camMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'pared' || obj.type === 'muro') {
                const wallMesh = crearPared3D(posX, posY, posZ, obj);
                if (wallMesh) {
                    wallMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'piso' || obj.type === 'piso_tecnico') {
                const floorMesh = crearPisoTecnico3D(posX, posY, posZ, obj);
                if (floorMesh) {
                    floorMesh.rotation.y = rotRad;
                }
            } else if (obj.type === 'puerta' || obj.type === 'puerta_deslizable') {
                const doorMesh = crearPuertaDeslizable3D(posX, posY, posZ, obj);
                if (doorMesh) {
                    doorMesh.rotation.y = rotRad;
                }
            } else {
                crearComponenteDesdeObjetoFloorPlan3D(obj);
            }
        });
    }

    if (siteRenderer3D && siteCamera3D) {
        siteRenderer3D.render(siteScene3D, siteCamera3D);
    }
}

// SINCRONIZACIÓN AUTOMÁTICA HACIA PLANO 2D AL MOVER/TRANSFORMAR OBJETOS EN 3D
function sincronizar2DDesde3D(mesh3D) {
    if (!mesh3D || !mesh3D.userData) return;
    const objId = mesh3D.userData.id;
    const objName = mesh3D.userData.name;

    let targetObj = siteFloorPlanObjects.find(o => o.id === objId || o.name === objName);
    if (targetObj) {
        targetObj.x = pos3DTo2DX(mesh3D.position.x);
        targetObj.y = pos3DTo2DY(mesh3D.position.z);
        targetObj.z = Math.round(mesh3D.position.y * 10);

        if (mesh3D.rotation && mesh3D.rotation.y !== undefined) {
            let degY = Math.round((mesh3D.rotation.y * 180) / Math.PI) % 360;
            if (degY < 0) degY += 360;
            targetObj.rot = degY;
        }

        selectedFloorPlanObj = targetObj;
        sincronizarPanelEditorObjeto2D();

        requestAnimationFrame(() => {
            drawSiteFloorPlan2D();
        });
    }
}

function getOrderedRackKeys() {
    const orderedKeys = [];

    // Siempre sincronizar con window.site2DObjects si está disponible
    if (typeof window !== 'undefined' && Array.isArray(window.site2DObjects)) {
        siteFloorPlanObjects = window.site2DObjects;
    }

    if (siteFloorPlanObjects && Array.isArray(siteFloorPlanObjects)) {
        const floorPlanRacks = siteFloorPlanObjects.filter(o => o && (o.type === 'rack' || (o.id && String(o.id).toLowerCase().includes('rack'))));

        floorPlanRacks.sort((a, b) => {
            const rowA = Math.floor((a.y || 0) / 60);
            const rowB = Math.floor((b.y || 0) / 60);
            if (rowA !== rowB) return rowA - rowB;
            return (a.x || 0) - (b.x || 0);
        });

        floorPlanRacks.forEach(r => {
            let matchedKey = r.id;
            if (!siteRacksData[matchedKey]) {
                const normId = String(r.id).replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
                const foundKey = Object.keys(siteRacksData).find(k => {
                    return k.replace(/[^a-zA-Z0-9]/g, '').toLowerCase() === normId ||
                           (siteRacksData[k] && siteRacksData[k].title === r.name);
                });
                if (foundKey) {
                    matchedKey = foundKey;
                } else {
                    siteRacksData[r.id] = {
                        title: r.name || 'RACK',
                        items: [
                            { u: 1, name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' }
                        ]
                    };
                    matchedKey = r.id;
                }
            }
            if (matchedKey && !orderedKeys.includes(matchedKey)) {
                orderedKeys.push(matchedKey);
            }
        });

        // EL PLANO 2D ES LA FUENTE DE VERDAD ABSOLUTA DE LOS RACKS EXISTENTES.
        // Si el usuario eliminó todos los racks del plano, orderedKeys debe ser [] (0 racks).
        return orderedKeys;
    }

    // Respaldo únicamente si siteFloorPlanObjects no ha sido inicializado
    if (siteRacksData && typeof siteRacksData === 'object') {
        Object.keys(siteRacksData).forEach(key => {
            if (!orderedKeys.includes(key)) {
                orderedKeys.push(key);
            }
        });
    }

    return orderedKeys;
}

// AGREGAR NUEVO RACK 42U DINÁMICO COMPLETAMENTE SINCRONIZADO EN 3 VISTAS
function agregarNuevoRack2D() {
    const rackKeys = getOrderedRackKeys();
    const newNum = rackKeys.length + 1;
    const newId = `rack${newNum}`;

    siteRacksData[newId] = {
        title: `RACK ${newNum}: NUEVO BASTIDOR 42U`,
        items: [
            { u: 1, name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' }
        ]
    };

    const rackObjs = siteFloorPlanObjects.filter(o => o.type === 'rack');
    const lastRack = rackObjs.length > 0 ? rackObjs[rackObjs.length - 1] : null;
    const newX = lastRack ? Math.min(500, lastRack.x + 110) : 140;
    const newY = lastRack ? lastRack.y : 160;

    const newObj = {
        id: newId,
        name: `RACK ${newNum}: BASTIDOR 42U`,
        type: 'rack',
        x: newX,
        y: newY,
        w: 75,
        h: 110,
        rot: 0,
        scale: 1.0,
        color: '#38bdf8'
    };

    siteFloorPlanObjects.push(newObj);
    selectedFloorPlanObj = newObj;

    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');
    sincronizarEscena3DDesde2D();
}

// ARRASTRE HTML5 DESDE PALETA DE HERRAMIENTAS
function onSiteToolDragStart(event, tipoTool) {
    currentPaletteDraggedTool = tipoTool;
    event.dataTransfer.setData('text/plain', tipoTool);
    event.dataTransfer.effectAllowed = 'copy';
}

function onSite2DDragOver(event, canvasTargetId) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';

    const canvas = document.getElementById(canvasTargetId);
    if (!canvas) return;

    const tipoTool = currentPaletteDraggedTool || event.dataTransfer.getData('text/plain');
    if (!tipoTool) return;

    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const hoverX = (event.clientX - rect.left) * scaleX;
    const hoverY = (event.clientY - rect.top) * scaleY;

    const isCompact = (canvasTargetId === 'siteCanvas2DConfig');
    const rW = isCompact ? 140 : 240;
    const rackHeight = isCompact ? 330 : 450;
    const startY = isCompact ? 42 : 48;
    const gap = isCompact ? 15 : 25;
    const marginX = isCompact ? 15 : 25;
    const slotH = rackHeight / 21;

    const rackKeys = getOrderedRackKeys();
    let targetRackKey = null;
    let targetRackIndex = 0;

    rackKeys.forEach((key, index) => {
        const rackStartX = marginX + index * (rW + gap);
        if (hoverX >= rackStartX && hoverX <= rackStartX + rW) {
            targetRackKey = key;
            targetRackIndex = index;
        }
    });

    if (!targetRackKey) {
        if (currentPaletteDragHover) {
            currentPaletteDragHover = null;
            drawSite2DRackElevation(canvasTargetId);
        }
        return;
    }

    const uHeight = getComponentUHeight(tipoTool);
    let slotFromTop = Math.floor((hoverY - startY) / slotH);
    slotFromTop = Math.max(0, Math.min(20 - uHeight, slotFromTop));
    const targetU = 20 - slotFromTop;
    const isFree = isSlotRangeFree(targetRackKey, targetU, uHeight);

    if (currentPaletteDragHover &&
        currentPaletteDragHover.canvasId === canvasTargetId &&
        currentPaletteDragHover.rackKey === targetRackKey &&
        currentPaletteDragHover.slotFromTop === slotFromTop &&
        currentPaletteDragHover.tipoTool === tipoTool &&
        currentPaletteDragHover.isFree === isFree) {
        return;
    }

    currentPaletteDragHover = {
        canvasId: canvasTargetId,
        rackKey: targetRackKey,
        rackIndex: targetRackIndex,
        slotFromTop: slotFromTop,
        uHeight: uHeight,
        tipoTool: tipoTool,
        targetU: targetU,
        isFree: isFree
    };

    drawSite2DRackElevation(canvasTargetId);
}

function onSite2DDragLeave(event, canvasTargetId) {
    if (currentPaletteDragHover) {
        currentPaletteDragHover = null;
        drawSite2DRackElevation(canvasTargetId);
    }
}

// Limpiar sombreado si se cancela o suelta el arrastre fuera
window.addEventListener('dragend', () => {
    if (currentPaletteDraggedTool || currentPaletteDragHover) {
        currentPaletteDraggedTool = null;
        currentPaletteDragHover = null;
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
    }
});

function onSite2DDrop(event, canvasTargetId) {
    event.preventDefault();
    const tipoTool = event.dataTransfer.getData('text/plain') || currentPaletteDraggedTool;
    currentPaletteDragHover = null;
    currentPaletteDraggedTool = null;
    if (!tipoTool) return;

    const canvas = document.getElementById(canvasTargetId);
    if (!canvas) return;

    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const dropX = (event.clientX - rect.left) * scaleX;
    const dropY = (event.clientY - rect.top) * scaleY;

    const isCompact = (canvasTargetId === 'siteCanvas2DConfig');
    const rW = isCompact ? 140 : 240;
    const rackHeight = isCompact ? 330 : 450;
    const startY = isCompact ? 42 : 48;
    const gap = isCompact ? 15 : 25;
    const marginX = isCompact ? 15 : 25;

    const rackKeys = getOrderedRackKeys();
    let targetRackKey = null;

    rackKeys.forEach((key, index) => {
        const rackStartX = marginX + index * (rW + gap);
        if (dropX >= rackStartX && dropX <= rackStartX + rW) {
            targetRackKey = key;
        }
    });

    if (!targetRackKey) targetRackKey = rackKeys[0];
    if (!targetRackKey) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('warning', 'Sin Racks', 'Primero agrega un Rack en el Plano 2D.');
        }
        return;
    }

    if (!siteRacksData[targetRackKey]) {
        siteRacksData[targetRackKey] = {
            title: targetRackKey.toUpperCase(),
            items: []
        };
    }
    if (!Array.isArray(siteRacksData[targetRackKey].items)) {
        siteRacksData[targetRackKey].items = [];
    }

    const slotH = rackHeight / 21;
    const uHeight = getComponentUHeight(tipoTool);
    let slotFromTop = Math.floor((dropY - startY) / slotH);
    slotFromTop = Math.max(0, Math.min(20 - uHeight, slotFromTop));
    const targetU = 20 - slotFromTop;

    // VALIDACIÓN: PERMITIR 2 EQUIPOS DE MEDIA ANCHURA EN EL LARGO DE LAS RENDIJAS
    if (!isSlotRangeFree(targetRackKey, targetU, uHeight, null, tipoTool)) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('danger', 'Rendija Ocupada', `La posición U${targetU} ya está ocupada. Selecciona una rendija libre.`);
        } else {
            alert(`La posición U${targetU} ya está ocupada.`);
        }
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
        return;
    }

    const configEquipos = {
        'servidor': { name: 'SERVIDOR', color: '#0a0e17', type: 'servidor' },
        'switch': { name: 'SWITCH 24P', color: '#d8dde6', type: 'switch' },
        'udm_pro': { name: 'UDM PRO', color: '#d8dde6', type: 'udm_pro' },
        'ont': { name: 'ONT INFINITUM', color: '#0a0f18', type: 'ont' },
        'fortinet': { name: 'FORTINET FIREWALL', color: '#ffffff', type: 'fortinet' },
        'nas_datto': { name: 'NAS DATTO', color: '#00b4d8', type: 'nas_datto' },
        'nas_buffalo': { name: 'NAS BUFFALO', color: '#334155', type: 'nas_buffalo' },
        'nas_qnap': { name: 'NAS QNAP', color: '#e2e8f0', type: 'nas_qnap' },
        'btac_box': { name: 'BTAC BOX', color: '#1f6ca5', type: 'btac_box' },
        'ups_1': { name: 'UPS 1 INDUSTRONIC', color: '#080c14', type: 'ups_1' },
        'ups_2': { name: 'UPS 2 APC 3000', color: '#1e222b', type: 'ups_2' },
        'servidor_2': { name: 'SERVIDOR 2 MODULAR', color: '#1f2937', type: 'servidor_2' },
        'enlace_mcm': { name: 'ENLACE MCM', color: '#8b5cf6', type: 'enlace_mcm' },
        'switch_principal': { name: 'SWITCH PRINCIPAL', color: '#e2e8f0', type: 'switch_principal' },
        'switch_secundario': { name: 'SWITCH SECUNDARIO', color: '#e2e8f0', type: 'switch_secundario' },
        'servidor_ad': { name: 'SERVIDOR ACTIVE DIRECTORY', color: '#090d16', type: 'servidor_ad' },
        'servidor_gds': { name: 'SERVIDOR GDS CORE', color: '#090d16', type: 'servidor_gds' },
        'servidor_anterior': { name: 'SERVIDOR ANTERIOR', color: '#0f172a', type: 'servidor_anterior' },
        'barra_pdu': { name: 'BARRA PDU 220V', color: '#1e293b', type: 'barra_pdu' },
        'barra_tierra': { name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' },
        'ups': { name: 'UPS RESPALDO 10KVA', color: '#0f172a', type: 'ups' },
        'extintor': { name: 'EXTINTOR SOLKAFLAM', color: '#dc2626', type: 'extintor' },
        'minisplit': { name: 'AIRE MINISPLIT INVERTER ❄️', color: '#38bdf8', type: 'minisplit' }
    };

    let invData = null;
    let invKey = null;
    let itemInfo = null;

    if (typeof tipoTool === 'string' && tipoTool.startsWith('inv:')) {
        invKey = tipoTool.replace('inv:', '');
        invData = (window.equiposInventarioMap && window.equiposInventarioMap[invKey]) ? window.equiposInventarioMap[invKey] : null;
        let detectedType = 'servidor';
        let detectedColor = '#0a0e17';
        if (invData) {
            const rawTipo = String(invData.tipo || '').toLowerCase();
            const rawLabel = String(invData.label || '').toLowerCase();
            if (rawTipo.includes('switch') || rawTipo.includes('sw') || rawLabel.includes('switch')) {
                detectedType = 'switch';
                detectedColor = '#d8dde6';
            } else if (rawTipo.includes('fortinet') || rawTipo.includes('firewall') || rawLabel.includes('fortinet') || rawLabel.includes('forti')) {
                detectedType = 'fortinet';
                detectedColor = '#ffffff';
            } else if (rawTipo.includes('datto') || rawLabel.includes('datto')) {
                detectedType = 'nas_datto';
                detectedColor = '#00b4d8';
            } else if (rawTipo.includes('buffalo') || rawLabel.includes('buffalo')) {
                detectedType = 'nas_buffalo';
                detectedColor = '#334155';
            } else if (rawTipo.includes('nas') || rawTipo.includes('qnap') || rawLabel.includes('nas')) {
                detectedType = 'nas_qnap';
                detectedColor = '#e2e8f0';
            } else if (rawTipo.includes('ups') || rawLabel.includes('ups') || rawLabel.includes('apc')) {
                detectedType = 'ups_2';
                detectedColor = '#1e222b';
            } else if (rawTipo.includes('router') || rawTipo.includes('ont') || rawLabel.includes('totalplay') || rawLabel.includes('infinitum')) {
                detectedType = 'router_totalplay';
                detectedColor = '#ffffff';
            } else if (rawTipo.includes('mcm') || rawLabel.includes('mcm')) {
                detectedType = 'enlace_mcm';
                detectedColor = '#8b5cf6';
            } else {
                detectedType = 'servidor';
                detectedColor = '#0a0e17';
            }
            itemInfo = {
                name: invData.label || 'EQUIPO INVENTARIO',
                color: detectedColor,
                type: detectedType
            };
        } else {
            itemInfo = { name: 'EQUIPO INVENTARIO', color: '#0a0e17', type: 'servidor' };
        }
    } else {
        itemInfo = configEquipos[tipoTool] || { name: 'COMPONENTE PERSONALIZADO', color: '#1e293b', type: tipoTool };
    }

    let slotPos = 'left';
    if (isHalfWidthItem(itemInfo.type, itemInfo.name)) {
        const existingPeer = siteRacksData[targetRackKey].items.find(it => 
            it && it.u === targetU && isHalfWidthItem(it.type, it.name)
        );
        if (existingPeer) {
            slotPos = (existingPeer.slotPos === 'left') ? 'right' : 'left';
        }
    }

    const newItem = {
        u: targetU,
        name: itemInfo.name,
        color: itemInfo.color,
        type: itemInfo.type,
        slotPos: slotPos
    };
    if (invKey && invData) {
        newItem.inventoryKey = invKey;
        newItem.inventoryData = invData;
    }

    siteRacksData[targetRackKey].items.push(newItem);

    selectedMountedRackItem = {
        rackKey: targetRackKey,
        itemIndex: siteRacksData[targetRackKey].items.length - 1,
        item: newItem,
        canvasId: canvasTargetId
    };

    if (typeof actualizarPanelVinculacionInventarioRack === 'function') {
        actualizarPanelVinculacionInventarioRack(selectedMountedRackItem);
    }

    if (typeof mostrarNotificacionToast === 'function') {
        mostrarNotificacionToast('success', 'Equipo Instalado', `"${itemInfo.name}" instalado en U${targetU}` + (invData ? ' con inventario vinculado' : ''));
    }

    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');
    sincronizarEscena3DDesde2D();
}

// ARRASTRE VISUAL INTERNO DENTRO DEL CANVAS 2D Y REORDENAMIENTO LIBRE
function initSite2DCanvasDragSystem() {
    ['siteCanvas2D', 'siteCanvas2DConfig'].forEach(canvasId => {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        canvas.addEventListener('mousedown', (e) => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            const clickX = (e.clientX - rect.left) * scaleX;
            const clickY = (e.clientY - rect.top) * scaleY;

            const isCompact = (canvasId === 'siteCanvas2DConfig');
            const rW = isCompact ? 140 : 240;
            const rackHeight = isCompact ? 330 : 450;
            const startY = isCompact ? 42 : 48;
            const gap = isCompact ? 15 : 25;
            const marginX = isCompact ? 15 : 25;
            const slotH = rackHeight / 21;

            const rackKeys = getOrderedRackKeys();

            for (let rIndex = 0; rIndex < rackKeys.length; rIndex++) {
                const rackKey = rackKeys[rIndex];
                const rackStartX = marginX + rIndex * (rW + gap);
                if (clickX >= rackStartX && clickX <= rackStartX + rW) {
                    const rackData = siteRacksData[rackKey];
                    if (!rackData || !Array.isArray(rackData.items)) continue;

                    // Recorrer de arriba a abajo (los últimos dibujados están al frente)
                    for (let itemIdx = rackData.items.length - 1; itemIdx >= 0; itemIdx--) {
                        const item = rackData.items[itemIdx];
                        if (!item) continue;
                        const bounds = getItemBounds2D(item, rackStartX, rW, startY, slotH, isCompact);

                        // Clic en el cuerpo del equipo: SELECCIONARLO y preparar arrastre
                        if (clickX >= bounds.x && clickX <= bounds.x + bounds.w &&
                            clickY >= bounds.y && clickY <= bounds.y + bounds.h) {
                            dragStartPos = { x: clickX, y: clickY };
                            hasDraggedSignificantDistance = false;
                            draggedMountedItem = {
                                sourceRackKey: rackKey,
                                itemIndex: itemIdx,
                                item: item,
                                initialU: item.u,
                                canvasId: canvasId
                            };
                            selectedMountedRackItem = {
                                rackKey: rackKey,
                                itemIndex: itemIdx,
                                item: item,
                                canvasId: canvasId
                            };
                            canvas.style.cursor = 'grab';
                            if (typeof actualizarPanelVinculacionInventarioRack === 'function') {
                                actualizarPanelVinculacionInventarioRack(selectedMountedRackItem);
                            }
                            drawSite2DRackElevation('siteCanvas2D');
                            drawSite2DRackElevation('siteCanvas2DConfig');
                            return;
                        }
                    }
                }
            }
            // Si hace clic en un espacio vacío, deseleccionar
            selectedMountedRackItem = null;
            if (typeof actualizarPanelVinculacionInventarioRack === 'function') {
                actualizarPanelVinculacionInventarioRack(null);
            }
            drawSite2DRackElevation('siteCanvas2D');
            drawSite2DRackElevation('siteCanvas2DConfig');
        });

        canvas.addEventListener('mouseleave', () => {
            const hud = document.getElementById('site3DHudTooltip');
            if (hud && typeof modoSiteActual !== 'undefined' && modoSiteActual === 'racks') {
                hud.style.display = 'none';
            }
        });

        canvas.addEventListener('mousemove', (e) => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            mouseCanvasPos = {
                x: (e.clientX - rect.left) * scaleX,
                y: (e.clientY - rect.top) * scaleY
            };

            const isCompact = (canvasId === 'siteCanvas2DConfig');
            const rW = isCompact ? 140 : 240;
            const rackHeight = isCompact ? 330 : 450;
            const startY = isCompact ? 42 : 48;
            const gap = isCompact ? 15 : 25;
            const marginX = isCompact ? 15 : 25;
            const slotH = rackHeight / 21;

            if (!draggedMountedItem) {
                // Hover dinámico de cursor sobre equipos
                let hoverOnItem = false;
                let hoveredItem = null;
                let hoveredRackKey = null;
                const rackKeys = getOrderedRackKeys();

                for (let rIndex = 0; rIndex < rackKeys.length; rIndex++) {
                    const rackKey = rackKeys[rIndex];
                    const rackStartX = marginX + rIndex * (rW + gap);
                    if (mouseCanvasPos.x >= rackStartX && mouseCanvasPos.x <= rackStartX + rW) {
                        const rackData = siteRacksData[rackKey];
                        if (rackData && Array.isArray(rackData.items)) {
                            for (let it of rackData.items) {
                                if (!it) continue;
                                const bounds = getItemBounds2D(it, rackStartX, rW, startY, slotH, isCompact);

                                if (mouseCanvasPos.x >= bounds.x && mouseCanvasPos.x <= bounds.x + bounds.w &&
                                    mouseCanvasPos.y >= bounds.y && mouseCanvasPos.y <= bounds.y + bounds.h) {
                                    hoverOnItem = true;
                                    hoveredItem = it;
                                    hoveredRackKey = rackKey;
                                    break;
                                }
                            }
                        }
                    }
                    if (hoverOnItem) break;
                }

                const hud = document.getElementById('site3DHudTooltip');
                if (hoverOnItem && hoveredItem) {
                    canvas.style.cursor = 'pointer';
                    canvas.title = 'Haz clic para seleccionar o arrastra para reubicar';
                    if (hud && typeof modoSiteActual !== 'undefined' && modoSiteActual === 'racks') {
                        mostrarTooltipComponenteSite(hud, hoveredItem, hoveredRackKey, e.clientX, e.clientY);
                    }
                } else {
                    canvas.style.cursor = 'default';
                    canvas.title = '';
                    if (hud && typeof modoSiteActual !== 'undefined' && modoSiteActual === 'racks') {
                        hud.style.display = 'none';
                    }
                }
                return;
            }

            const dist = Math.hypot(mouseCanvasPos.x - dragStartPos.x, mouseCanvasPos.y - dragStartPos.y);
            if (dist > 4) {
                hasDraggedSignificantDistance = true;
                canvas.style.cursor = 'grabbing';
            }

            const rackKeys = getOrderedRackKeys();
            let targetRackKey = null;
            let targetRackIndex = 0;

            rackKeys.forEach((key, index) => {
                const rackStartX = marginX + index * (rW + gap);
                if (mouseCanvasPos.x >= rackStartX && mouseCanvasPos.x <= rackStartX + rW) {
                    targetRackKey = key;
                    targetRackIndex = index;
                }
            });

            if (targetRackKey) {
                const uHeight = getComponentUHeight(draggedMountedItem.item.type, draggedMountedItem.item.name);
                let slotFromTop = Math.floor((mouseCanvasPos.y - startY) / slotH);
                slotFromTop = Math.max(0, Math.min(20 - uHeight, slotFromTop));
                const targetU = 20 - slotFromTop;
                const isFree = isSlotRangeFree(targetRackKey, targetU, uHeight, draggedMountedItem.item, draggedMountedItem.item.type, draggedMountedItem.item.name);

                draggedMountedItem.targetHover = {
                    rackKey: targetRackKey,
                    rackIndex: targetRackIndex,
                    slotFromTop: slotFromTop,
                    uHeight: uHeight,
                    targetU: targetU,
                    isFree: isFree
                };
            } else {
                draggedMountedItem.targetHover = null;
            }

            drawSite2DRackElevation(canvasId);
        });

        const handleMouseUp = (e) => {
            if (!draggedMountedItem) return;

            // Si solo fue un clic rápido sin arrastre, mantener seleccionado y salir
            if (!hasDraggedSignificantDistance) {
                draggedMountedItem = null;
                canvas.style.cursor = 'pointer';
                drawSite2DRackElevation('siteCanvas2D');
                drawSite2DRackElevation('siteCanvas2DConfig');
                return;
            }

            // 1. Verificar si se soltó sobre la papelera / botón eliminar del panel flotante
            const trashEl = document.getElementById('siteTrashDropZone');
            let droppedInTrash = false;
            if (trashEl) {
                const tRect = trashEl.getBoundingClientRect();
                if (e.clientX >= tRect.left && e.clientX <= tRect.right &&
                    e.clientY >= tRect.top && e.clientY <= tRect.bottom) {
                    droppedInTrash = true;
                }
            }

            if (droppedInTrash) {
                const removedName = draggedMountedItem.item.name || 'Componente';
                siteRacksData[draggedMountedItem.sourceRackKey].items.splice(draggedMountedItem.itemIndex, 1);
                selectedMountedRackItem = null;
                if (typeof mostrarNotificacionToast === 'function') {
                    mostrarNotificacionToast('info', 'Equipo Eliminado', `Se desinstaló "${removedName}" del rack.`);
                }
                draggedMountedItem = null;
                canvas.style.cursor = 'grab';
                drawSite2DRackElevation('siteCanvas2D');
                drawSite2DRackElevation('siteCanvas2DConfig');
                sincronizarEscena3DDesde2D();
                return;
            }

            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            const dropX = (e.clientX - rect.left) * scaleX;
            const dropY = (e.clientY - rect.top) * scaleY;

            const isCompact = (canvasId === 'siteCanvas2DConfig');
            const rW = isCompact ? 140 : 240;
            const rackHeight = isCompact ? 330 : 450;
            const startY = isCompact ? 42 : 48;
            const gap = isCompact ? 15 : 25;
            const marginX = isCompact ? 15 : 25;

            const rackKeys = getOrderedRackKeys();
            let targetRackKey = null;

            rackKeys.forEach((key, index) => {
                const rackStartX = marginX + index * (rW + gap);
                if (dropX >= rackStartX && dropX <= rackStartX + rW) {
                    targetRackKey = key;
                }
            });

            if (targetRackKey) {
                const slotH = rackHeight / 21;
                const uHeight = getComponentUHeight(draggedMountedItem.item.type, draggedMountedItem.item.name);
                let slotFromTop = Math.floor((dropY - startY) / slotH);
                slotFromTop = Math.max(0, Math.min(20 - uHeight, slotFromTop));
                const targetU = 20 - slotFromTop;

                const isFree = isSlotRangeFree(targetRackKey, targetU, uHeight, draggedMountedItem.item, draggedMountedItem.item.type, draggedMountedItem.item.name);
                if (isFree) {
                    // Mover limpiamente el equipo a la nueva posición U
                    siteRacksData[draggedMountedItem.sourceRackKey].items.splice(draggedMountedItem.itemIndex, 1);
                    draggedMountedItem.item.u = targetU;
                    if (isHalfWidthItem(draggedMountedItem.item.type, draggedMountedItem.item.name)) {
                        const existingPeer = siteRacksData[targetRackKey].items.find(it => 
                            it && it.u === targetU && isHalfWidthItem(it.type, it.name) && it !== draggedMountedItem.item
                        );
                        if (existingPeer) {
                            draggedMountedItem.item.slotPos = (existingPeer.slotPos === 'left') ? 'right' : 'left';
                        } else {
                            draggedMountedItem.item.slotPos = 'left';
                        }
                    }
                    siteRacksData[targetRackKey].items.push(draggedMountedItem.item);
                    selectedMountedRackItem = {
                        rackKey: targetRackKey,
                        itemIndex: siteRacksData[targetRackKey].items.length - 1,
                        item: draggedMountedItem.item,
                        canvasId: canvasId
                    };
                    if (typeof mostrarNotificacionToast === 'function') {
                        mostrarNotificacionToast('success', 'Equipo Reubicado', `"${draggedMountedItem.item.name || 'Equipo'}" reubicado en U${targetU}`);
                    }
                } else {
                    // Rendija ocupada: se preserva en su posición original sin duplicar ni romper nada
                    if (typeof mostrarNotificacionToast === 'function') {
                        mostrarNotificacionToast('warning', 'Rendija Ocupada', `No se pudo mover a U${targetU}: la rendija ya está ocupada.`);
                    }
                }
            }

            draggedMountedItem = null;
            canvas.style.cursor = 'grab';

            drawSite2DRackElevation('siteCanvas2D');
            drawSite2DRackElevation('siteCanvas2DConfig');
            sincronizarEscena3DDesde2D();
        };

        canvas.addEventListener('mouseup', handleMouseUp);
        canvas.addEventListener('mouseleave', () => {
            if (draggedMountedItem) {
                draggedMountedItem = null;
                canvas.style.cursor = 'grab';
                drawSite2DRackElevation('siteCanvas2D');
                drawSite2DRackElevation('siteCanvas2DConfig');
                sincronizarEscena3DDesde2D();
            }
        });

        // DESINSTALAR / RETIRAR COMPONENTE DEL RACK CON CLIC DERECHO
        canvas.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            const clickX = (e.clientX - rect.left) * scaleX;
            const clickY = (e.clientY - rect.top) * scaleY;

            const isCompact = (canvasId === 'siteCanvas2DConfig');
            const rW = isCompact ? 140 : 240;
            const rackHeight = isCompact ? 330 : 450;
            const startY = isCompact ? 42 : 48;
            const gap = isCompact ? 15 : 25;
            const marginX = isCompact ? 15 : 25;
            const slotH = rackHeight / 21;

            const rackKeys = getOrderedRackKeys();
            rackKeys.forEach((rackKey, rIndex) => {
                const rackStartX = marginX + rIndex * (rW + gap);
                if (clickX >= rackStartX && clickX <= rackStartX + rW) {
                    const rackData = siteRacksData[rackKey];
                    if (!rackData || !Array.isArray(rackData.items)) return;
                    for (let itemIdx = rackData.items.length - 1; itemIdx >= 0; itemIdx--) {
                        const item = rackData.items[itemIdx];
                        const bounds = getItemBounds2D(item, rackStartX, rW, startY, slotH, isCompact);

                        if (clickX >= bounds.x && clickX <= bounds.x + bounds.w &&
                            clickY >= bounds.y && clickY <= bounds.y + bounds.h) {
                            if (confirm(`¿Deseas desinstalar / retirar "${item.name || 'Componente'}" del ${rackData.title || rackKey.toUpperCase()}?`)) {
                                rackData.items.splice(itemIdx, 1);
                                if (typeof mostrarNotificacionToast === 'function') {
                                    mostrarNotificacionToast('info', 'Equipo Retirado', `Se retiró "${item.name || 'Componente'}" del rack.`);
                                }
                                drawSite2DRackElevation('siteCanvas2D');
                                drawSite2DRackElevation('siteCanvas2DConfig');
                                sincronizarEscena3DDesde2D();
                            }
                            return;
                        }
                    }
                }
            });
        });
    });
}

// DIBUJAR SOMBREADO / RESPLANDOR INDICADOR DE POSICIÓN AL ARRASTRAR COMPONENTE AL RACK
function drawRackSlotShadow(ctx, rackStartX, rW, startY, slotH, slotFromTop, uHeight, tipoTool, rackTitle, isCompact, isFree = true) {
    const innerX = rackStartX + 18;
    const innerW = rW - 36;
    const shadowY = startY + (slotFromTop * slotH);
    const shadowH = uHeight * slotH;

    ctx.save();

    // 1. Resplandor exterior / Glow (Cian si libre, Rojo si ocupado)
    ctx.shadowColor = isFree ? '#00f2fe' : '#ef4444';
    ctx.shadowBlur = 14;

    // 2. Fondo semitransparente sombreado
    const grad = ctx.createLinearGradient(innerX, shadowY, innerX + innerW, shadowY + shadowH);
    if (isFree) {
        grad.addColorStop(0, 'rgba(0, 242, 254, 0.25)');
        grad.addColorStop(0.5, 'rgba(56, 189, 248, 0.40)');
        grad.addColorStop(1, 'rgba(14, 165, 233, 0.25)');
    } else {
        grad.addColorStop(0, 'rgba(239, 68, 68, 0.35)');
        grad.addColorStop(0.5, 'rgba(220, 38, 38, 0.50)');
        grad.addColorStop(1, 'rgba(185, 28, 28, 0.35)');
    }
    ctx.fillStyle = grad;
    ctx.fillRect(innerX, shadowY + 1, innerW, shadowH - 2);

    // 3. Borde punteado de alta visibilidad
    ctx.shadowBlur = 0;
    ctx.strokeStyle = isFree ? '#00f2fe' : '#ef4444';
    ctx.lineWidth = 2;
    ctx.setLineDash([6, 4]);
    ctx.strokeRect(innerX, shadowY + 1, innerW, shadowH - 2);
    ctx.setLineDash([]);

    // 4. Brackets / Esquinas tácticas de anclaje
    const bLen = Math.min(8, (shadowH - 2) / 2);
    ctx.strokeStyle = isFree ? '#ffffff' : '#fca5a5';
    ctx.lineWidth = 2;

    // Sup Izq
    ctx.beginPath();
    ctx.moveTo(innerX, shadowY + 1 + bLen);
    ctx.lineTo(innerX, shadowY + 1);
    ctx.lineTo(innerX + bLen, shadowY + 1);
    ctx.stroke();

    // Sup Der
    ctx.beginPath();
    ctx.moveTo(innerX + innerW - bLen, shadowY + 1);
    ctx.lineTo(innerX + innerW, shadowY + 1);
    ctx.lineTo(innerX + innerW, shadowY + 1 + bLen);
    ctx.stroke();

    // Inf Izq
    ctx.beginPath();
    ctx.moveTo(innerX, shadowY + shadowH - 1 - bLen);
    ctx.lineTo(innerX, shadowY + shadowH - 1);
    ctx.lineTo(innerX + bLen, shadowY + shadowH - 1);
    ctx.stroke();

    // Inf Der
    ctx.beginPath();
    ctx.moveTo(innerX + innerW - bLen, shadowY + shadowH - 1);
    ctx.lineTo(innerX + innerW, shadowY + shadowH - 1);
    ctx.lineTo(innerX + innerW, shadowY + shadowH - 1 - bLen);
    ctx.stroke();

    // 5. Etiqueta informativa con número de U y nombre de componente
    const topU = 20 - slotFromTop;
    const bottomU = topU - uHeight + 1;
    const uLabel = (uHeight > 1) ? `U${bottomU} - U${topU}` : `U${topU}`;
    const cleanName = (tipoTool || 'EQUIPO').replace(/_/g, ' ').toUpperCase();
    const tagText = isFree 
        ? `+ SOLTAR AQUÍ: [ ${cleanName} | ${uHeight}U | ${uLabel} ]`
        : `⛔ RENDIJA OCUPADA: [ ${cleanName} | ${uHeight}U | ${uLabel} ]`;

    ctx.font = isCompact ? 'bold 7.5px monospace' : 'bold 9.5px monospace';
    const textW = ctx.measureText(tagText).width;
    const pillW = Math.min(innerW - 8, textW + 16);
    const pillH = isCompact ? 14 : 17;
    const pillX = innerX + (innerW / 2) - (pillW / 2);
    const pillY = shadowY + (shadowH / 2) - (pillH / 2);

    ctx.fillStyle = isFree ? 'rgba(6, 19, 37, 0.94)' : 'rgba(30, 10, 10, 0.94)';
    ctx.fillRect(pillX, pillY, pillW, pillH);
    ctx.strokeStyle = isFree ? '#38bdf8' : '#ef4444';
    ctx.lineWidth = 1;
    ctx.strokeRect(pillX, pillY, pillW, pillH);

    ctx.fillStyle = isFree ? '#38bdf8' : '#f87171';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(tagText, innerX + (innerW / 2), shadowY + (shadowH / 2));

    ctx.restore();
}


// RENDERING 2D ELEVACIÓN RACKS DINÁMICO
function drawSite2DRackElevation(targetCanvasId) {
    const canvasId = targetCanvasId || 'siteCanvas2D';
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const isCompact = (canvasId === 'siteCanvas2DConfig');

    // Asegurar que todo objeto tipo rack de siteFloorPlanObjects exista en siteRacksData
    if (siteFloorPlanObjects && Array.isArray(siteFloorPlanObjects)) {
        siteFloorPlanObjects.forEach(obj => {
            if (obj.type === 'rack' && !siteRacksData[obj.id]) {
                siteRacksData[obj.id] = {
                    title: obj.name,
                    items: [
                        { u: 1, name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' }
                    ]
                };
            }
        });
    }

    const rackKeys = getOrderedRackKeys();
    const palRacks = document.getElementById('sitePaletteRacks2D');

    if (rackKeys.length === 0) {
        if (palRacks) palRacks.style.display = 'none';
        canvas.width = isCompact ? 400 : 700;
        canvas.height = isCompact ? 360 : 460;
        const w = canvas.width;
        const h = canvas.height;

        ctx.clearRect(0, 0, w, h);
        ctx.fillStyle = '#061325';
        ctx.fillRect(0, 0, w, h);

        ctx.fillStyle = '#1e293b';
        ctx.fillRect(10, startY - 26, w - 20, 10);
        ctx.fillStyle = '#2563eb';
        ctx.fillRect(20, startY - 24, w - 40, 3);
        ctx.fillStyle = '#eab308';
        ctx.fillRect(25, startY - 20, w - 50, 3);

        const boxW = Math.min(480, w - 60);
        const boxH = isCompact ? 160 : 200;
        const boxX = (w - boxW) / 2;
        const boxY = startY + (isCompact ? 30 : 50);

        ctx.fillStyle = 'rgba(15, 23, 42, 0.75)';
        ctx.fillRect(boxX, boxY, boxW, boxH);
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.3)';
        ctx.lineWidth = 1.5;
        ctx.setLineDash([6, 6]);
        ctx.strokeRect(boxX, boxY, boxW, boxH);
        ctx.setLineDash([]);

        ctx.fillStyle = '#38bdf8';
        ctx.font = isCompact ? 'bold 14px sans-serif' : 'bold 18px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('⚡ NO HAY BASTIDORES EN EL SITE', w / 2, boxY + (isCompact ? 50 : 65));

        ctx.fillStyle = '#94a3b8';
        ctx.font = isCompact ? '10px sans-serif' : '13px sans-serif';
        ctx.fillText('Has eliminado todos los racks del Plano 2D.', w / 2, boxY + (isCompact ? 80 : 100));

        ctx.fillStyle = '#00f2fe';
        ctx.font = isCompact ? 'bold 9.5px sans-serif' : 'bold 12px sans-serif';
        ctx.fillText('Para montar equipos, agrega un Rack 42U desde la pestaña "Vista 2D Plano".', w / 2, boxY + (isCompact ? 110 : 135));
        return;
    }

    if (palRacks && !isCompact) palRacks.style.display = 'block';

    const totalRacks = rackKeys.length;
    const rW = isCompact ? 140 : 240;
    const rackHeight = isCompact ? 330 : 450;
    const startY = isCompact ? 42 : 48;
    const gap = isCompact ? 15 : 25;
    const marginX = isCompact ? 15 : 25;

    canvas.width = Math.max(580, marginX * 2 + (totalRacks * (rW + gap)) + 40);
    canvas.height = isCompact ? 440 : 540;

    const w = canvas.width;
    const h = canvas.height;

    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = '#061325';
    ctx.fillRect(0, 0, w, h);

    ctx.fillStyle = '#1e293b';
    ctx.fillRect(10, startY - 26, w - 20, 10);
    ctx.fillStyle = '#2563eb';
    ctx.fillRect(20, startY - 24, w - 40, 3);
    ctx.fillStyle = '#eab308';
    ctx.fillRect(25, startY - 20, w - 50, 3);

    rackKeys.forEach((rackKey, index) => {
        const rackData = siteRacksData[rackKey];
        if (!rackData || typeof rackData !== 'object' || !rackData.items) return;
        const startX = marginX + index * (rW + gap);

        ctx.fillStyle = '#0f172a';
        ctx.fillRect(startX, startY, rW, rackHeight);
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 2.5;
        ctx.strokeRect(startX, startY, rW, rackHeight);

        const floorObj = siteFloorPlanObjects ? siteFloorPlanObjects.find(o => o.id === rackKey) : null;
        const rackDisplayTitle = (floorObj && floorObj.name) ? floorObj.name : (rackData.title || rackKey.toUpperCase());

        ctx.fillStyle = '#ffffff';
        ctx.font = isCompact ? 'bold 10px font-monospace' : 'bold 13px font-monospace';
        ctx.textAlign = 'center';
        ctx.fillText(rackDisplayTitle, startX + (rW / 2), startY - 12);

        const slotH = rackHeight / 21;
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 21; i++) {
            const y = startY + (i * slotH);
            ctx.beginPath();
            ctx.moveTo(startX, y);
            ctx.lineTo(startX + rW, y);
            ctx.stroke();

            if (!isCompact || i % 3 === 0) {
                ctx.fillStyle = '#64748b';
                ctx.font = isCompact ? '7px monospace' : '9px monospace';
                ctx.textAlign = 'left';
                ctx.fillText(`U${21 - i}`, startX + 4, y - 3);
            }
        }

        rackData.items.forEach(item => {
            const itemTypeStr = (item && item.type) ? String(item.type) : '';
            const itemNameStr = (item && item.name) ? String(item.name) : 'EQUIPO';
            const bounds = getItemBounds2D(item, startX, rW, startY, slotH, isCompact);
            const eqY = bounds.y;
            const uHeight = getComponentUHeight(itemTypeStr, itemNameStr);
            const eqH = bounds.h;
            const innerW = bounds.w;
            const innerX = bounds.x;

            // Si es un equipo sobre bandeja de rack (media anchura 3U o 2U), dibujar la bandeja metálica base
            if (isHalfWidthItem(itemTypeStr, itemNameStr)) {
                const fullInnerX = startX + 18;
                const fullInnerW = rW - 36;
                const shelfH = 4;
                const shelfY = eqY + eqH - shelfH;
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(fullInnerX, shelfY, fullInnerW, shelfH);
                ctx.strokeStyle = '#475569';
                ctx.lineWidth = 1;
                ctx.strokeRect(fullInnerX, shelfY, fullInnerW, shelfH);
            }

            if (itemTypeStr === 'fortinet') {
                ctx.fillStyle = '#f8fafc';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.fillStyle = '#dc2626';
                ctx.fillRect(innerX, eqY + 1, innerW, 4);

                ctx.fillStyle = '#0f172a';
                ctx.fillRect(innerX + innerW - 55, eqY + (eqH / 2) - 4, 45, 8);

                ctx.fillStyle = '#0f172a';
                ctx.font = isCompact ? 'bold 7px sans-serif' : 'bold 9px sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText('FORTINET FIREWALL', innerX + 8, eqY + (eqH / 2) + 3);
            } else if (itemTypeStr === 'router_totalplay') {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#e2e8f0';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                ctx.fillStyle = '#dc2626';
                ctx.fillRect(innerX, eqY + 1, 8, eqH);

                ctx.fillStyle = '#0f172a';
                ctx.font = isCompact ? 'bold 6px sans-serif' : 'bold 8px sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText('FiberHome Totalplay ONT 📶', innerX + 12, eqY + (eqH / 2) + 3);

                ctx.fillStyle = '#22c55e';
                ctx.beginPath(); ctx.arc(innerX + innerW - 18, eqY + (eqH / 2), 2.5, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.arc(innerX + innerW - 8, eqY + (eqH / 2), 2.5, 0, Math.PI * 2); ctx.fill();
            } else if (itemTypeStr === 'enlace_mcm') {
                ctx.fillStyle = '#8b5cf6';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 7px sans-serif' : 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('ENLACE MCM', innerX + (innerW / 2), eqY + (eqH / 2) + 3);
            } else if (itemTypeStr === 'gateway_unifi' || itemTypeStr.includes('switch')) {
                // SWITCH 1U - UBIQUITI UNIFI SWITCH 24 PRO (DISEÑO EXACTO DE IMAGEN 2)
                // 1. Chasis aluminio plateado mate con bordes anodizados
                ctx.fillStyle = '#d8dde6';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#94a3b8';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                // Ranura de ventilación horizontal superior oscura
                ctx.fillStyle = '#64748b';
                ctx.fillRect(innerX + 18, eqY + 2.5, innerW - 36, 1);

                // Orejas metálicas laterales de montaje 1U con tornillos ovalados
                const earW = isCompact ? 8 : 12;
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX, eqY + 1, earW, eqH);
                ctx.fillRect(innerX + innerW - earW, eqY + 1, earW, eqH);
                ctx.fillStyle = '#64748b';
                ctx.fillRect(innerX + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);
                ctx.fillRect(innerX + innerW - earW + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);

                // 2. PANTALLA TÁCTIL UNIFI LCM (Square Touchscreen con logo azul UniFi)
                const screenX = innerX + earW + (isCompact ? 4 : 8);
                const screenS = isCompact ? 12 : 16;
                const screenY = eqY + (eqH / 2) - (screenS / 2);

                // Marco y pantalla táctil oscura
                ctx.fillStyle = '#090d16';
                ctx.fillRect(screenX, screenY, screenS, screenS);
                ctx.strokeStyle = '#334155';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(screenX, screenY, screenS, screenS);

                // Anillo y logo azul UniFi brillante (#00aaff)
                ctx.strokeStyle = '#00aaff';
                ctx.lineWidth = 1.2;
                ctx.beginPath();
                ctx.arc(screenX + (screenS / 2), screenY + (screenS / 2), screenS * 0.32, 0, Math.PI * 2);
                ctx.stroke();

                ctx.fillStyle = '#00f2fe';
                ctx.beginPath();
                ctx.arc(screenX + (screenS / 2), screenY + (screenS / 2), 1.2, 0, Math.PI * 2);
                ctx.fill();

                // 3. PUERTOS ETHERNET RJ45 (2 Filas de 12 Puertos = 24 Puertos, divididos en 2 bloques)
                const portBlockStartX = screenX + screenS + (isCompact ? 8 : 16);
                const totalPortsW = (innerW - (portBlockStartX - innerX) - (isCompact ? 22 : 36));
                const singlePortW = Math.max(3.5, (totalPortsW - 8) / 13);
                const portH = (eqH - 7) / 2;

                for (let row = 0; row < 2; row++) {
                    const py = eqY + 3 + (row * (portH + 1));
                    for (let col = 0; col < 12; col++) {
                        // Separador entre bloque 1 (puertos 1-12) y bloque 2 (puertos 13-24)
                        const blockGap = (col >= 6) ? 4 : 0;
                        const px = portBlockStartX + (col * (singlePortW + 1.2)) + blockGap;

                        // Cavidad negra RJ45
                        ctx.fillStyle = '#090d16';
                        ctx.fillRect(px, py, singlePortW, portH);
                        ctx.strokeStyle = '#475569';
                        ctx.lineWidth = 0.5;
                        ctx.strokeRect(px, py, singlePortW, portH);

                        // Pines dorados / pestaña RJ45
                        ctx.fillStyle = '#d97706';
                        ctx.fillRect(px + 1, (row === 0 ? py + portH - 1.5 : py + 0.5), Math.max(1, singlePortW - 2), 1);

                        // LED de enlace/actividad verde (#22c55e)
                        if ((col + row) % 3 !== 2) {
                            ctx.fillStyle = '#22c55e';
                            ctx.fillRect(px + (singlePortW / 2) - 0.7, (row === 0 ? py + 0.6 : py + portH - 1.6), 1.4, 1);
                        }
                    }
                }

                // 4. PUERTOS SFP+ 10G ÓPTICOS DERECHOS (2 Jaulas de fibra apiladas)
                const sfpX = innerX + innerW - earW - (isCompact ? 14 : 20);
                const sfpW = isCompact ? 9 : 13;
                const sfpRowH = (eqH - 6) / 2;

                for (let r = 0; r < 2; r++) {
                    const sy = eqY + 3 + (r * (sfpRowH + 0.8));
                    ctx.fillStyle = '#1e293b';
                    ctx.fillRect(sfpX, sy, sfpW, sfpRowH);
                    ctx.strokeStyle = '#64748b';
                    ctx.lineWidth = 0.6;
                    ctx.strokeRect(sfpX, sy, sfpW, sfpRowH);

                    // Seguro metálico / pestillo plateado del módulo SFP
                    ctx.fillStyle = '#cbd5e1';
                    ctx.fillRect(sfpX + 2, sy + (sfpRowH / 2) - 1, sfpW - 4, 2);

                    // LED link SFP verde
                    ctx.fillStyle = '#22c55e';
                    ctx.beginPath();
                    ctx.arc(sfpX + sfpW - 2, sy + (sfpRowH / 2), 0.9, 0, Math.PI * 2);
                    ctx.fill();
                }

                // Identificador sutil
                ctx.fillStyle = '#475569';
                ctx.font = isCompact ? 'bold 5px sans-serif' : 'bold 7px sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText('SW 24P', screenX, eqY + eqH - 2);
            } else if (itemTypeStr === 'udm_pro') {
                // GATEWAY CONSOLE 1U - UBIQUITI UNIFI DREAM MACHINE PRO (UDM PRO - IMAGEN EXACTA)
                // 1. Chasis aluminio plateado mate con bordes anodizados
                ctx.fillStyle = '#d8dde6';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#94a3b8';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                // Ranuras horizontales superiores de ventilación
                ctx.fillStyle = '#64748b';
                ctx.fillRect(innerX + 18, eqY + 2.5, innerW - 36, 1);

                // Orejas metálicas laterales de montaje 1U
                const earW = isCompact ? 8 : 12;
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX, eqY + 1, earW, eqH);
                ctx.fillRect(innerX + innerW - earW, eqY + 1, earW, eqH);
                ctx.fillStyle = '#64748b';
                ctx.fillRect(innerX + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);
                ctx.fillRect(innerX + innerW - earW + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);

                // 2. PANTALLA TÁCTIL UNIFI LCM (Square Touchscreen con logo azul UniFi)
                const screenX = innerX + earW + (isCompact ? 4 : 8);
                const screenS = isCompact ? 12 : 16;
                const screenY = eqY + (eqH / 2) - (screenS / 2);

                ctx.fillStyle = '#090d16';
                ctx.fillRect(screenX, screenY, screenS, screenS);
                ctx.strokeStyle = '#334155';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(screenX, screenY, screenS, screenS);

                // Anillo y logo azul UniFi brillante (#00aaff)
                ctx.strokeStyle = '#00aaff';
                ctx.lineWidth = 1.2;
                ctx.beginPath();
                ctx.arc(screenX + (screenS / 2), screenY + (screenS / 2), screenS * 0.32, 0, Math.PI * 2);
                ctx.stroke();

                ctx.fillStyle = '#00f2fe';
                ctx.beginPath();
                ctx.arc(screenX + (screenS / 2), screenY + (screenS / 2), 1.2, 0, Math.PI * 2);
                ctx.fill();

                // 3. BAHÍAS DUALES DE DISCO DURO 3.5" (HDD BAY 1 y BAY 2)
                const hddStartX = screenX + screenS + (isCompact ? 6 : 10);
                const hddAreaW = isCompact ? 70 : 120;
                const singleHddW = (hddAreaW - 4) / 2;
                const hddH = eqH - 6;

                for (let b = 0; b < 2; b++) {
                    const hx = hddStartX + (b * (singleHddW + 3));
                    // Carátula plateada de la bandeja
                    ctx.fillStyle = '#cbd5e1';
                    ctx.fillRect(hx, eqY + 3, singleHddW, hddH);
                    ctx.strokeStyle = '#94a3b8';
                    ctx.lineWidth = 0.8;
                    ctx.strokeRect(hx, eqY + 3, singleHddW, hddH);

                    // Mecanismo de extracción / jaladera lateral izquierda
                    ctx.fillStyle = '#475569';
                    ctx.fillRect(hx + 1, eqY + 4, 1.5, hddH - 2);

                    // LED indicador de disco blanco
                    ctx.fillStyle = '#f8fafc';
                    ctx.beginPath();
                    ctx.arc(hx + singleHddW - 3, eqY + (eqH / 2), 1, 0, Math.PI * 2);
                    ctx.fill();

                    // Etiqueta 1 y 2
                    ctx.fillStyle = '#64748b';
                    ctx.font = '5px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(`${b + 1}`, hx + (singleHddW / 2), eqY + hddH + 1);
                }

                // 4. SWITCH INTEGRADO DE 8 PUERTOS RJ45 GBE (2 Filas de 4 Puertos)
                const rj45StartX = hddStartX + hddAreaW + (isCompact ? 6 : 12);
                const rjW = isCompact ? 5 : 7.5;
                const rjH = (eqH - 7) / 2;

                for (let row = 0; row < 2; row++) {
                    const py = eqY + 3 + (row * (rjH + 1));
                    for (let col = 0; col < 4; col++) {
                        const px = rj45StartX + (col * (rjW + 1.2));

                        // Cavidad negra RJ45
                        ctx.fillStyle = '#090d16';
                        ctx.fillRect(px, py, rjW, rjH);
                        ctx.strokeStyle = '#475569';
                        ctx.lineWidth = 0.5;
                        ctx.strokeRect(px, py, rjW, rjH);

                        // Contacto dorado
                        ctx.fillStyle = '#d97706';
                        ctx.fillRect(px + 1, (row === 0 ? py + rjH - 1.2 : py + 0.4), Math.max(1, rjW - 2), 0.8);

                        // LED verde link
                        ctx.fillStyle = '#22c55e';
                        ctx.fillRect(px + (rjW / 2) - 0.6, (row === 0 ? py + 0.5 : py + rjH - 1.3), 1.2, 0.8);
                    }
                }

                // 5. PUERTO WAN RJ45 2.5G
                const wanX = rj45StartX + (4 * (rjW + 1.2)) + (isCompact ? 4 : 8);
                const wanH = isCompact ? 8 : 11;
                const wanY = eqY + (eqH / 2) - (wanH / 2);

                ctx.fillStyle = '#090d16';
                ctx.fillRect(wanX, wanY, rjW + 1, wanH);
                ctx.strokeStyle = '#475569';
                ctx.lineWidth = 0.5;
                ctx.strokeRect(wanX, wanY, rjW + 1, wanH);

                ctx.fillStyle = '#22c55e';
                ctx.fillRect(wanX + (rjW / 2) - 0.6, wanY + wanH - 1.5, 1.2, 0.8);

                // 6. PUERTOS SFP+ 10G ÓPTICOS DERECHOS (LAN y WAN apilados)
                const sfpX = innerX + innerW - earW - (isCompact ? 14 : 20);
                const sfpW = isCompact ? 9 : 13;
                const sfpRowH = (eqH - 6) / 2;

                for (let r = 0; r < 2; r++) {
                    const sy = eqY + 3 + (r * (sfpRowH + 0.8));
                    ctx.fillStyle = '#1e293b';
                    ctx.fillRect(sfpX, sy, sfpW, sfpRowH);
                    ctx.strokeStyle = '#64748b';
                    ctx.lineWidth = 0.6;
                    ctx.strokeRect(sfpX, sy, sfpW, sfpRowH);

                    // Pestillo metálico plateado
                    ctx.fillStyle = '#cbd5e1';
                    ctx.fillRect(sfpX + 2, sy + (sfpRowH / 2) - 1, sfpW - 4, 2);

                    // LED link verde
                    ctx.fillStyle = '#22c55e';
                    ctx.beginPath();
                    ctx.arc(sfpX + sfpW - 2, sy + (sfpRowH / 2), 0.9, 0, Math.PI * 2);
                    ctx.fill();
                }

                // Texto identificador UDM PRO
                ctx.fillStyle = '#475569';
                ctx.font = isCompact ? 'bold 5px sans-serif' : 'bold 7px sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText('UDM PRO', screenX, eqY + eqH - 2);
            } else if (itemTypeStr === 'ont' || itemTypeStr.includes('ont')) {
                // MÓDEM / ONT ÓPTICA - ALCATEL-LUCENT INFINITUM TELMEX (DISEÑO EXACTO SEGÚN IMAGEN)
                // 1. Chasis negro obsidiana / brillo acrílico con marco perimetral plateado
                ctx.fillStyle = '#080d1a';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#94a3b8';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                // Orejas metálicas laterales de montaje en rack
                const earW = isCompact ? 7 : 10;
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(innerX, eqY + 1, earW, eqH);
                ctx.fillRect(innerX + innerW - earW, eqY + 1, earW, eqH);
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);
                ctx.fillRect(innerX + innerW - earW + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);

                // Marco interior curvado del panel frontal de la ONT
                const ontX = innerX + earW + 2;
                const ontW = innerW - (earW * 2) - 4;
                ctx.fillStyle = '#0c101d';
                ctx.fillRect(ontX, eqY + 1.5, ontW, eqH - 1);
                ctx.strokeStyle = '#334155';
                ctx.lineWidth = 0.6;
                ctx.strokeRect(ontX, eqY + 1.5, ontW, eqH - 1);

                // Pestaña roja protectora superior derecha
                ctx.fillStyle = '#dc2626';
                ctx.fillRect(ontX + ontW - 12, eqY + 1, 6, 2);

                // 2. Fila superior de micro-LEDs verdes de estado
                const numLeds = isCompact ? 10 : 16;
                const ledStartX = ontX + (isCompact ? 8 : 16);
                const ledSpacing = (ontW - (isCompact ? 16 : 32)) / (numLeds - 1);
                for (let l = 0; l < numLeds; l++) {
                    const lx = ledStartX + (l * ledSpacing);
                    ctx.fillStyle = '#22c55e';
                    ctx.fillRect(lx, eqY + 2.5, 1.2, 2.5);
                }

                // 3. ETIQUETA / STICKER CENTRAL AMARILLO Y AZUL INFINITUM TELMEX
                const stickerW = isCompact ? Math.min(ontW * 0.46, 75) : Math.min(ontW * 0.44, 110);
                const stickerH = eqH - 7;
                const stickerX = ontX + (ontW / 2) - (stickerW / 2);
                const stickerY = eqY + 5;

                if (stickerH > 5) {
                    // Encabezado amarillo de advertencia Telmex
                    const headerH = Math.max(3, stickerH * 0.28);
                    ctx.fillStyle = '#facc15';
                    ctx.fillRect(stickerX, stickerY, stickerW, headerH);

                    // Ícono de advertencia / texto
                    ctx.fillStyle = '#000000';
                    ctx.font = isCompact ? 'bold 3.5px sans-serif' : 'bold 4.5px sans-serif';
                    ctx.textAlign = 'center';
                    if (!isCompact) {
                        ctx.fillText('▲ NUNCA APAGUES TU MÓDEM', stickerX + (stickerW / 2), stickerY + headerH - 1.2);
                    } else {
                        ctx.fillText('▲ TELMEX', stickerX + (stickerW / 2), stickerY + headerH - 0.8);
                    }

                    // Cuerpo azul Infinitum
                    const bodyH = stickerH - headerH;
                    ctx.fillStyle = '#0284c7';
                    ctx.fillRect(stickerX, stickerY + headerH, stickerW, bodyH);

                    // Campos blancos de SSID y Contraseña WPA
                    const boxMarginX = 3;
                    const boxW = stickerW - (boxMarginX * 2);
                    const boxH = Math.max(1.8, (bodyH - 5) / 2);

                    // Campo 1: SSID
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(stickerX + boxMarginX, stickerY + headerH + 1.5, boxW, boxH);
                    ctx.fillStyle = '#0f172a';
                    ctx.font = isCompact ? 'bold 3px sans-serif' : 'bold 4px sans-serif';
                    ctx.textAlign = 'left';
                    ctx.fillText('INFINITUMB661_2.4', stickerX + boxMarginX + 1.5, stickerY + headerH + 1.5 + boxH - 0.6);

                    // Campo 2: WPA Key
                    if (bodyH > 8) {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(stickerX + boxMarginX, stickerY + headerH + 2 + boxH, boxW, boxH);
                        ctx.fillStyle = '#0f172a';
                        ctx.fillText('WPA: 1081594561', stickerX + boxMarginX + 1.5, stickerY + headerH + 2 + boxH * 2 - 0.6);
                    }
                }

                // 4. REJILLA INFERIOR DE VENTILACIÓN
                const grillY = eqY + eqH - 3.5;
                ctx.fillStyle = '#030712';
                ctx.fillRect(ontX + 10, grillY, ontW - 20, 1.2);

                // 5. LOGOS INFERIORES: infinitum. (izq), Alcatel-Lucent (centro), TELMEX (der)
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 4.5px sans-serif' : 'bold 6px sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText('infinitum.', ontX + 6, eqY + eqH - 2);

                if (!isCompact) {
                    ctx.fillStyle = '#cbd5e1';
                    ctx.font = '5px sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText('Alcatel-Lucent', ontX + (ontW / 2), eqY + eqH - 2);
                }

                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 4.5px sans-serif' : 'bold 6px sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText('TELMEX', ontX + ontW - 6, eqY + eqH - 2);
            } else if (itemTypeStr.includes('datto')) {
                // NAS DATTO SIRIS BCDR 2U (IMAGEN 1: MALLA HEXAGONAL CIAN CON LOGO DATTO Y CERRADURA)
                // 1. Chasis frontal azul marino oscuro / obsidiana
                ctx.fillStyle = '#0a101d';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                // Orejas metálicas laterales de montaje en rack
                const earW = isCompact ? 7 : 11;
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(innerX, eqY + 1, earW, eqH);
                ctx.fillRect(innerX + innerW - earW, eqY + 1, earW, eqH);
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);
                ctx.fillRect(innerX + innerW - earW + 2, eqY + (eqH / 2) - 1.5, earW - 4, 3);

                // 2. Sección izquierda con cerradura y hendidura angular
                const leftCtrlW = isCompact ? 22 : 32;
                const leftCtrlX = innerX + earW;
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(leftCtrlX, eqY + 1, leftCtrlW, eqH);

                // Hendidura angular / chevron en el extremo izquierdo
                ctx.fillStyle = '#00b4d8';
                ctx.beginPath();
                ctx.moveTo(leftCtrlX + 3, eqY + (eqH / 2) - 3);
                ctx.lineTo(leftCtrlX + 5.5, eqY + (eqH / 2));
                ctx.lineTo(leftCtrlX + 3, eqY + (eqH / 2) + 3);
                ctx.strokeStyle = '#00b4d8';
                ctx.lineWidth = 1.0;
                ctx.stroke();

                // Mecanismo de cerradura circular (Barrel Keylock)
                const lockX = leftCtrlX + (leftCtrlW / 2) + 2;
                const lockY = eqY + (eqH / 2);
                const lockR = isCompact ? 3.5 : 4.5;

                ctx.fillStyle = '#1e293b';
                ctx.beginPath(); ctx.arc(lockX, lockY, lockR, 0, Math.PI * 2); ctx.fill();
                ctx.strokeStyle = '#38bdf8';
                ctx.lineWidth = 0.8;
                ctx.stroke();

                ctx.fillStyle = '#94a3b8';
                ctx.beginPath(); ctx.arc(lockX, lockY, lockR * 0.55, 0, Math.PI * 2); ctx.fill();

                // Muescas de cerradura doradas
                ctx.fillStyle = '#d97706';
                for (let k = 0; k < 4; k++) {
                    const ang = (k * Math.PI / 2);
                    ctx.fillRect(lockX + Math.cos(ang) * (lockR * 0.35) - 0.5, lockY + Math.sin(ang) * (lockR * 0.35) - 0.5, 1.0, 1.0);
                }

                // Micro ícono de candado
                ctx.fillStyle = '#94a3b8';
                ctx.font = '4.5px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('🔒', lockX - lockR - 2.5, lockY + 1.8);

                // 3. Área de rejilla hexagonal cian (Interlocking Honeycomb Mesh)
                const meshStartX = leftCtrlX + leftCtrlW;
                const meshW = innerW - earW * 2 - leftCtrlW;

                ctx.fillStyle = '#060a12';
                ctx.fillRect(meshStartX, eqY + 1.5, meshW, Math.max(2, eqH - 2));

                // Fondo de micro-perforaciones
                ctx.fillStyle = '#0f172a';
                const dotStep = isCompact ? 5 : 7;
                for (let dx = meshStartX + 2; dx < meshStartX + meshW - 2; dx += dotStep) {
                    for (let dy = eqY + 3; dy < eqY + eqH - 2; dy += dotStep) {
                        ctx.fillRect(dx, dy, 1.0, 1.0);
                    }
                }

                // Trazado de líneas hexagonales cian enlazadas (Interlocking Hex Pattern)
                ctx.strokeStyle = '#00b4d8';
                ctx.lineWidth = isCompact ? 0.9 : 1.2;
                ctx.beginPath();
                const hexW = isCompact ? 9 : 12;
                const hexH = Math.min(eqH * 0.65, 11);
                const hexYCenter = eqY + (eqH / 2);

                for (let hx = meshStartX + 4; hx < meshStartX + meshW - 4; hx += hexW) {
                    ctx.moveTo(hx, hexYCenter - (hexH / 2));
                    ctx.lineTo(hx + (hexW * 0.3), hexYCenter - (hexH / 2));
                    ctx.lineTo(hx + (hexW * 0.5), hexYCenter);
                    ctx.lineTo(hx + (hexW * 0.3), hexYCenter + (hexH / 2));
                    ctx.lineTo(hx, hexYCenter + (hexH / 2));
                    ctx.lineTo(hx - (hexW * 0.2), hexYCenter);
                    ctx.closePath();

                    // Vínculo horizontal continuo
                    ctx.moveTo(hx + (hexW * 0.5), hexYCenter);
                    ctx.lineTo(hx + hexW, hexYCenter);
                }
                ctx.stroke();

                // 4. Placa / Emblema Trapezoidal "datto"
                const badgeW = isCompact ? 32 : 46;
                const badgeH = Math.min(isCompact ? 8.5 : 11.5, eqH * 0.65);
                const badgeX = meshStartX + (isCompact ? 12 : 20);
                const badgeY = eqY + (eqH / 2) - (badgeH / 2);

                // Silueta trapezoidal con corte angular
                ctx.fillStyle = '#080d1a';
                ctx.beginPath();
                ctx.moveTo(badgeX + 4, badgeY);
                ctx.lineTo(badgeX + badgeW, badgeY);
                ctx.lineTo(badgeX + badgeW - 4, badgeY + badgeH);
                ctx.lineTo(badgeX, badgeY + badgeH);
                ctx.closePath();
                ctx.fill();

                ctx.strokeStyle = '#00b4d8';
                ctx.lineWidth = 1.1;
                ctx.stroke();

                ctx.fillStyle = '#00f2fe';
                ctx.font = isCompact ? 'bold 6.5px sans-serif' : 'bold 8.5px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('datto', badgeX + (badgeW / 2), badgeY + (badgeH / 2));
                ctx.textBaseline = 'alphabetic';

                // LEDs de estado en extremo derecho
                ctx.fillStyle = '#00f2fe';
                ctx.beginPath(); ctx.arc(innerX + innerW - earW - 5, eqY + (eqH / 2), 1.3, 0, Math.PI * 2); ctx.fill();

            } else if (itemTypeStr.includes('buffalo')) {
                // NAS BUFFALO TERA STATION TOWER (IMAGEN 2: TORRE METÁLICA CON PANTALLA LCD, LÍNEA ROJA Y PUERTA HEXAGONAL)
                // 1. Bandeja / Estante metálico 19" en el fondo
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#334155';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                // Base de estante soporte de acero
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(innerX, eqY + eqH - 3, innerW, 3);
                ctx.fillStyle = '#64748b';
                ctx.fillRect(innerX + 4, eqY + eqH - 2, innerW - 8, 1);

                // 2. Chasis Torre Buffalo TeraStation centrado en el estante
                const towerW = isCompact ? innerW * 0.72 : innerW * 0.58;
                const towerX = innerX + (innerW - towerW) / 2;
                const towerH = eqH - 3;
                const towerY = eqY + 1;

                // Carcasa externa gris grafito / gunmetal metálico
                ctx.fillStyle = '#334155';
                ctx.fillRect(towerX, towerY, towerW, towerH);
                ctx.strokeStyle = '#64748b';
                ctx.lineWidth = 1.2;
                ctx.strokeRect(towerX, towerY, towerW, towerH);

                // Columnas laterales redondeadas de la torre
                const colW = isCompact ? 10 : 16;

                // Columna izquierda con ranuras de ventilación superiores
                ctx.fillStyle = '#273549';
                ctx.fillRect(towerX, towerY, colW, towerH);
                ctx.fillStyle = '#0f172a';
                for (let v = 0; v < 3; v++) {
                    ctx.fillRect(towerX + 3, towerY + 6 + (v * 4), colW - 6, 1.8);
                }

                // Columna derecha con cerradura circular con llave
                ctx.fillStyle = '#273549';
                ctx.fillRect(towerX + towerW - colW, towerY, colW, towerH);
                const buffLockX = towerX + towerW - (colW / 2);
                const buffLockY = towerY + (towerH * 0.65);
                ctx.fillStyle = '#0f172a';
                ctx.beginPath(); ctx.arc(buffLockX, buffLockY, isCompact ? 3.5 : 5, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = '#94a3b8';
                ctx.beginPath(); ctx.arc(buffLockX, buffLockY, isCompact ? 2 : 2.8, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(buffLockX - 0.8, buffLockY - 1.2, 1.6, 2.4);

                // 3. Panel frontal superior (Display LCD negro acrílico)
                const panelX = towerX + colW;
                const panelW = towerW - (colW * 2);
                const panelH = towerH * 0.36;

                ctx.fillStyle = '#090d16';
                ctx.fillRect(panelX, towerY, panelW, panelH);

                // Botón de encendido cuadrado a la izquierda
                const pwrSize = isCompact ? 6 : 9;
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(panelX + 4, towerY + 5, pwrSize, pwrSize);
                ctx.strokeStyle = '#22c55e';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(panelX + 4, towerY + 5, pwrSize, pwrSize);

                // Pantalla LCD azul/cian retroiluminada
                const lcdW = isCompact ? panelW * 0.44 : panelW * 0.48;
                const lcdH = panelH * 0.52;
                const lcdX = panelX + (panelW / 2) - (lcdW / 2);
                const lcdY = towerY + 4;

                ctx.fillStyle = '#03233b';
                ctx.fillRect(lcdX, lcdY, lcdW, lcdH);
                ctx.strokeStyle = '#0284c7';
                ctx.lineWidth = 0.6;
                ctx.strokeRect(lcdX, lcdY, lcdW, lcdH);

                ctx.fillStyle = '#38bdf8';
                ctx.font = isCompact ? 'bold 4px monospace' : 'bold 5.5px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('ONLINE', lcdX + (lcdW / 2), lcdY + (lcdH / 2) + 2);

                // Texto "TeraStation" debajo del LCD
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? '4.5px sans-serif' : '6px sans-serif';
                ctx.fillText('TeraStation', lcdX + (lcdW / 2), towerY + panelH - 2);

                // Micro LEDs superiores (INFO, ERROR, LAN1-3)
                const ledColors = ['#22c55e', '#ef4444', '#22c55e', '#22c55e', '#22c55e'];
                for (let ld = 0; ld < ledColors.length; ld++) {
                    ctx.fillStyle = ledColors[ld];
                    ctx.beginPath();
                    ctx.arc(panelX + (panelW / 2) - 12 + (ld * 6), towerY + 2.5, 0.9, 0, Math.PI * 2);
                    ctx.fill();
                }

                // 4. LÍNEA ROJA HORIZONTAL EMBLEMÁTICA DE TERA STATION
                ctx.fillStyle = '#ef4444';
                ctx.fillRect(panelX, towerY + panelH, panelW, 1.8);

                // 5. Puerta inferior de rejilla hexagonal con logo BUFFALO
                const doorY = towerY + panelH + 1.8;
                const doorH = towerH - panelH - 1.8;

                ctx.fillStyle = '#080c14';
                ctx.fillRect(panelX, doorY, panelW, doorH);

                // Trama de panal de abeja hexagonal
                ctx.fillStyle = '#1e293b';
                const hexStep = isCompact ? 4 : 5.5;
                for (let gx = panelX + 3; gx < panelX + panelW - 3; gx += hexStep) {
                    for (let gy = doorY + 3; gy < doorY + doorH - 3; gy += hexStep) {
                        ctx.beginPath();
                        ctx.arc(gx, gy, 1.1, 0, Math.PI * 2);
                        ctx.fill();
                    }
                }

                // Insignia metálica central "BUFFALO"
                const bBadgeW = isCompact ? panelW * 0.62 : panelW * 0.54;
                const bBadgeH = Math.min(isCompact ? 8 : 11, doorH * 0.55);
                const bBadgeX = panelX + (panelW / 2) - (bBadgeW / 2);
                const bBadgeY = doorY + (doorH / 2) - (bBadgeH / 2);

                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(bBadgeX, bBadgeY, bBadgeW, bBadgeH);
                ctx.strokeStyle = '#475569';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(bBadgeX, bBadgeY, bBadgeW, bBadgeH);

                ctx.fillStyle = '#0f172a';
                ctx.font = isCompact ? 'bold 5px sans-serif' : 'bold 7px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('BUFFALO', panelX + (panelW / 2), doorY + (doorH / 2));
                ctx.textBaseline = 'alphabetic';

            } else if (itemTypeStr.includes('qnap')) {
                // NAS QNAP 1U 4-BAY RACKMOUNT (IMAGEN 3: 4 BAHÍAS HORIZONTALES, MANIJAS NEGRAS, BANDA ACRÍLICA Y LOGO QNAP)
                // 1. Chasis frontal metálico
                ctx.fillStyle = '#e2e8f0';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#94a3b8';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                // Orejas de montaje metálicas laterales con MANIJAS / ASAS NEGRAS
                const earW = isCompact ? 9 : 13;

                // Placas plateadas de orejas
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX, eqY + 1, earW, eqH);
                ctx.fillRect(innerX + innerW - earW, eqY + 1, earW, eqH);

                // Asas / Manijas redondeadas negras frontales QNAP
                const handleW = isCompact ? 3.5 : 5;
                const handleH = eqH * 0.72;
                const handleY = eqY + (eqH / 2) - (handleH / 2);

                ctx.fillStyle = '#0f172a';
                ctx.fillRect(innerX + 2, handleY, handleW, handleH);
                ctx.fillRect(innerX + innerW - earW + (earW - handleW - 2), handleY, handleW, handleH);
                ctx.strokeStyle = '#475569';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(innerX + 2, handleY, handleW, handleH);
                ctx.strokeRect(innerX + innerW - earW + (earW - handleW - 2), handleY, handleW, handleH);

                const qnapX = innerX + earW;
                const qnapW = innerW - (earW * 2);

                // 2. Banda acrílica negra superior
                const topBarH = Math.max(5, eqH * 0.36);
                ctx.fillStyle = '#080c14';
                ctx.fillRect(qnapX, eqY + 1, qnapW, topBarH);

                // Logo QNAP en blanco a la izquierda
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 5.5px sans-serif' : 'bold 8px sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText('QNAP', qnapX + 4, eqY + topBarH - 1.5);

                // Cluster de LEDs de estado a la derecha de la barra superior
                const ledClusterStartX = qnapX + qnapW - (isCompact ? 45 : 70);

                // LEDs numerados de bahías 1, 2, 3, 4
                for (let d = 0; d < 4; d++) {
                    const lx = ledClusterStartX + (d * (isCompact ? 5.5 : 8));
                    ctx.fillStyle = '#22c55e';
                    ctx.beginPath(); ctx.arc(lx, eqY + (topBarH / 2), 0.9, 0, Math.PI * 2); ctx.fill();
                }

                // LED 10GbE / Status (azul/naranja)
                ctx.fillStyle = '#00aaff';
                ctx.beginPath(); ctx.arc(ledClusterStartX + (4 * (isCompact ? 5.5 : 8)), eqY + (topBarH / 2), 1.1, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = '#f59e0b';
                ctx.beginPath(); ctx.arc(ledClusterStartX + (5 * (isCompact ? 5.5 : 8)), eqY + (topBarH / 2), 1.1, 0, Math.PI * 2); ctx.fill();

                // Botón de encendido micro
                ctx.fillStyle = '#ffffff';
                ctx.beginPath(); ctx.arc(qnapX + qnapW - 4, eqY + (topBarH / 2), 1.1, 0, Math.PI * 2); ctx.fill();

                // 3. Fila inferior de 4 Bahías de discos horizontales Hot-Swap (Bays 1-4)
                const bayAreaY = eqY + 1 + topBarH;
                const bayAreaH = eqH - topBarH;
                const bayCount = 4;
                const singleBayW = (qnapW - ((bayCount + 1) * 1.5)) / bayCount;

                for (let b = 0; b < bayCount; b++) {
                    const bx = qnapX + 1.5 + (b * (singleBayW + 1.5));

                    // Carcasa de la bahía
                    ctx.fillStyle = '#0e1420';
                    ctx.fillRect(bx, bayAreaY, singleBayW, bayAreaH);
                    ctx.strokeStyle = '#475569';
                    ctx.lineWidth = 0.6;
                    ctx.strokeRect(bx, bayAreaY, singleBayW, bayAreaH);

                    // Rejilla microperforada de ventilación en cada bahía
                    ctx.fillStyle = '#1e293b';
                    const caddyPerforW = singleBayW * 0.62;
                    ctx.fillRect(bx + 2, bayAreaY + 2, caddyPerforW, bayAreaH - 4);

                    // Micro-puntos de ventilación
                    ctx.fillStyle = '#0a0e17';
                    for (let px = bx + 4; px < bx + caddyPerforW; px += 2.5) {
                        for (let py = bayAreaY + 3; py < bayAreaY + bayAreaH - 3; py += 2.5) {
                            ctx.fillRect(px, py, 0.8, 0.8);
                        }
                    }

                    // Palanca de liberación y pestillo (Latch Handle) a la derecha
                    const latchX = bx + caddyPerforW + 2;
                    const latchW = singleBayW - caddyPerforW - 4;
                    ctx.fillStyle = '#1a2333';
                    ctx.fillRect(latchX, bayAreaY + 2, latchW, bayAreaH - 4);
                    ctx.strokeStyle = '#64748b';
                    ctx.lineWidth = 0.5;
                    ctx.strokeRect(latchX, bayAreaY + 2, latchW, bayAreaH - 4);

                    // Pestaña metálica de expulsión
                    ctx.fillStyle = '#94a3b8';
                    ctx.fillRect(latchX + 1, bayAreaY + (bayAreaH / 2) - 1, latchW - 2, 2);
                }
            } else if (itemTypeStr === 'btac_box' || itemTypeStr.includes('btac')) {
                // DISPOSITIVO BTAC BOX (DISEÑO SEGÚN IMAGEN: MARCO AZUL, PANEL NEGRO, PANTALLA CIAN, D-PAD Y 4 BARRAS)
                // 1. Marco exterior azul acero característico (#1f6ca5)
                const blueBorderW = isCompact ? 1.5 : 2.5;
                ctx.fillStyle = '#1f6ca5';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);

                // 2. Chasis frontal negro profundo (#05070a)
                ctx.fillStyle = '#05070a';
                ctx.fillRect(innerX + blueBorderW, eqY + 1 + blueBorderW, innerW - (blueBorderW * 2), eqH - (blueBorderW * 2));

                // Orejas metálicas laterales de montaje en rack
                const earW = isCompact ? 6 : 9;
                ctx.fillStyle = '#1f6ca5';
                ctx.fillRect(innerX, eqY + 1, earW, eqH);
                ctx.fillRect(innerX + innerW - earW, eqY + 1, earW, eqH);

                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX + 1.5, eqY + (eqH / 2) - 1.5, earW - 3, 3);
                ctx.fillRect(innerX + innerW - earW + 1.5, eqY + (eqH / 2) - 1.5, earW - 3, 3);

                // 3. Texto identificador en el área izquierda: BTAC BOX
                ctx.fillStyle = '#38bdf8';
                ctx.font = isCompact ? 'bold 6px font-monospace' : 'bold 8px font-monospace';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillText('BTAC BOX', innerX + earW + (isCompact ? 4 : 7), eqY + (eqH / 2));

                // 4. Pantalla rectangular azul cian brillante (#29b6f6)
                const scrW = isCompact ? 34 : 52;
                const scrH = Math.min(eqH * 0.65, isCompact ? 9 : 13);
                const scrX = innerX + (innerW * 0.32);
                const scrY = eqY + (eqH / 2) - (scrH / 2);

                ctx.fillStyle = '#29b6f6';
                ctx.fillRect(scrX, scrY, scrW, scrH);
                ctx.strokeStyle = '#0284c7';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(scrX, scrY, scrW, scrH);

                // Texto digital LCD sutil
                ctx.fillStyle = '#083344';
                ctx.font = isCompact ? 'bold 4.5px monospace' : 'bold 6px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('21.5°C', scrX + (scrW / 2), scrY + (scrH / 2) + 0.5);

                // 5. Botonera D-PAD en cruz (4 botones direccionales redondeados)
                const dpadX = scrX + scrW + (isCompact ? 10 : 15);
                const dpadY = eqY + (eqH / 2);
                const btnR = isCompact ? 1.5 : 2.3;
                const dOff = isCompact ? 3.0 : 4.5;

                const dirPositions = [
                    { x: dpadX, y: dpadY - dOff }, // UP
                    { x: dpadX, y: dpadY + dOff }, // DOWN
                    { x: dpadX - dOff, y: dpadY }, // LEFT
                    { x: dpadX + dOff, y: dpadY }  // RIGHT
                ];

                dirPositions.forEach(pos => {
                    // Aro plateado/blanco exterior
                    ctx.fillStyle = '#e2e8f0';
                    ctx.beginPath();
                    ctx.arc(pos.x, pos.y, btnR + 0.6, 0, Math.PI * 2);
                    ctx.fill();
                    // Interior oscuro del botón
                    ctx.fillStyle = '#0f172a';
                    ctx.beginPath();
                    ctx.arc(pos.x, pos.y, btnR, 0, Math.PI * 2);
                    ctx.fill();
                });

                // 6. Cuatro barras horizontales redondeadas (Pill bars grises)
                const pillStartX = dpadX + (isCompact ? 10 : 15);
                const pillRightMax = innerX + innerW - earW - (isCompact ? 12 : 18);
                const pillFullW = Math.max(10, pillRightMax - pillStartX);
                const pillShortW = pillFullW * 0.55;
                const pillH = Math.max(1.8, Math.min(2.8, (eqH - 6) / 4 - 0.5));
                const pillSpacing = Math.max(0.5, (eqH - 6 - (pillH * 4)) / 3);

                ctx.fillStyle = '#4b5563';
                for (let p = 0; p < 4; p++) {
                    const py = eqY + 3 + p * (pillH + pillSpacing);
                    const pw = (p < 2) ? pillShortW : pillFullW;

                    if (typeof ctx.roundRect === 'function') {
                        ctx.beginPath();
                        ctx.roundRect(pillStartX, py, pw, pillH, pillH / 2);
                        ctx.fill();
                    } else {
                        ctx.fillRect(pillStartX, py, pw, pillH);
                    }
                }

                // 7. LED Indicador circular en la esquina superior derecha
                const ledX = innerX + innerW - earW - (isCompact ? 5 : 8);
                const ledY = eqY + 3 + (pillH / 2);
                const ledR = isCompact ? 1.8 : 2.6;

                // Anillo exterior gris
                ctx.fillStyle = '#64748b';
                ctx.beginPath(); ctx.arc(ledX, ledY, ledR, 0, Math.PI * 2); ctx.fill();

                // Núcleo brillante cian (#29b6f6)
                ctx.fillStyle = '#29b6f6';
                ctx.beginPath(); ctx.arc(ledX, ledY, ledR * 0.65, 0, Math.PI * 2); ctx.fill();
                ctx.textBaseline = 'alphabetic';
            } else if (itemTypeStr.includes('nas')) {
                // Fallback genérico para otros tipos de NAS
                ctx.fillStyle = '#020617';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#38bdf8';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                const bayW = (innerW - 20) / 4;
                for (let r = 0; r < 2; r++) {
                    for (let c = 0; c < 4; c++) {
                        ctx.fillStyle = '#1e293b';
                        ctx.fillRect(innerX + 6 + (c * (bayW + 3)), eqY + 3 + (r * 9), bayW, 7);
                        ctx.fillStyle = '#94a3b8';
                        ctx.fillRect(innerX + 8 + (c * (bayW + 3)), eqY + 5 + (r * 9), bayW - 4, 2);
                    }
                }
                ctx.fillStyle = '#38bdf8';
                ctx.font = isCompact ? 'bold 7px sans-serif' : 'bold 9px sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText(itemNameStr, innerX + innerW - 6, eqY + eqH - 4);
            } else if (itemTypeStr === 'ups_1' || itemTypeStr.includes('industronic')) {
                // UPS 1 - INDUSTRONIC 3U (PANTALLA LCD 220V, LOGO Y REJILLA INFERIOR)
                ctx.fillStyle = '#080c14';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH - 4);
                ctx.strokeStyle = '#334155';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH - 4);

                // 2. Módulo de Pantalla superior: marco blanco redondeado
                const dispW = innerW * 0.76;
                const dispH = Math.min(36, (eqH - 6) * 0.44);
                const dispX = innerX + (innerW - dispW) / 2;
                const dispY = eqY + 3;

                ctx.fillStyle = '#f8fafc';
                ctx.fillRect(dispX, dispY, dispW, dispH);

                // Fondo pantalla negra
                const scrPad = 2;
                ctx.fillStyle = '#050811';
                ctx.fillRect(dispX + scrPad, dispY + scrPad, dispW - (scrPad * 2), dispH - (scrPad * 2));

                // Pantalla LCD Azul Brillante (#0055ff / #00d2ff)
                const lcdW = dispW - (scrPad * 2) - 4;
                const lcdH = dispH * 0.54;
                const lcdX = dispX + scrPad + 2;
                const lcdY = dispY + scrPad + 2;
                ctx.fillStyle = '#0055ff';
                ctx.fillRect(lcdX, lcdY, lcdW, lcdH);
                ctx.fillStyle = '#00f2fe';
                ctx.font = isCompact ? 'bold 6.5px monospace' : 'bold 8.5px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('220V', lcdX + (lcdW / 2), lcdY + (lcdH / 2) + 2.5);

                // 3 Botones capacitivos táctiles redondos debajo de la pantalla
                const btnRowY = dispY + dispH - 4;
                for (let b = 0; b < 3; b++) {
                    const bx = dispX + (dispW / 2) - 7 + (b * 7);
                    ctx.fillStyle = '#334155';
                    ctx.beginPath(); ctx.arc(bx, btnRowY, 1.5, 0, Math.PI * 2); ctx.fill();
                    ctx.strokeStyle = '#cbd5e1'; ctx.lineWidth = 0.5; ctx.stroke();
                }

                // 3. Marca "Industronic" centrada debajo de la pantalla
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 5px sans-serif' : 'bold 6.5px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('Industronic', innerX + (innerW / 2), dispY + dispH + 7);

                // 4. Gran Rejilla inferior de ventilación microperforada
                const grillY = dispY + dispH + 10;
                const grillH = eqH - 5 - (grillY - eqY);
                if (grillH > 6) {
                    ctx.fillStyle = '#090d16';
                    ctx.fillRect(innerX + 3, grillY, innerW - 6, grillH);
                    ctx.strokeStyle = '#1e293b';
                    ctx.lineWidth = 0.6;
                    ctx.strokeRect(innerX + 3, grillY, innerW - 6, grillH);

                    ctx.fillStyle = '#1e293b';
                    const step = isCompact ? 3.5 : 4.5;
                    for (let gx = innerX + 5; gx < innerX + innerW - 5; gx += step) {
                        for (let gy = grillY + 3; gy < grillY + grillH - 2; gy += step) {
                            ctx.beginPath(); ctx.arc(gx, gy, 0.7, 0, Math.PI * 2); ctx.fill();
                        }
                    }
                }

                // Patitas inferiores
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(innerX + 6, eqY + eqH - 5, 5, 2);
                ctx.fillRect(innerX + innerW - 11, eqY + eqH - 5, 5, 2);

            } else if (itemTypeStr === 'ups_2' || itemTypeStr.includes('apc') || itemTypeStr.includes('smart-ups')) {
                // UPS 2 - APC SMART-UPS 3000 3U (DISEÑO EXACTO SEGÚN IMAGEN 2)
                ctx.fillStyle = '#1e222b';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH - 4);
                ctx.strokeStyle = '#334155';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH - 4);

                // Línea de corte / costura central horizontal
                const midSeamY = eqY + ((eqH - 4) * 0.48);
                ctx.strokeStyle = '#0f172a';
                ctx.lineWidth = 1.2;
                ctx.beginPath(); ctx.moveTo(innerX, midSeamY); ctx.lineTo(innerX + innerW, midSeamY); ctx.stroke();

                // Columna superior izquierda de LEDs (Load) y botones
                const ctrlStartX = innerX + 5;
                for (let ld = 0; ld < 4; ld++) {
                    ctx.fillStyle = (ld === 0 ? '#ef4444' : (ld === 1 ? '#f59e0b' : '#22c55e'));
                    ctx.fillRect(ctrlStartX, eqY + 4 + (ld * 3), 2.2, 1.4);
                }
                // Botones redondos de encendido/test
                ctx.fillStyle = '#0f172a';
                ctx.beginPath(); ctx.arc(ctrlStartX + 8, eqY + 6, 2.0, 0, Math.PI * 2); ctx.fill();
                ctx.beginPath(); ctx.arc(ctrlStartX + 8, eqY + 12, 2.0, 0, Math.PI * 2); ctx.fill();

                // Columna batería derecha
                for (let bt = 0; bt < 4; bt++) {
                    ctx.fillStyle = '#22c55e';
                    ctx.fillRect(ctrlStartX + 13, eqY + 4 + (bt * 3), 2.2, 1.4);
                }

                // Logo rojo "APC"
                ctx.fillStyle = '#dc2626';
                ctx.font = isCompact ? 'bold 6.5px sans-serif' : 'bold 8.5px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('APC', innerX + (innerW / 2), eqY + 22);

                // Insignia metálica oscura "Smart-UPS 3000"
                const badgeW = isCompact ? innerW * 0.68 : innerW * 0.60;
                const badgeH = 13;
                const badgeX = innerX + (innerW - badgeW) / 2;
                const badgeY = eqY + 26;

                ctx.fillStyle = '#0f172a';
                ctx.fillRect(badgeX, badgeY, badgeW, badgeH);
                ctx.strokeStyle = '#475569';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(badgeX, badgeY, badgeW, badgeH);

                ctx.fillStyle = '#cbd5e1';
                ctx.font = 'bold 5px sans-serif';
                ctx.fillText('Smart-UPS', badgeX + (badgeW / 2), badgeY + 5.5);
                ctx.font = 'bold 6px monospace';
                ctx.fillText('3000', badgeX + (badgeW / 2), badgeY + 11.5);

                // Rejillas de ventilación laterales
                const ventStartX = innerX + innerW - 12;
                ctx.fillStyle = '#0f172a';
                for (let r = 0; r < 4; r++) {
                    for (let c = 0; c < 2; c++) {
                        ctx.fillRect(ventStartX + (c * 4), eqY + 8 + (r * 5), 2.5, 2.5);
                    }
                }

                // Panel inferior de baterías
                ctx.fillStyle = '#161a22';
                ctx.fillRect(innerX + 2, midSeamY + 2, innerW - 4, eqH - 6 - (midSeamY - eqY));

            } else if (itemTypeStr === 'servidor_2' || itemTypeStr.includes('servidor_2') || itemTypeStr.includes('servidor 2')) {
                // SERVIDOR 2 - CHASSIS MODULAR BLADES & DISCOS 3U (SEGÚN IMAGEN 3)
                ctx.fillStyle = '#1f2937';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH - 4);
                ctx.strokeStyle = '#4b5563';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH - 4);

                // 2. BAHÍAS SUPERIORES MODULARES (6 Filas de Blades horizontales con LEDs amarillos)
                const upperH = (eqH - 6) * 0.60;
                const bladeCount = 6;
                const singleBladeH = (upperH - 3) / bladeCount;

                for (let b = 0; b < bladeCount; b++) {
                    const by = eqY + 3 + (b * singleBladeH);
                    ctx.fillStyle = '#111827';
                    ctx.fillRect(innerX + 2, by, innerW - 4, singleBladeH - 1);
                    ctx.strokeStyle = '#374151';
                    ctx.lineWidth = 0.5;
                    ctx.strokeRect(innerX + 2, by, innerW - 4, singleBladeH - 1);

                    // Micro-LED de encendido blade
                    ctx.fillStyle = '#22c55e';
                    ctx.fillRect(innerX + 4, by + (singleBladeH / 2) - 0.6, 1.2, 1.2);

                    // LEDs amarillos/ámbar de procesamiento
                    const ledStartX = innerX + 8;
                    const numLeds = (b % 2 === 0) ? 4 : 5;
                    for (let l = 0; l < numLeds; l++) {
                        ctx.fillStyle = (l % 3 === 0) ? '#facc15' : '#eab308';
                        ctx.fillRect(ledStartX + (l * (isCompact ? 4 : 6.5)), by + (singleBladeH / 2) - 0.6, 1.4, 1.2);
                    }
                }

                // 3. BAHÍAS INFERIORES DE ALMACENAMIENTO Y PODER (3 Caddies Verticales con LEDs)
                const lowerY = eqY + 3 + upperH;
                const lowerH = eqH - 6 - upperH;
                const caddyCount = 3;
                const caddyW = (innerW - 6 - (caddyCount - 1) * 2) / caddyCount;

                for (let c = 0; c < caddyCount; c++) {
                    const cx = innerX + 3 + (c * (caddyW + 2));
                    ctx.fillStyle = '#0f172a';
                    ctx.fillRect(cx, lowerY, caddyW, lowerH);
                    ctx.strokeStyle = '#475569';
                    ctx.lineWidth = 0.6;
                    ctx.strokeRect(cx, lowerY, caddyW, lowerH);

                    // Ranuras acanaladas verticales
                    ctx.fillStyle = '#334155';
                    for (let sl = cx + 2.5; sl < cx + caddyW - 2.5; sl += 2) {
                        ctx.fillRect(sl, lowerY + 2, 0.9, lowerH - 4);
                    }

                    // LEDs de estado caddy
                    if (c === 0) {
                        ctx.fillStyle = '#ef4444'; ctx.fillRect(cx + 1, lowerY + 2.5, 1.1, 1.1);
                        ctx.fillStyle = '#22c55e'; ctx.fillRect(cx + 1, lowerY + 5.5, 1.1, 1.1);
                    } else {
                        ctx.fillStyle = '#22c55e'; ctx.fillRect(cx + 1, lowerY + 2.5, 1.1, 1.1);
                        ctx.fillStyle = '#22c55e'; ctx.fillRect(cx + 1, lowerY + 5.5, 1.1, 1.1);
                    }
                }

            } else if (itemTypeStr.includes('servidor') && itemTypeStr !== 'servidor_anterior' && itemTypeStr !== 'servidor_2') {
                // SERVIDOR RACK 1U - HPE PROLIANT (Gen10 / Gen11)
                // Chasis principal oscuro y perfil metálico
                ctx.fillStyle = '#0a0e17';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);

                // Bordes metálicos superior e inferior del servidor (chasis plateado)
                ctx.fillStyle = '#94a3b8';
                ctx.fillRect(innerX, eqY + 1, innerW, 1.2);
                ctx.fillRect(innerX, eqY + eqH - 1, innerW, 1.2);

                const leftEarW = isCompact ? 12 : 18;
                const rightEarW = isCompact ? 14 : 22;
                const driveAreaX = innerX + leftEarW;
                const driveAreaW = innerW - leftEarW - rightEarW;

                // 1. OREJA IZQUIERDA (Soporte aluminio con jaladera / candado)
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX, eqY + 1, leftEarW, eqH);
                ctx.fillStyle = '#475569';
                ctx.fillRect(innerX + 2, eqY + 2.5, leftEarW - 4, Math.max(2, eqH - 5));
                ctx.fillStyle = '#94a3b8';
                ctx.fillRect(innerX + (leftEarW / 2) - 1, eqY + 3.5, 2, Math.max(2, eqH - 7));
                // Ranura de candado / seguro
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(innerX + leftEarW - 3, eqY + (eqH / 2) - 2, 2, 4);

                // 2. OREJA DERECHA (Panel de Control HPE ProLiant)
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX + innerW - rightEarW, eqY + 1, rightEarW, eqH);
                ctx.fillStyle = '#090d16';
                ctx.fillRect(innerX + innerW - rightEarW + 2, eqY + 2, rightEarW - 4, Math.max(2, eqH - 4));

                // LED encendido (Power verde)
                ctx.fillStyle = '#22c55e';
                ctx.beginPath();
                ctx.arc(innerX + innerW - (rightEarW / 2), eqY + (eqH * 0.35), 1.4, 0, Math.PI * 2);
                ctx.fill();

                // LED Health / Heartbeat (Verde)
                ctx.fillStyle = '#22c55e';
                ctx.beginPath();
                ctx.arc(innerX + innerW - (rightEarW / 2), eqY + (eqH * 0.65), 1.4, 0, Math.PI * 2);
                ctx.fill();

                // Puerto USB / iLO
                ctx.fillStyle = '#475569';
                ctx.fillRect(innerX + innerW - rightEarW + 3, eqY + (eqH / 2) - 2, 3, 4);

                // 3. BAHÍAS DE DISCOS SFF (Fondo tras el bisel frontal)
                const sffCount = isCompact ? 10 : 18;
                const sffW = driveAreaW / sffCount;
                for (let s = 0; s < sffCount; s++) {
                    const sx = driveAreaX + (s * sffW);
                    // Ranura de disco vertical
                    ctx.fillStyle = '#050811';
                    ctx.fillRect(sx + 0.5, eqY + 2, sffW - 1, Math.max(2, eqH - 4));
                    ctx.fillStyle = '#1e293b';
                    ctx.fillRect(sx + 1, eqY + 2.5, sffW - 2, Math.max(1, eqH - 5));

                    // Pestaña de liberación roja del caddy HPE
                    ctx.fillStyle = '#dc2626';
                    ctx.fillRect(sx + 1.5, eqY + eqH - 4, sffW - 3, 1.5);

                    // LED actividad de disco (verde/ámbar)
                    ctx.fillStyle = (s % 3 === 0) ? '#22c55e' : ((s % 3 === 1) ? '#01a982' : '#050811');
                    ctx.beginPath();
                    ctx.arc(sx + (sffW / 2), eqY + 3.5, 0.8, 0, Math.PI * 2);
                    ctx.fill();
                }

                // 4. BISEL FRONTAL GEOMÉTRICO HPE PROLIANT (Hexagonal / Chevron Bezel)
                ctx.save();
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 1.2;

                const midY = eqY + (eqH / 2);
                const midX = driveAreaX + (driveAreaW / 2);

                // Marco exterior del bisel
                ctx.strokeRect(driveAreaX, eqY + 1.5, driveAreaW, Math.max(2, eqH - 3));

                // Tirantes diagonales del bisel HPE
                ctx.beginPath();
                ctx.moveTo(driveAreaX, eqY + 2);
                ctx.lineTo(midX - (isCompact ? 18 : 28), midY - (isCompact ? 2 : 4));
                ctx.moveTo(driveAreaX, eqY + eqH - 2);
                ctx.lineTo(midX - (isCompact ? 18 : 28), midY + (isCompact ? 2 : 4));

                ctx.moveTo(driveAreaX + driveAreaW, eqY + 2);
                ctx.lineTo(midX + (isCompact ? 18 : 28), midY - (isCompact ? 2 : 4));
                ctx.moveTo(driveAreaX + driveAreaW, eqY + eqH - 2);
                ctx.lineTo(midX + (isCompact ? 18 : 28), midY + (isCompact ? 2 : 4));
                ctx.stroke();

                // 5. DISTINTIVO EMBLEMÁTICO HPE (RECTÁNGULO VERDE CYAN/MINT)
                const badgeW = isCompact ? 30 : 46;
                const badgeH = Math.min(isCompact ? 8 : 11, eqH * 0.65);
                const badgeX = midX - (badgeW / 2);
                const badgeY = midY - (badgeH / 2);

                // Fondo oscuro del logo
                ctx.fillStyle = '#050811';
                ctx.fillRect(badgeX, badgeY, badgeW, badgeH);

                // Rectángulo corporativo HPE verde menta brillante (#01a982)
                ctx.strokeStyle = '#01a982';
                ctx.lineWidth = isCompact ? 1.2 : 1.6;
                ctx.strokeRect(badgeX + 1.5, badgeY + 1, badgeW - 3, badgeH - 2);

                // Centro / detalle del logo HPE
                ctx.fillStyle = '#01a982';
                ctx.fillRect(badgeX + 4, badgeY + (badgeH / 2) - 0.7, badgeW - 8, 1.4);

                // Texto identificador: SERVIDOR
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 5.5px font-monospace' : 'bold 6.5px font-monospace';
                ctx.textAlign = 'right';
                ctx.fillText('SERVIDOR', innerX + innerW - rightEarW - 4, eqY + (eqH / 2) + 2.2);

                ctx.restore();
            } else if (itemTypeStr === 'servidor_anterior') {
                const halfW = (innerW - 6) / 2;
                ctx.fillStyle = '#020617';
                ctx.fillRect(innerX, eqY + 1, halfW, eqH);
                ctx.fillRect(innerX + halfW + 6, eqY + 1, halfW, eqH);

                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, halfW, eqH);
                ctx.strokeRect(innerX + halfW + 6, eqY + 1, halfW, eqH);

                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? '6px sans-serif' : '8px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('SRV 1 👤', innerX + (halfW / 2), eqY + (eqH / 2) + 3);
                ctx.fillText('SRV 2 👤', innerX + halfW + 6 + (halfW / 2), eqY + (eqH / 2) + 3);
            } else if (itemTypeStr === 'enlace_mcm' || itemTypeStr.includes('enlace_mcm') || itemTypeStr.includes('mcm') || itemNameStr.toLowerCase().includes('mcm')) {
                // ENLACE MCM 1U TELECOM (DISEÑO SEGÚN IMAGEN: FRONTAL MORADO, PANTALLA AZUL OSCURO, TEXTO NEGRO Y 4 PUERTOS 2X2)
                // 1. Chasis frontal morado / púrpura
                ctx.fillStyle = '#7c3aed';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#4c1d95';
                ctx.lineWidth = 1.2;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                // Orejas metálicas de montaje laterales
                const earW = isCompact ? 6 : 9;
                ctx.fillStyle = '#4c1d95';
                ctx.fillRect(innerX, eqY + 1, earW, eqH);
                ctx.fillRect(innerX + innerW - earW, eqY + 1, earW, eqH);
                ctx.fillStyle = '#cbd5e1';
                ctx.fillRect(innerX + 1.5, eqY + (eqH / 2) - 1.5, earW - 3, 3);
                ctx.fillRect(innerX + innerW - earW + 1.5, eqY + (eqH / 2) - 1.5, earW - 3, 3);

                // 2. Pantalla / Módulo izquierdo con degradado azul oscuro profundo
                const scrW = isCompact ? 22 : 34;
                const scrH = eqH - (isCompact ? 4 : 6);
                const scrX = innerX + earW + (isCompact ? 3 : 6);
                const scrY = eqY + 1 + (eqH - scrH) / 2;

                const scrGrad = ctx.createLinearGradient(scrX, scrY, scrX + scrW, scrY + scrH);
                scrGrad.addColorStop(0, '#020617');
                scrGrad.addColorStop(0.6, '#0f172a');
                scrGrad.addColorStop(1, '#1e3a8a');
                ctx.fillStyle = scrGrad;
                ctx.fillRect(scrX, scrY, scrW, scrH);
                ctx.strokeStyle = '#020617';
                ctx.lineWidth = 0.8;
                ctx.strokeRect(scrX, scrY, scrW, scrH);

                // Brillo superior sutil de pantalla
                ctx.fillStyle = 'rgba(56, 189, 248, 0.25)';
                ctx.fillRect(scrX + 1, scrY + 1, scrW - 2, isCompact ? 2 : 3);

                // 3. Dos líneas centrales de texto negro / grafito oscuro
                const textX = scrX + scrW + (isCompact ? 6 : 10);
                ctx.fillStyle = '#09090b';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.font = isCompact ? 'bold 6.5px sans-serif' : 'bold 9px sans-serif';
                ctx.fillText('enlace mcm', textX, eqY + (eqH * 0.38));

                ctx.font = isCompact ? 'bold 5px monospace' : 'bold 6.5px monospace';
                ctx.fillText('OPTICAL LINK', textX, eqY + (eqH * 0.70));

                // 4. Grupo de 4 puertos / conectores cuadrados en cuadrícula 2x2 a la derecha
                const pW = isCompact ? 5 : 7.5;
                const pH = isCompact ? 3.5 : 5.5;
                const pGapX = isCompact ? 2 : 3;
                const pGapY = isCompact ? 1.5 : 2.5;
                const portBlockW = (pW * 2) + pGapX;
                const portBlockH = (pH * 2) + pGapY;
                const portStartX = innerX + innerW - earW - portBlockW - (isCompact ? 4 : 8);
                const portStartY = eqY + 1 + (eqH - portBlockH) / 2;

                for (let r = 0; r < 2; r++) {
                    for (let c = 0; c < 2; c++) {
                        const px = portStartX + (c * (pW + pGapX));
                        const py = portStartY + (r * (pH + pGapY));

                        // Puerto negro
                        ctx.fillStyle = '#090d16';
                        ctx.fillRect(px, py, pW, pH);
                        ctx.strokeStyle = '#1e293b';
                        ctx.lineWidth = 0.5;
                        ctx.strokeRect(px, py, pW, pH);

                        // Micro LED interno
                        ctx.fillStyle = (r === 0) ? '#22c55e' : '#38bdf8';
                        ctx.fillRect(px + 1, py + 1, 1.2, 1.2);
                    }
                }
                ctx.textBaseline = 'alphabetic';
            } else if (itemTypeStr === 'barra_tierra') {
                ctx.fillStyle = '#22c55e';
                ctx.fillRect(innerX, eqY + 2, innerW, eqH - 2);
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 7px sans-serif' : 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('BARRA DE TIERRA FÍSICA', innerX + (innerW / 2), eqY + (eqH / 2) + 3);

                ctx.strokeStyle = '#22c55e';
                ctx.lineWidth = 2;
                for (let g = 0; g < 3; g++) {
                    ctx.beginPath();
                    ctx.moveTo(innerX + 30 + (g * 40), eqY + eqH);
                    ctx.lineTo(innerX + 30 + (g * 40), startY + rackHeight + 15);
                    ctx.stroke();
                }
            } else {
                ctx.fillStyle = item.color || '#1e293b';
                ctx.fillRect(innerX, eqY + 1, innerW, eqH);
                ctx.strokeStyle = '#38bdf8';
                ctx.lineWidth = 1;
                ctx.strokeRect(innerX, eqY + 1, innerW, eqH);

                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 7px sans-serif' : 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(itemNameStr, innerX + (innerW / 2), eqY + (eqH / 2) + 3);
            }

            // DISTINTIVO VISUAL 🔗 DE INVENTARIO REAL VINCULADO
            const hasInv = !!(item.inventoryData || item.inventoryKey);
            if (hasInv) {
                ctx.save();
                const invBadgeW = isCompact ? 16 : 22;
                const invBadgeH = isCompact ? 9 : 12;
                const invX = innerX + 2;
                const invY = eqY + 2;
                ctx.fillStyle = 'rgba(16, 185, 129, 0.9)';
                ctx.fillRect(invX, invY, invBadgeW, invBadgeH);
                ctx.strokeStyle = '#34d399';
                ctx.lineWidth = 1;
                ctx.strokeRect(invX, invY, invBadgeW, invBadgeH);
                ctx.fillStyle = '#ffffff';
                ctx.font = isCompact ? 'bold 6.5px monospace' : 'bold 8px monospace';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('🔗', invX + (invBadgeW / 2), invY + (invBadgeH / 2) + 0.5);
                ctx.restore();
            }

            // RESALTADO VISUAL DE SELECCIÓN ACTIVA (SIN BOTÓN [✕])
            const isItemSelected = (selectedMountedRackItem && selectedMountedRackItem.item === item);
            if (isItemSelected) {
                ctx.save();
                ctx.shadowColor = '#00f2fe';
                ctx.shadowBlur = 8;
                ctx.strokeStyle = '#00f2fe';
                ctx.lineWidth = 2;
                ctx.strokeRect(innerX - 1, eqY, innerW + 2, eqH + 1);

                // Brackets esquineros tácticos blancos
                const bLen = Math.min(6, eqH / 2);
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.moveTo(innerX - 2, eqY + bLen); ctx.lineTo(innerX - 2, eqY - 1); ctx.lineTo(innerX - 2 + bLen, eqY - 1);
                ctx.moveTo(innerX + innerW + 2 - bLen, eqY - 1); ctx.lineTo(innerX + innerW + 2, eqY - 1); ctx.lineTo(innerX + innerW + 2, eqY + bLen);
                ctx.moveTo(innerX - 2, eqY + eqH + 1 - bLen); ctx.lineTo(innerX - 2, eqY + eqH + 1); ctx.lineTo(innerX - 2 + bLen, eqY + eqH + 1);
                ctx.moveTo(innerX + innerW + 2 - bLen, eqY + eqH + 1); ctx.lineTo(innerX + innerW + 2, eqY + eqH + 1); ctx.lineTo(innerX + innerW + 2, eqY + eqH + 1 - bLen);
                ctx.stroke();

                // Indicador tipo etiqueta en la oreja derecha
                const invLabel = (item.inventoryData && item.inventoryData.label) ? item.inventoryData.label : '';
                const selTag = invLabel ? ('🔗 ' + invLabel) : '✓ SELECCIONADO';
                ctx.font = isCompact ? 'bold 6px sans-serif' : 'bold 7.5px sans-serif';
                const tagW = ctx.measureText(selTag).width + 8;
                const tagH = isCompact ? 10 : 12;
                const tagX = innerX + innerW - tagW - 4;
                const tagY = eqY + (eqH / 2) - (tagH / 2);
                ctx.fillStyle = invLabel ? 'rgba(16, 185, 129, 0.95)' : 'rgba(0, 242, 254, 0.92)';
                ctx.fillRect(tagX, tagY, tagW, tagH);
                ctx.fillStyle = '#061325';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(selTag, tagX + (tagW / 2), tagY + (tagH / 2) + 0.5);
                ctx.restore();
            }
        });
    });

    // 1. SOMBREADO EN VIVO AL ARRASTRAR UN EQUIPO DESDE LA PALETA (HTML5 DRAG)
    if (currentPaletteDragHover && currentPaletteDragHover.canvasId === canvasId) {
        const hover = currentPaletteDragHover;
        const rackStartX = marginX + hover.rackIndex * (rW + gap);
        drawRackSlotShadow(ctx, rackStartX, rW, startY, slotH, hover.slotFromTop, hover.uHeight, hover.tipoTool, hover.rackKey, isCompact, hover.isFree);
    }

    // 2. SOMBREADO EN VIVO AL REUBICAR UN EQUIPO EXISTENTE (MOUSE DRAG INTERNO)
    if (draggedMountedItem && draggedMountedItem.canvasId === canvasId) {
        if (draggedMountedItem.targetHover) {
            const h = draggedMountedItem.targetHover;
            const rackStartX = marginX + h.rackIndex * (rW + gap);
            drawRackSlotShadow(ctx, rackStartX, rW, startY, slotH, h.slotFromTop, h.uHeight, draggedMountedItem.item.type || draggedMountedItem.item.name, h.rackKey, isCompact, h.isFree);
        }

        // Mini preview flotante junto al cursor del mouse (Cian si libre, Rojo si ocupado)
        const isFree = (draggedMountedItem.targetHover && draggedMountedItem.targetHover.isFree !== undefined) ? draggedMountedItem.targetHover.isFree : true;
        ctx.fillStyle = isFree ? 'rgba(56, 189, 248, 0.4)' : 'rgba(239, 68, 68, 0.45)';
        ctx.strokeStyle = isFree ? '#00f2fe' : '#ef4444';
        ctx.lineWidth = 2;
        ctx.fillRect(mouseCanvasPos.x - 60, mouseCanvasPos.y - 12, 120, 24);
        ctx.strokeRect(mouseCanvasPos.x - 60, mouseCanvasPos.y - 12, 120, 24);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 9.5px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(draggedMountedItem.item.name || 'EQUIPO', mouseCanvasPos.x, mouseCanvasPos.y + 4);
    }
}

function agregarHerramientaSite(tipoTool) {
    const configEquipos = {
        'servidor': { name: 'SERVIDOR', color: '#0a0e17', type: 'servidor' },
        'switch': { name: 'SWITCH 24P', color: '#d8dde6', type: 'switch' },
        'udm_pro': { name: 'UDM PRO', color: '#d8dde6', type: 'udm_pro' },
        'ont': { name: 'ONT INFINITUM', color: '#0a0f18', type: 'ont' },
        'fortinet': { name: 'FORTINET FIREWALL', color: '#ffffff', type: 'fortinet' },
        'nas_datto': { name: 'NAS DATTO', color: '#00b4d8', type: 'nas_datto' },
        'nas_buffalo': { name: 'NAS BUFFALO', color: '#334155', type: 'nas_buffalo' },
        'nas_qnap': { name: 'NAS QNAP', color: '#e2e8f0', type: 'nas_qnap' },
        'btac_box': { name: 'BTAC BOX', color: '#1f6ca5', type: 'btac_box' },
        'ups_1': { name: 'UPS 1 (INDUSTRONIC)', color: '#080c14', type: 'ups_1' },
        'ups_2': { name: 'UPS 2 (APC 3000)', color: '#1e222b', type: 'ups_2' },
        'servidor_2': { name: 'SERVIDOR 2 MODULAR', color: '#1f2937', type: 'servidor_2' },
        'switch_principal': { name: 'SWITCH PRINCIPAL', color: '#e2e8f0', type: 'switch_principal' },
        'switch_secundario': { name: 'SWITCH SECUNDARIO', color: '#e2e8f0', type: 'switch_secundario' },
        'gateway_unifi': { name: 'GATEWAY UNIFI UDM-PRO', color: '#f1f5f9', type: 'gateway_unifi' },
        'enlace_mcm': { name: 'ENLACE MCM', color: '#8b5cf6', type: 'enlace_mcm' },
        'router_cisco': { name: 'ROUTER CISCO 1841', color: '#94a3b8', type: 'router_cisco' },
        'routers_internet': { name: 'ROUTERS INTERNET', color: '#2563eb', type: 'routers_internet' },
        'router_totalplay': { name: 'ROUTER TOTALPLAY FIBERHOME', color: '#ffffff', type: 'router_totalplay' },
        'patch_panel': { name: 'PATCH PANEL CAT6', color: '#334155', type: 'patch_panel' },
        'nas_vw_qnap': { name: 'NAS VW QNAP', color: '#ffffff', type: 'nas_vw_qnap' },
        'nas_cupra_qnap': { name: 'NAS CUPRA QNAP', color: '#ffffff', type: 'nas_cupra_qnap' },
        'nas_buffalo': { name: 'NAS BUFFALO STORAGE', color: '#ffffff', type: 'nas_buffalo' },
        'servidor_ad': { name: 'SERVIDOR ACTIVE DIRECTORY', color: '#090d16', type: 'servidor_ad' },
        'servidor_gds': { name: 'SERVIDOR GDS CORE', color: '#090d16', type: 'servidor_gds' },
        'servidor_anterior': { name: 'SERVIDOR ANTERIOR', color: '#0f172a', type: 'servidor_anterior' },
        'barra_pdu': { name: 'BARRA PDU 220V', color: '#1e293b', type: 'barra_pdu' },
        'barra_tierra': { name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' },
        'ups': { name: 'UPS RESPALDO 10KVA', color: '#0f172a', type: 'ups' },
        'extintor': { name: 'EXTINTOR SOLKAFLAM', color: '#dc2626', type: 'extintor' }
    };

    const item = configEquipos[tipoTool] || { name: 'COMPONENTE PERSONALIZADO', color: '#1e293b', type: tipoTool };

    const rackKeys = getOrderedRackKeys();
    if (rackKeys.length === 0) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('warning', 'Sin Racks', 'Primero agrega un Rack en el Plano 2D.');
        } else {
            alert('Primero agrega un Rack en el Plano 2D.');
        }
        return;
    }

    const targetRackKey = rackKeys[0];
    if (!siteRacksData[targetRackKey]) {
        siteRacksData[targetRackKey] = {
            title: targetRackKey.toUpperCase(),
            items: [{ u: 1, name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' }]
        };
    }
    if (!Array.isArray(siteRacksData[targetRackKey].items)) {
        siteRacksData[targetRackKey].items = [];
    }

    const uHeight = getComponentUHeight(tipoTool, item.name);
    const targetU = findNextFreeSlot(targetRackKey, uHeight, null, tipoTool, item.name);

    if (targetU === null) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('danger', 'Rack Lleno', `No hay rendijas libres contiguas disponibles para ${item.name} (${uHeight}U).`);
        } else {
            alert(`No hay rendijas libres contiguas disponibles para ${item.name} (${uHeight}U).`);
        }
        return;
    }

    let slotPos = 'left';
    if (isHalfWidthItem(item.type, item.name)) {
        const existingPeer = siteRacksData[targetRackKey].items.find(it => 
            it && it.u === targetU && isHalfWidthItem(it.type, it.name)
        );
        if (existingPeer) {
            slotPos = (existingPeer.slotPos === 'left') ? 'right' : 'left';
        }
    }

    siteRacksData[targetRackKey].items.push({
        u: targetU,
        name: item.name,
        color: item.color,
        type: item.type,
        slotPos: slotPos
    });

    if (typeof mostrarNotificacionToast === 'function') {
        mostrarNotificacionToast('success', 'Equipo Montado', `"${item.name}" instalado en U${targetU}`);
    }

    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');
    sincronizarEscena3DDesde2D();
}

// GESTIÓN DE ELIMINACIÓN Y PAPELERA DE COMPONENTES
function eliminarComponenteSeleccionadoSite() {
    if (selectedMountedRackItem && selectedMountedRackItem.rackKey && selectedMountedRackItem.item) {
        const rackKey = selectedMountedRackItem.rackKey;
        const item = selectedMountedRackItem.item;
        const rackData = siteRacksData[rackKey];
        if (rackData && Array.isArray(rackData.items)) {
            const idx = rackData.items.indexOf(item);
            if (idx !== -1) {
                const removedName = item.name || 'Componente';
                rackData.items.splice(idx, 1);
                selectedMountedRackItem = null;
                if (typeof mostrarNotificacionToast === 'function') {
                    mostrarNotificacionToast('info', 'Equipo Eliminado', `Se desinstaló "${removedName}" del rack.`);
                }
                drawSite2DRackElevation('siteCanvas2D');
                drawSite2DRackElevation('siteCanvas2DConfig');
                sincronizarEscena3DDesde2D();
                return;
            }
        }
    }
    // Si no hay un equipo seleccionado por clic en el canvas, abrir el modal de selección
    abrirModalEliminarComponentesSite();
}

function abrirModalEliminarComponentesSite() {
    let modalEl = document.getElementById('modalEliminarComponenteSite');
    if (!modalEl) {
        const modalDiv = document.createElement('div');
        modalDiv.className = 'modal fade';
        modalDiv.id = 'modalEliminarComponenteSite';
        modalDiv.tabIndex = -1;
        modalDiv.setAttribute('aria-hidden', 'true');
        modalDiv.style.zIndex = '100000';
        modalDiv.innerHTML = `
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
        `;
        document.body.appendChild(modalDiv);
        modalEl = modalDiv;
    }

    const bodyEl = document.getElementById('modalEliminarComponenteSiteBody');
    if (!bodyEl) return;

    const rackKeys = getOrderedRackKeys();
    let totalItems = 0;
    let html = '';

    rackKeys.forEach(rackKey => {
        const rackData = siteRacksData[rackKey];
        if (!rackData || !Array.isArray(rackData.items) || rackData.items.length === 0) return;

        const sortedItems = [...rackData.items].sort((a, b) => (b.u || 0) - (a.u || 0));
        html += `
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between pb-1 border-bottom border-secondary border-opacity-30 mb-2">
                    <span class="fw-bold text-info small"><i class="bi bi-hdd-stack me-1"></i> ${rackData.title || rackKey.toUpperCase()}</span>
                    <span class="badge bg-secondary bg-opacity-25 text-light font-monospace" style="font-size: 0.65rem;">${sortedItems.length} equipos</span>
                </div>
                <div class="d-flex flex-column gap-1.5">
        `;

        sortedItems.forEach(item => {
            totalItems++;
            const uHeight = getComponentUHeight(item.type, item.name);
            const uLabel = uHeight > 1 ? `U${item.u - uHeight + 1}-U${item.u}` : `U${item.u}`;
            html += `
                <div class="d-flex align-items-center justify-content-between p-2 rounded-2 border border-secondary border-opacity-25" style="background: rgba(15, 23, 42, 0.6);">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary bg-opacity-25 text-info font-monospace" style="font-size: 0.7rem;">${uLabel}</span>
                        <div class="d-flex flex-column lh-1">
                            <span class="fw-semibold text-white small" style="font-size: 0.76rem;">${item.name || 'Componente'}</span>
                            <span class="text-secondary" style="font-size: 0.62rem;">${uHeight}U &bull; Tipo: ${item.type || 'rackmount'}</span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-xs btn-outline-danger px-2 py-1 rounded-pill" onclick="eliminarComponenteEspecificoSite('${rackKey}', ${item.u})" title="Retirar este equipo del rack">
                        <i class="bi bi-trash3-fill me-1"></i> Retirar
                    </button>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    });

    if (totalItems === 0) {
        html = `
            <div class="text-center py-4 text-secondary">
                <i class="bi bi-check2-circle fs-1 text-success d-block mb-2"></i>
                <span class="small">No hay equipos montados en los racks actualmente.</span>
            </div>
        `;
    }

    bodyEl.innerHTML = html;

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();
    } else {
        $(modalEl).modal('show');
    }
}

function eliminarComponenteEspecificoSite(rackKey, targetU) {
    if (!siteRacksData[rackKey] || !Array.isArray(siteRacksData[rackKey].items)) return;
    const idx = siteRacksData[rackKey].items.findIndex(it => it.u === targetU);
    if (idx !== -1) {
        const removed = siteRacksData[rackKey].items.splice(idx, 1)[0];
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('info', 'Equipo Retirado', `Se desinstaló "${removed.name || 'Componente'}" del rack.`);
        }
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
        sincronizarEscena3DDesde2D();
        abrirModalEliminarComponentesSite();
    }
}

function vaciarTodosLosRacksSite() {
    if (!confirm('¿Estás seguro de que deseas retirar todos los equipos de todos los racks?')) return;
    const rackKeys = getOrderedRackKeys();
    rackKeys.forEach(key => {
        if (siteRacksData[key]) {
            siteRacksData[key].items = [];
        }
    });
    if (typeof mostrarNotificacionToast === 'function') {
        mostrarNotificacionToast('warning', 'Racks Vaciados', 'Se han retirado todos los componentes de los racks.');
    }
    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');
    sincronizarEscena3DDesde2D();

    const modalEl = document.getElementById('modalEliminarComponenteSite');
    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const bsModal = bootstrap.Modal.getInstance(modalEl);
        if (bsModal) bsModal.hide();
    }
}

function onSiteTrashDrop(event) {
    event.preventDefault();
    currentPaletteDraggedTool = null;
    currentPaletteDragHover = null;
    if (draggedMountedItem) {
        const removedName = draggedMountedItem.item.name || 'Componente';
        siteRacksData[draggedMountedItem.sourceRackKey].items.splice(draggedMountedItem.itemIndex, 1);
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('info', 'Equipo Eliminado', `Se desinstaló "${removedName}" del rack.`);
        }
        draggedMountedItem = null;
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
        sincronizarEscena3DDesde2D();
    }
}


// THREE.JS SCENE INITIALIZER
function initSite3DScene(targetContainerId) {
    if (typeof THREE === 'undefined') return;

    const containerId = targetContainerId || active3DContainerId;
    active3DContainerId = containerId;
    const container = document.getElementById(containerId);
    if (!container) return;

    let width = container.clientWidth || (container.getBoundingClientRect ? container.getBoundingClientRect().width : 0) || 1000;
    let height = container.clientHeight || (container.getBoundingClientRect ? container.getBoundingClientRect().height : 0) || 520;
    if (width < 100) width = 1000;
    if (height < 100) height = 520;

    if (!isSite3DInit || container.children.length === 0 || !container.querySelector('canvas')) {
        isSite3DInit = true;

        siteScene3D = new THREE.Scene();
        siteScene3D.background = new THREE.Color(0x081325);
        siteScene3D.fog = new THREE.FogExp2(0x081325, 0.008);

        siteCamera3D = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
        posicionarCamaraAlEntrar3D();

        siteRenderer3D = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
        siteRenderer3D.setSize(width, height);
        siteRenderer3D.setPixelRatio(Math.min(window.devicePixelRatio, 2));

        const overlay = container.querySelector('#site3DControlOverlay');
        container.innerHTML = '';
        if (overlay) container.appendChild(overlay);
        container.appendChild(siteRenderer3D.domElement);

        if (typeof THREE.OrbitControls !== 'undefined') {
            siteControls3D = new THREE.OrbitControls(siteCamera3D, siteRenderer3D.domElement);
            siteControls3D.enabled = false; // Deshabilitado para ceder control a los controles de videojuego (FPS / Head Look)
            siteControls3D.target.set(0, 8, 0);
        }

        if (typeof THREE.TransformControls !== 'undefined') {
            transformControls3D = new THREE.TransformControls(siteCamera3D, siteRenderer3D.domElement);
            transformControls3D.size = 0.75;
            transformControls3D.addEventListener('dragging-changed', function (event) {
                if (siteControls3D) siteControls3D.enabled = false;
            });
            transformControls3D.addEventListener('change', function () {
                if (selectedObject3D) {
                    if (boxHelper3D) actualizarBoxHelperSeguro(boxHelper3D, selectedObject3D);
                    sincronizarPanelControles3D();

                    if (transformControls3D.dragging) {
                        sincronizar2DDesde3D(selectedObject3D);
                    }
                }
            });
            siteScene3D.add(transformControls3D);
        }

        raycaster3D = new THREE.Raycaster();
        mouseVector3D = new THREE.Vector2();
        siteRenderer3D.domElement.addEventListener('pointerdown', onSite3DCanvasClick);
        setupVideoGameControls3D(siteRenderer3D.domElement);

        boxHelper3D = new THREE.BoxHelper(new THREE.Object3D(), 0x38bdf8);
        siteScene3D.add(boxHelper3D);
        boxHelper3D.visible = false;

        const ambientLight = new THREE.AmbientLight(0xffffff, 1.2);
        siteScene3D.add(ambientLight);

        const frontLight = new THREE.DirectionalLight(0xffffff, 1.2);
        frontLight.position.set(12, 25, 25);
        siteScene3D.add(frontLight);

        // SPOTLIGHTS ESTILO VIDEOJUEGO SOBRE CADA RACK
        [-8, 0, 8].forEach((posX, idx) => {
            const spotLight = new THREE.SpotLight(idx === 0 ? 0x00f2fe : (idx === 1 ? 0x38bdf8 : 0xc084fc), 2.5);
            spotLight.position.set(posX, 24, 10);
            spotLight.target.position.set(posX, 8, 0);
            spotLight.angle = Math.PI / 4;
            spotLight.penumbra = 0.5;
            siteScene3D.add(spotLight);
            siteScene3D.add(spotLight.target);
        });

        const gridHelper = new THREE.GridHelper(50, 25, 0x00f2fe, 0x1e293b);
        gridHelper.position.y = 0;
        siteScene3D.add(gridHelper);

        // PISO METALICO REFLEJANTE ESTILO DATACENTER
        const floorMat = new THREE.MeshStandardMaterial({ color: 0x061121, metalness: 0.85, roughness: 0.15 });
        const floorGeo = new THREE.PlaneGeometry(50, 50);
        const floor = new THREE.Mesh(floorGeo, floorMat);
        floor.rotation.x = -Math.PI / 2;
        floor.position.y = -0.05;
        siteScene3D.add(floor);

        // PARED TRASERA TIPO SALA DE SERVIDORES SCI-FI CON LUZ NEÓN
        const wallMat = new THREE.MeshStandardMaterial({ color: 0x0a1628, metalness: 0.7, roughness: 0.4 });
        const wallGeo = new THREE.PlaneGeometry(50, 24);
        const backWall = new THREE.Mesh(wallGeo, wallMat);
        backWall.position.set(0, 12, -24);
        siteScene3D.add(backWall);

        const neonStripMat = new THREE.MeshBasicMaterial({ color: 0x00f2fe });
        const neonGeo = new THREE.BoxGeometry(50, 0.2, 0.2);
        const neonStrip = new THREE.Mesh(neonGeo, neonStripMat);
        neonStrip.position.set(0, 0.1, -23.9);
        siteScene3D.add(neonStrip);

        crearEscalerillaCabecera3D();

        // SINCRONIZAR Y GENERAR ESCENA 3D DIRECTAMENTE DESDE LOS COMPONENTES DE PLANO 2D
        sincronizarEscena3DDesde2D();
        posicionarCamaraAlEntrar3D();

        // EVENTOS DE MOUSE & TECLADO TIPO VIDEOJUEGO (HOVER HUD, FLECHAS NAVEGACIÓN & SELECCIÓN)
        siteRenderer3D.domElement.addEventListener('pointermove', onSite3DCanvasHover);
        siteRenderer3D.domElement.addEventListener('pointerleave', onSite3DCanvasLeave);
        siteRenderer3D.domElement.addEventListener('mouseleave', onSite3DCanvasLeave);
        siteRenderer3D.domElement.addEventListener('dblclick', onSite3DCanvasDblClick);
        window.addEventListener('keydown', onSite3DKeyDown);

        window.addEventListener('resize', onSiteWindowResize);
    } else {
        onSiteWindowResize();
    }

    animateSite3D();
}

// CONTROL DE NAVEGACIÓN CON FLECHAS Y TECLAS WASD EN LA SALA 3D
function onSite3DKeyDown(event) {
    // Compatibilidad retroactiva
}

// =========================================================================
// CONTROLES DE NAVEGACIÓN ESTILO VIDEOJUEGO (FPS / VISTA DE LA CARA)
// =========================================================================
let siteCamYaw = 0;
let siteCamPitch = -0.22;
let isSite3DMouseDragging = false;
let lastSite3DMouseX = 0;
let lastSite3DMouseY = 0;
let isSite3DPointerLocked = false;
const site3DKeysPressed = new Set();

function aplicarRotacionCamaraVideojuego() {
    if (!siteCamera3D) return;
    siteCamera3D.rotation.order = 'YXZ';
    siteCamera3D.rotation.y = siteCamYaw;
    siteCamera3D.rotation.x = siteCamPitch;
    siteCamera3D.rotation.z = 0;
}

function actualizarMovimientoCamaraVideojuego() {
    if (!siteCamera3D) return;
    if (typeof modoSiteActual !== 'undefined' && modoSiteActual !== '3d') return;
    if (site3DKeysPressed.size === 0) return;

    const moveSpeed = 0.38;
    const forward = new THREE.Vector3(-Math.sin(siteCamYaw), 0, -Math.cos(siteCamYaw)).normalize();
    const right = new THREE.Vector3(Math.cos(siteCamYaw), 0, -Math.sin(siteCamYaw)).normalize();

    // W o Flecha Arriba: Avanzar hacia donde mira la cara
    if (site3DKeysPressed.has('w') || site3DKeysPressed.has('arrowup')) {
        siteCamera3D.position.addScaledVector(forward, moveSpeed);
    }
    // S o Flecha Abajo: Retroceder
    if (site3DKeysPressed.has('s') || site3DKeysPressed.has('arrowdown')) {
        siteCamera3D.position.addScaledVector(forward, -moveSpeed);
    }
    // A o Flecha Izquierda: Desplazamiento lateral (strafe) izquierda
    if (site3DKeysPressed.has('a') || site3DKeysPressed.has('arrowleft')) {
        siteCamera3D.position.addScaledVector(right, -moveSpeed);
    }
    // D o Flecha Derecha: Desplazamiento lateral (strafe) derecha
    if (site3DKeysPressed.has('d') || site3DKeysPressed.has('arrowright')) {
        siteCamera3D.position.addScaledVector(right, moveSpeed);
    }
    // Letra Q: Bajar
    if (site3DKeysPressed.has('q')) {
        siteCamera3D.position.y = Math.max(0.8, siteCamera3D.position.y - moveSpeed);
    }
    // Letra E o Barra Espaciadora: Subir
    if (site3DKeysPressed.has('e') || site3DKeysPressed.has(' ')) {
        siteCamera3D.position.y = Math.min(23.5, siteCamera3D.position.y + moveSpeed);
    }

    // Delimitar dentro del área de la sala 3D (-23.8 a +23.8)
    siteCamera3D.position.x = Math.max(-23.8, Math.min(23.8, siteCamera3D.position.x));
    siteCamera3D.position.z = Math.max(-23.8, Math.min(23.8, siteCamera3D.position.z));
}

function setupVideoGameControls3D(canvasElement) {
    if (!canvasElement) return;

    // Pointerdown para arrastrar la vista de la cara
    canvasElement.addEventListener('pointerdown', (e) => {
        if (transformControls3D && transformControls3D.dragging) return;
        isSite3DMouseDragging = true;
        lastSite3DMouseX = e.clientX;
        lastSite3DMouseY = e.clientY;
        canvasElement.style.cursor = 'grab';
    });

    // Pointermove para girar la cabeza con el mouse ("vista de la cara")
    window.addEventListener('pointermove', (e) => {
        if (isSite3DPointerLocked) {
            const deltaX = e.movementX || 0;
            const deltaY = e.movementY || 0;
            const sensitivity = 0.0028;
            siteCamYaw -= deltaX * sensitivity;
            siteCamPitch -= deltaY * sensitivity;
            siteCamPitch = Math.max(-Math.PI / 2.05, Math.min(Math.PI / 2.05, siteCamPitch));
            aplicarRotacionCamaraVideojuego();
            return;
        }

        if (isSite3DMouseDragging) {
            const deltaX = e.clientX - lastSite3DMouseX;
            const deltaY = e.clientY - lastSite3DMouseY;
            lastSite3DMouseX = e.clientX;
            lastSite3DMouseY = e.clientY;
            const sensitivity = 0.0035;
            siteCamYaw -= deltaX * sensitivity;
            siteCamPitch -= deltaY * sensitivity;
            siteCamPitch = Math.max(-Math.PI / 2.05, Math.min(Math.PI / 2.05, siteCamPitch));
            aplicarRotacionCamaraVideojuego();
        }
    });

    window.addEventListener('pointerup', () => {
        isSite3DMouseDragging = false;
        if (canvasElement && !isSite3DPointerLocked) {
            canvasElement.style.cursor = 'default';
        }
    });

    // Rueda del mouse: avanzar/retroceder en la dirección que mira la cara
    canvasElement.addEventListener('wheel', (e) => {
        e.preventDefault();
        if (!siteCamera3D) return;
        const forward = new THREE.Vector3(-Math.sin(siteCamYaw), 0, -Math.cos(siteCamYaw)).normalize();
        const zoomStep = e.deltaY > 0 ? -1.6 : 1.6;
        siteCamera3D.position.addScaledVector(forward, zoomStep);
        siteCamera3D.position.x = Math.max(-23.8, Math.min(23.8, siteCamera3D.position.x));
        siteCamera3D.position.z = Math.max(-23.8, Math.min(23.8, siteCamera3D.position.z));
    }, { passive: false });

    // Listener de Pointer Lock (Modo FPS inmersivo)
    document.addEventListener('pointerlockchange', () => {
        isSite3DPointerLocked = (document.pointerLockElement === canvasElement);
        const lockBtn = document.getElementById('btnSite3DPointerLock');
        if (lockBtn) {
            if (isSite3DPointerLocked) {
                lockBtn.innerHTML = '<i class="bi bi-controller me-1"></i> Modo FPS Activo (ESC para salir)';
                lockBtn.classList.remove('btn-outline-warning');
                lockBtn.classList.add('btn-warning', 'text-dark');
            } else {
                lockBtn.innerHTML = '<i class="bi bi-controller me-1"></i> Modo Videojuego (Bloquear Mouse)';
                lockBtn.classList.remove('btn-warning', 'text-dark');
                lockBtn.classList.add('btn-outline-warning');
            }
        }
    });
}

function togglePointerLockSite3D() {
    if (!siteRenderer3D || !siteRenderer3D.domElement) return;
    if (document.pointerLockElement === siteRenderer3D.domElement) {
        document.exitPointerLock();
    } else {
        siteRenderer3D.domElement.requestPointerLock();
    }
}

// CONTROL DE TECLADO CONTINUO (WASD + Q/E/Espacio)
window.addEventListener('keydown', (event) => {
    const activeEl = document.activeElement;
    if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT')) {
        return;
    }
    const key = event.key.toLowerCase();
    if (['w', 'a', 's', 'd', 'q', 'e', ' ', 'arrowup', 'arrowdown', 'arrowleft', 'arrowright'].includes(key)) {
        site3DKeysPressed.add(key);
        if (typeof modoSiteActual !== 'undefined' && modoSiteActual === '3d') {
            event.preventDefault();
        }
    }
});

window.addEventListener('keyup', (event) => {
    site3DKeysPressed.delete(event.key.toLowerCase());
});

window.addEventListener('blur', () => {
    site3DKeysPressed.clear();
});

// POSICIONAMIENTO INTELIGENTE DE CÁMARA AL ENTRAR A LA VISTA 3D:
// Si el plano contiene una puerta, sitúa la cámara en la puerta mirando al interior del datacenter.
// Si no tiene puerta, la sitúa en el ángulo de perspectiva clásico general.
function posicionarCamaraAlEntrar3D() {
    if (!siteCamera3D) return;

    // Buscar si existe alguna puerta en el plano (2D o FloorPlan)
    let objetos = [];
    if (window.site2DObjects && Array.isArray(window.site2DObjects)) {
        objetos = window.site2DObjects;
    } else if (typeof siteFloorPlanObjects !== 'undefined' && Array.isArray(siteFloorPlanObjects)) {
        objetos = siteFloorPlanObjects;
    }

    const puerta = objetos.find(o => o && (o.type === 'puerta' || o.type === 'puerta_deslizable' || String(o.id).toLowerCase().includes('puerta')));

    if (puerta) {
        // Coordenadas 3D exactas calculadas de la puerta
        const doorW = puerta.w || 120;
        const doorH = puerta.h || 24;
        const doorX = pos2DTo3DX(puerta.x, doorW);
        const doorZ = pos2DTo3DZ(puerta.y, doorH);

        // Vector director hacia el centro de la sala (0, 0)
        const dx = 0 - doorX;
        const dz = 0 - doorZ;
        const dist = Math.hypot(dx, dz) || 1;
        const dirX = dx / dist;
        const dirZ = dz / dist;

        // Situar al usuario en el umbral de la puerta a altura de ojos humanos (Y = 8.5)
        // adelantado 2.2 unidades hacia el interior de la sala para apreciar el cuarto
        const camX = Math.max(-23.5, Math.min(23.5, doorX + dirX * 2.2));
        const camZ = Math.max(-23.5, Math.min(23.5, doorZ + dirZ * 2.2));
        const camY = 8.5;

        siteCamera3D.position.set(camX, camY, camZ);

        // Orientar la vista ("la cara") directamente hacia los bastidores y equipos
        siteCamYaw = Math.atan2(-dx, -dz);
        siteCamPitch = -0.06; // Mirada al frente a nivel visual de los racks
        aplicarRotacionCamaraVideojuego();

        if (siteControls3D) {
            siteControls3D.target.set(0, 8, 0);
        }
    } else {
        // Ángulo de perspectiva general clásico por defecto
        siteCamera3D.position.set(0, 13, 27);
        siteCamYaw = 0;
        siteCamPitch = -0.22;
        aplicarRotacionCamaraVideojuego();

        if (siteControls3D) {
            siteControls3D.target.set(0, 8, 0);
        }
    }
}

// REINICIAR CÁMARA 3D Y MIRADA AL ESTADO INICIAL
function resetSite3DCamera() {
    posicionarCamaraAlEntrar3D();
}

function crearEscalerillaCabecera3D() {
    if (typeof THREE === 'undefined' || !siteScene3D) return;
    const group = new THREE.Group();
    const trayMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.8 });

    const barGeo = new THREE.BoxGeometry(32, 0.2, 0.4);
    const bar1 = new THREE.Mesh(barGeo, trayMat); bar1.position.set(0, 16.6, -0.4); group.add(bar1);
    const bar2 = new THREE.Mesh(barGeo, trayMat); bar2.position.set(0, 16.6, 0.4); group.add(bar2);

    const cableBlueMat = new THREE.MeshStandardMaterial({ color: 0x2563eb });
    const cableYellowMat = new THREE.MeshStandardMaterial({ color: 0xeab308 });

    for (let c = 0; c < 4; c++) {
        const cGeo = new THREE.CylinderGeometry(0.1, 0.1, 30, 12);
        const cMesh = new THREE.Mesh(cGeo, (c % 2 === 0) ? cableBlueMat : cableYellowMat);
        cMesh.rotation.z = Math.PI / 2;
        cMesh.position.set(0, 16.8, -0.2 + (c * 0.15));
        group.add(cMesh);
    }

    siteScene3D.add(group);
}

function onSite3DCanvasClick(event) {
    if (typeof THREE === 'undefined' || !siteRenderer3D || !siteCamera3D || !siteScene3D) return;
    // Si estamos en modo de visualización del SITE (sin modificar nada en 3D), ignorar selección y gizmos
    if (typeof modoSiteActual !== 'undefined' && modoSiteActual === '3d') {
        return;
    }
    if (transformControls3D && transformControls3D.dragging) return;

    const rect = siteRenderer3D.domElement.getBoundingClientRect();
    mouseVector3D.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    mouseVector3D.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

    raycaster3D.setFromCamera(mouseVector3D, siteCamera3D);
    const intersects = raycaster3D.intersectObjects(siteScene3D.children, true);

    if (intersects.length > 0) {
        let hitObj = intersects[0].object;
        if (hitObj === boxHelper3D || hitObj.type === 'GridHelper' || hitObj.geometry?.type === 'PlaneGeometry' || hitObj.parent?.type === 'TransformControls') {
            return;
        }

        let rootGroup = hitObj;
        while (rootGroup.parent && rootGroup.parent !== siteScene3D) {
            rootGroup = rootGroup.parent;
        }

        if (rootGroup && rootGroup !== siteScene3D) {
            seleccionarObjeto3D(rootGroup);
        }
    }
}

function actualizarBoxHelperSeguro(boxHelper, targetObject) {
    if (!boxHelper || !targetObject || typeof THREE === 'undefined') return;

    const box = new THREE.Box3();
    let hasMesh = false;

    targetObject.traverse((child) => {
        if (child.isMesh && !child.userData?.isEffectMesh && child.type !== 'LineSegments' && child !== boxHelper) {
            if (!child.geometry.boundingBox) child.geometry.computeBoundingBox();
            const childBox = child.geometry.boundingBox.clone();
            childBox.applyMatrix4(child.matrixWorld);
            if (!hasMesh) {
                box.copy(childBox);
                hasMesh = true;
            } else {
                box.union(childBox);
            }
        }
    });

    if (hasMesh) {
        boxHelper.box = box;
        boxHelper.update();
    } else {
        boxHelper.setFromObject(targetObject);
    }
}

function seleccionarObjeto3D(objGroup) {
    selectedObject3D = objGroup;

    if (boxHelper3D) {
        actualizarBoxHelperSeguro(boxHelper3D, selectedObject3D);
        boxHelper3D.visible = true;
    }

    if (transformControls3D) {
        transformControls3D.attach(selectedObject3D);
        transformControls3D.setMode('translate');
    }

    sincronizarPanelControles3D();
}

function sincronizarPanelControles3D() {
    if (!selectedObject3D) return;

    const overlay = document.getElementById('site3DControlOverlay');
    const labelName = document.getElementById('site3DSelectedName');
    const sliderScale = document.getElementById('sliderScale3D');
    const labelScale = document.getElementById('labelScaleValue');
    const inputX = document.getElementById('inputScaleX3D');
    const inputY = document.getElementById('inputScaleY3D');
    const inputZ = document.getElementById('inputScaleZ3D');
    const sliderRotY = document.getElementById('sliderRotY3D');
    const labelRotY = document.getElementById('labelRotYValue');

    if (overlay) overlay.style.display = 'block';
    if (labelName) labelName.textContent = selectedObject3D.userData?.name || selectedObject3D.name || 'Componente 3D';

    const sx = selectedObject3D.scale.x || 1.0;
    const sy = selectedObject3D.scale.y || 1.0;
    const sz = selectedObject3D.scale.z || 1.0;
    const avgScale = (sx + sy + sz) / 3;

    if (sliderScale) sliderScale.value = avgScale;
    if (labelScale) labelScale.textContent = avgScale.toFixed(1) + 'x';

    if (inputX) inputX.value = sx.toFixed(1);
    if (inputY) inputY.value = sy.toFixed(1);
    if (inputZ) inputZ.value = sz.toFixed(1);

    if (typeof THREE !== 'undefined') {
        const degY = Math.round(THREE.MathUtils.radToDeg(selectedObject3D.rotation.y || 0)) % 360;
        const normalizedDegY = degY < 0 ? degY + 360 : degY;

        if (sliderRotY) sliderRotY.value = normalizedDegY;
        if (labelRotY) labelRotY.textContent = normalizedDegY + '°';
    }
}

function deseleccionarObjeto3D() {
    selectedObject3D = null;
    if (boxHelper3D) boxHelper3D.visible = false;
    if (transformControls3D) transformControls3D.detach();

    const overlay = document.getElementById('site3DControlOverlay');
    if (overlay) overlay.style.display = 'none';
}

function set3DTransformMode(mode) {
    if (transformControls3D) {
        transformControls3D.setMode(mode);
    }

    const btnTrans = document.getElementById('btn3DModeTranslate');
    const btnScale = document.getElementById('btn3DModeScale');
    const btnRot = document.getElementById('btn3DModeRotate');

    [btnTrans, btnScale, btnRot].forEach(btn => {
        if (btn) btn.classList.remove('active', 'btn-info', 'btn-warning', 'btn-purple');
    });

    if (mode === 'translate' && btnTrans) btnTrans.classList.add('active');
    else if (mode === 'scale' && btnScale) btnScale.classList.add('active');
    else if (mode === 'rotate' && btnRot) btnRot.classList.add('active');
}

function cambiarEscala3D(val) {
    const scaleVal = parseFloat(val);
    if (selectedObject3D) {
        selectedObject3D.scale.set(scaleVal, scaleVal, scaleVal);
        if (boxHelper3D) actualizarBoxHelperSeguro(boxHelper3D, selectedObject3D);
        sincronizarPanelControles3D();
    }
}

function actualizarEscalaXYZ3D() {
    if (!selectedObject3D) return;
    const x = parseFloat(document.getElementById('inputScaleX3D')?.value || 1.0);
    const y = parseFloat(document.getElementById('inputScaleY3D')?.value || 1.0);
    const z = parseFloat(document.getElementById('inputScaleZ3D')?.value || 1.0);

    selectedObject3D.scale.set(x, y, z);
    if (boxHelper3D) actualizarBoxHelperSeguro(boxHelper3D, selectedObject3D);
    sincronizarPanelControles3D();
}

function cambiarRotacionY3D(valDeg) {
    if (!selectedObject3D || typeof THREE === 'undefined') return;
    const deg = parseFloat(valDeg);
    selectedObject3D.rotation.y = THREE.MathUtils.degToRad(deg);
    if (boxHelper3D) actualizarBoxHelperSeguro(boxHelper3D, selectedObject3D);

    const labelRotY = document.getElementById('labelRotYValue');
    if (labelRotY) labelRotY.textContent = Math.round(deg) + '°';
}

function eliminarObjeto3DSeleccionado() {
    if (selectedObject3D && siteScene3D) {
        siteScene3D.remove(selectedObject3D);
        deseleccionarObjeto3D();
    }
}

let animated3DLeds = [];

function crearRackCompleto3D(posX, posY, posZ, rackData, fpObj) {
    if (typeof THREE === 'undefined' || !rackData) return null;

    const title = rackData.title || fpObj?.name || 'RACK 42U BASTIDOR';
    const items = Array.isArray(rackData.items) ? rackData.items : [];

    const rackGroup = new THREE.Group();
    rackGroup.position.set(posX, posY, posZ);
    rackGroup.userData = {
        id: fpObj?.id || rackData.id || title,
        name: title,
        type: 'rack',
        isRack: true,
        isEquip: true
    };

    const metalMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.9, roughness: 0.2 });

    const baseGeo = new THREE.BoxGeometry(3.8, 0.4, 4.0);
    const baseMesh = new THREE.Mesh(baseGeo, metalMat);
    baseMesh.position.y = 0.2;
    rackGroup.add(baseMesh);

    const topMesh = new THREE.Mesh(baseGeo, metalMat);
    topMesh.position.y = 16.2;
    rackGroup.add(topMesh);

    // VENTILADORES EN GABINETE SUPERIOR DEL RACK
    const fanMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.8 });
    for (let f = 0; f < 3; f++) {
        const fanGeo = new THREE.CylinderGeometry(0.35, 0.35, 0.1, 16);
        const fan = new THREE.Mesh(fanGeo, fanMat);
        fan.position.set(-1.0 + (f * 1.0), 16.45, 0);
        rackGroup.add(fan);
    }

    const postGeo = new THREE.BoxGeometry(0.25, 16, 0.25);
    const postFL = new THREE.Mesh(postGeo, metalMat); postFL.position.set(-1.7, 8.2, 1.8); rackGroup.add(postFL);
    const postFR = new THREE.Mesh(postGeo, metalMat); postFR.position.set(1.7, 8.2, 1.8); rackGroup.add(postFR);
    const postBL = new THREE.Mesh(postGeo, metalMat); postBL.position.set(-1.7, 8.2, -1.8); rackGroup.add(postBL);
    const postBR = new THREE.Mesh(postGeo, metalMat); postBR.position.set(1.7, 8.2, -1.8); rackGroup.add(postBR);

    // LUZ INTERNA NEÓN DENTRO DEL RACK
    const rackPointLight = new THREE.PointLight(0x00f2fe, 1.2, 7);
    rackPointLight.position.set(0, 8, 0);
    rackGroup.add(rackPointLight);

    // PUERTA DE CRISTAL ACRÍLICO ABRIBLE CON BISAGRA
    const doorGroup = new THREE.Group();
    doorGroup.position.set(-1.8, 0, 1.95);
    doorGroup.userData = { isDoor: true, isOpen: false, rackTitle: title };

    const doorGlassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.25, metalness: 0.9, roughness: 0.1 });
    const doorPanelGeo = new THREE.BoxGeometry(3.6, 15.6, 0.06);
    const doorPanel = new THREE.Mesh(doorPanelGeo, doorGlassMat);
    doorPanel.position.set(1.8, 8.2, 0);
    doorGroup.add(doorPanel);

    const handleGeo = new THREE.BoxGeometry(0.1, 1.2, 0.15);
    const handleMat = new THREE.MeshStandardMaterial({ color: 0x00f2fe, metalness: 0.9 });
    const handle = new THREE.Mesh(handleGeo, handleMat);
    handle.position.set(3.4, 8.2, 0.08);
    doorGroup.add(handle);

    rackGroup.add(doorGroup);

    items.forEach(item => {
        if (item && item.u !== undefined) {
            const slotY = 0.6 + (item.u * 0.72);
            montarComponenteEspecifico3D(rackGroup, item.type || 'equipo', item.name || 'EQUIPO', slotY, title, item.u, item.slotPos, item);
        }
    });

    if (siteScene3D) siteScene3D.add(rackGroup);
    return rackGroup;
}

// MONTAJE DE COMPONENTES 3D CON TEXTURA FRONTAL DETALLADA Y LEDS ANIMADOS
function montarComponenteEspecifico3D(group, tipoTool, nombre, y, rackTitle, slotU, slotPos = 'left', itemObj = null) {
    if (typeof THREE === 'undefined') return;

    const textureFront = crearTexturaFrontalCanvas(tipoTool, nombre);
    const uHeight = getComponentUHeight(tipoTool, nombre);
    const h = uHeight === 4 ? 2.6 : (uHeight === 3 ? 2.0 : (uHeight === 2 ? 1.4 : 0.7));
    const centerY = y - ((uHeight - 1) * 0.72 / 2);
    const isHalf = isHalfWidthItem(tipoTool, nombre);

    let bodyColor = 0x1e293b;
    if (tipoTool.includes('switch') || tipoTool === 'gateway_unifi' || tipoTool === 'udm_pro' || tipoTool.includes('qnap')) {
        bodyColor = 0xd8dde6; // Carcasa plateada anodizada UniFi / QNAP 1U
    } else if (tipoTool.includes('buffalo')) {
        bodyColor = 0x334155; // Gunmetal grey metálico TeraStation
    } else if (tipoTool.includes('datto')) {
        bodyColor = 0x0c1421; // Deep dark navy-slate Datto SIRIS
    } else if (tipoTool.includes('ups_1') || tipoTool.includes('industronic')) {
        bodyColor = 0x080c14; // Industronic black obsidiana
    } else if (tipoTool.includes('ups_2') || tipoTool.includes('apc')) {
        bodyColor = 0x1e222b; // APC Smart-UPS dark anthracite
    } else if (tipoTool === 'servidor_2' || tipoTool.includes('servidor_2') || tipoTool.includes('servidor 2')) {
        bodyColor = 0x1f2937; // Servidor 2 modular dark slate
    } else if (tipoTool === 'fortinet' || tipoTool === 'router_totalplay') {
        bodyColor = 0xf8fafc;
    } else if (tipoTool === 'ont') {
        bodyColor = 0x080d1a; // Carcasa negra brillante acrílica de módem ONT Infinitum
    } else if (tipoTool.includes('servidor')) {
        bodyColor = 0x0a0e17;
    } else if (tipoTool === 'btac_box' || tipoTool.includes('btac')) {
        bodyColor = 0x05070a; // Carcasa negra profunda con marco azul
    } else if (tipoTool === 'enlace_mcm') {
        bodyColor = 0x7e22ce;
    } else if (tipoTool === 'router_cisco' || tipoTool === 'routers_internet') {
        bodyColor = 0x334155;
    } else if (tipoTool === 'barra_tierra') {
        bodyColor = 0x22c55e;
    }

    const bodyMat = new THREE.MeshStandardMaterial({ color: bodyColor, metalness: 0.6, roughness: 0.3 });
    const frontMat = new THREE.MeshStandardMaterial({ map: textureFront, metalness: 0.2, roughness: 0.4 });

    const materials = [
        bodyMat,  // +X (Derecha)
        bodyMat,  // -X (Izquierda)
        bodyMat,  // +Y (Arriba)
        bodyMat,  // -Y (Abajo)
        frontMat, // +Z (Frontal con Textura Diseñada)
        bodyMat   // -Z (Atrás)
    ];

    // OREJAS DE MONTAJE METÁLICAS LATERALES 19" (RACK EARS 3D) - Solo equipos de ancho completo
    const earMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.9, roughness: 0.2 });
    if (!isHalf && tipoTool !== 'servidor_anterior' && tipoTool !== 'barra_tierra' && tipoTool !== 'extintor') {
        const earGeo = new THREE.BoxGeometry(0.25, h, 0.08);
        const earL = new THREE.Mesh(earGeo, earMat); earL.position.set(-1.72, centerY, 1.6); group.add(earL);
        const earR = new THREE.Mesh(earGeo, earMat); earR.position.set(1.72, centerY, 1.6); group.add(earR);
    }

    // BANDEJA METÁLICA DE SOPORTE PARA EQUIPOS DE MEDIA ANCHURA
    if (isHalf) {
        let hasShelf = false;
        const targetShelfY = centerY - (h / 2) + 0.04;
        group.children.forEach(c => {
            if (c.userData && c.userData.isShelf && Math.abs(c.position.y - targetShelfY) < 0.1) {
                hasShelf = true;
            }
        });
        if (!hasShelf) {
            const shelfGeo = new THREE.BoxGeometry(3.35, 0.08, 3.25);
            const shelfMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.3 });
            const shelf = new THREE.Mesh(shelfGeo, shelfMat);
            shelf.position.set(0, targetShelfY, 0);
            shelf.userData = { isShelf: true };
            group.add(shelf);
        }
    }

    // SI ES SERVIDOR ANTERIOR: CONSTRUIR 2 TORRES VERTICALES 3D AL LADO DE LA OTRA
    if (tipoTool === 'servidor_anterior') {
        const towerGeo = new THREE.BoxGeometry(1.4, 3.8, 3.0);
        const towerMat = new THREE.MeshStandardMaterial({ color: 0x090d16, metalness: 0.8, roughness: 0.3 });
        const badgeMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, metalness: 0.9 });
        const ledMat = new THREE.MeshBasicMaterial({ color: 0x22c55e });

        const invData = (itemObj && itemObj.inventoryData) 
            ? itemObj.inventoryData 
            : ((itemObj && itemObj.inventoryKey && window.equiposInventarioMap) 
                ? window.equiposInventarioMap[itemObj.inventoryKey] 
                : null);

        for (let t = 0; t < 2; t++) {
            const tower = new THREE.Mesh(towerGeo, towerMat);
            tower.position.set(-0.9 + (t * 1.8), y + 1.2, 0);
            tower.userData = { 
                isEquip: true, 
                name: (invData && invData.label) ? invData.label : 'Servidor Anterior (Torre)', 
                type: tipoTool, 
                rack: rackTitle || 'RACK 3', 
                u: slotU || 1,
                inventoryData: invData,
                inventoryKey: (itemObj ? (itemObj.inventoryKey || (invData ? invData.key : null)) : null),
                itemRef: itemObj
            };
            group.add(tower);

            // Rejilla frontal y botón de encendido 3D
            const grillGeo = new THREE.BoxGeometry(1.2, 2.0, 0.05);
            const grillMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.5 });
            const grill = new THREE.Mesh(grillGeo, grillMat);
            grill.position.set(-0.9 + (t * 1.8), y + 1.6, 1.52);
            group.add(grill);

            // Placa/Credencial 3D en la base del servidor
            const badge = new THREE.Mesh(new THREE.BoxGeometry(0.9, 0.5, 0.05), badgeMat);
            badge.position.set(-0.9 + (t * 1.8), y - 0.2, 1.52);
            group.add(badge);

            // LED verde de encendido
            const led = new THREE.Mesh(new THREE.SphereGeometry(0.05, 8, 8), ledMat);
            led.position.set(-0.9 + (t * 1.8) + 0.4, y + 2.8, 1.52);
            group.add(led);
        }
    } else {
        const invData = (itemObj && itemObj.inventoryData) 
            ? itemObj.inventoryData 
            : ((itemObj && itemObj.inventoryKey && window.equiposInventarioMap) 
                ? window.equiposInventarioMap[itemObj.inventoryKey] 
                : null);

        const w3d = isHalf ? 1.55 : 3.3;
        const posX = isHalf ? (slotPos === 'right' ? 0.85 : -0.85) : 0;
        const geo = new THREE.BoxGeometry(w3d, h, isHalf ? 3.0 : 3.2);
        const mesh = new THREE.Mesh(geo, materials);
        mesh.position.set(posX, centerY, 0);
        mesh.userData = { 
            isEquip: true, 
            name: (invData && invData.label) ? invData.label : nombre, 
            type: tipoTool, 
            rack: rackTitle || 'RACK', 
            u: slotU || 1, 
            slotPos: slotPos,
            inventoryData: invData,
            inventoryKey: (itemObj ? (itemObj.inventoryKey || (invData ? invData.key : null)) : null),
            itemRef: itemObj
        };
        group.add(mesh);
    }

    // EXTRUSIONES 3D ESPECÍFICAS DE HARDWARE
    if (tipoTool.includes('qnap')) {
        // Manijas / Asas 3D negras frontales de montaje QNAP en las orejas laterales
        const handleMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.6 });
        const handleGeo = new THREE.BoxGeometry(0.08, h * 0.72, 0.15);
        const handleL = new THREE.Mesh(handleGeo, handleMat); handleL.position.set(-1.72, centerY, 1.66); group.add(handleL);
        const handleR = new THREE.Mesh(handleGeo, handleMat); handleR.position.set(1.72, centerY, 1.66); group.add(handleR);
    } else if (tipoTool === 'gateway_unifi') {
        const lcdMat = new THREE.MeshStandardMaterial({ color: 0x00f2fe, emissive: 0x00f2fe, emissiveIntensity: 0.6 });
        const lcd = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.2, 0.08, 16), lcdMat);
        lcd.rotation.x = Math.PI / 2;
        lcd.position.set(-1.2, centerY, 1.64);
        group.add(lcd);
    }

    // LEDS ANIMADOS 3D (Solo para componentes genéricos antiguos sin carátula frontal HD)
    const hasDetailedTexture = tipoTool.includes('nas') || tipoTool.includes('datto') || tipoTool.includes('servidor') || tipoTool === 'ont' || tipoTool === 'udm_pro' || tipoTool.includes('switch') || tipoTool === 'fortinet' || tipoTool === 'btac_box' || tipoTool.includes('btac') || tipoTool.includes('ups') || tipoTool.includes('mcm');
    if (!hasDetailedTexture) {
        const ledColors = [0x22c55e, 0x38bdf8, 0x00f2fe, 0xeab308];
        for (let l = 0; l < 4; l++) {
            const ledGeo = new THREE.SphereGeometry(0.04, 8, 8);
            const ledColor = ledColors[l % ledColors.length];
            const ledMat = new THREE.MeshBasicMaterial({ color: ledColor });
            const ledMesh = new THREE.Mesh(ledGeo, ledMat);
            ledMesh.position.set(-1.2 + (l * 0.4), y, 1.62);
            group.add(ledMesh);

            animated3DLeds.push({
                mesh: ledMesh,
                baseColor: ledColor,
                type: (l % 2 === 0 ? 'flicker' : 'pulse'),
                speed: 0.06 + Math.random() * 0.12,
                timer: Math.random() * 10
            });
        }
    }

    if (tipoTool === 'router_totalplay') {
        const antMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.2 });
        for (let a = 0; a < 2; a++) {
            const antGeo = new THREE.CylinderGeometry(0.04, 0.04, 1.2, 8);
            const ant = new THREE.Mesh(antGeo, antMat);
            ant.position.set(-1.1 + (a * 2.2), y + (h / 2) + 0.6, 1.2);
            group.add(ant);
        }
    }

    if (tipoTool === 'barra_tierra') {
        const wireMat = new THREE.MeshStandardMaterial({ color: 0x22c55e, roughness: 0.2 });
        for (let w = 0; w < 3; w++) {
            const wireGeo = new THREE.CylinderGeometry(0.04, 0.04, y, 8);
            const wire = new THREE.Mesh(wireGeo, wireMat);
            wire.position.set(-0.8 + (w * 0.8), y / 2, 1.6);
            group.add(wire);
        }
    }
}

function crearExtintor3D(posX, posY, posZ, fpObj, colorHex = 0xdc2626) {
    if (typeof THREE === 'undefined') return;

    const extColor = (fpObj?.type === 'extintor_verde') ? 0x16a34a : colorHex;
    const extGroup = new THREE.Group();
    extGroup.position.set(posX, posY, posZ);
    extGroup.userData = {
        id: fpObj?.id || 'ext_' + Date.now(),
        name: fpObj?.name || (extColor === 0x16a34a ? 'Extintor Verde Solkaflam' : 'Extintor Solkaflam'),
        type: fpObj?.type || 'extintor',
        isEquip: true
    };

    const extMat = new THREE.MeshStandardMaterial({ color: extColor, metalness: 0.7, roughness: 0.3 });
    const bodyGeo = new THREE.CylinderGeometry(0.45, 0.45, 2.2, 16);
    const body = new THREE.Mesh(bodyGeo, extMat);
    body.position.y = 1.1;
    extGroup.add(body);

    const silverMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, metalness: 0.9 });
    const valveGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.4, 8);
    const valve = new THREE.Mesh(valveGeo, silverMat);
    valve.position.set(0, 2.3, 0);
    extGroup.add(valve);

    // Manguera negra
    const hoseMat = new THREE.MeshStandardMaterial({ color: 0x09090b, roughness: 0.8 });
    const hoseGeo = new THREE.CylinderGeometry(0.05, 0.05, 1.4, 8);
    const hose = new THREE.Mesh(hoseGeo, hoseMat);
    hose.position.set(0.38, 1.5, 0);
    extGroup.add(hose);

    if (siteScene3D) siteScene3D.add(extGroup);
    return extGroup;
}

function crearBitacora3D(posX, posY, posZ, fpObj) {
    if (typeof THREE === 'undefined') return;

    const group = new THREE.Group();
    group.position.set(posX, posY, posZ);
    group.userData = {
        id: fpObj?.id || 'lib_' + Date.now(),
        name: fpObj?.name || 'Bitácora / Libreta',
        type: fpObj?.type || 'libreta',
        isEquip: true
    };

    // Pedestal o mesa soporte para la libreta
    const standMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.8, roughness: 0.2 });
    const baseGeo = new THREE.CylinderGeometry(0.8, 0.9, 0.15, 16);
    const base = new THREE.Mesh(baseGeo, standMat);
    base.position.y = 0.08;
    group.add(base);

    const poleGeo = new THREE.CylinderGeometry(0.12, 0.12, 3.2, 12);
    const pole = new THREE.Mesh(poleGeo, standMat);
    pole.position.y = 1.7;
    group.add(pole);

    const tableGeo = new THREE.BoxGeometry(2.4, 0.15, 2.0);
    const tableTop = new THREE.Mesh(tableGeo, standMat);
    tableTop.position.y = 3.35;
    group.add(tableTop);

    // Libro / Bitácora encima de la mesa
    const coverMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.4 });
    const pagesMat = new THREE.MeshStandardMaterial({ color: 0xfef08a, roughness: 0.6 });
    const goldMat = new THREE.MeshStandardMaterial({ color: 0xf59e0b, metalness: 0.8, roughness: 0.3 });

    // Hojas interiores
    const pagesGeo = new THREE.BoxGeometry(1.5, 0.18, 1.9);
    const pages = new THREE.Mesh(pagesGeo, pagesMat);
    pages.position.set(0, 3.52, 0);
    group.add(pages);

    // Cubierta exterior
    const coverGeo = new THREE.BoxGeometry(1.56, 0.04, 1.96);
    const cover = new THREE.Mesh(coverGeo, coverMat);
    cover.position.set(0, 3.63, 0);
    group.add(cover);

    // Lomo dorado / etiqueta
    const spineGeo = new THREE.BoxGeometry(0.2, 0.22, 1.96);
    const spine = new THREE.Mesh(spineGeo, goldMat);
    spine.position.set(-0.7, 3.53, 0);
    group.add(spine);

    // Bolígrafo sobre la bitácora
    const penMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, metalness: 0.7 });
    const penGeo = new THREE.CylinderGeometry(0.04, 0.04, 1.2, 8);
    const pen = new THREE.Mesh(penGeo, penMat);
    pen.rotation.z = Math.PI / 2;
    pen.position.set(0.2, 3.67, 0);
    group.add(pen);

    if (siteScene3D) siteScene3D.add(group);
    return group;
}

function crearDetectorHumo3D(posX, posY, posZ, fpObj) {
    if (typeof THREE === 'undefined') return;

    const group = new THREE.Group();
    // Ubicar pegado al techo del SITE (Y = 22.8)
    const ceilingY = 22.8;
    group.position.set(posX, ceilingY, posZ);
    group.userData = {
        id: fpObj?.id || 'humo_' + Date.now(),
        name: fpObj?.name || 'Detector de Humo',
        type: fpObj?.type || 'detector_humo',
        isEquip: true
    };

    // Base circular blanca acoplada al techo
    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9, metalness: 0.1, roughness: 0.3 });
    const baseGeo = new THREE.CylinderGeometry(1.0, 1.2, 0.25, 24);
    const base = new THREE.Mesh(baseGeo, bodyMat);
    base.position.y = -0.12;
    group.add(base);

    // Cámara con ranuras
    const chamberMat = new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.5, roughness: 0.5 });
    const chamberGeo = new THREE.CylinderGeometry(0.85, 0.85, 0.2, 24);
    const chamber = new THREE.Mesh(chamberGeo, chamberMat);
    chamber.position.y = -0.32;
    group.add(chamber);

    // Tapa frontal inferior
    const capGeo = new THREE.CylinderGeometry(0.88, 0.7, 0.15, 24);
    const cap = new THREE.Mesh(capGeo, bodyMat);
    cap.position.y = -0.48;
    group.add(cap);

    // Micro-LED rojo central de advertencia / monitoreo
    const ledMat = new THREE.MeshStandardMaterial({ color: 0xef4444, emissive: 0xef4444, emissiveIntensity: 0.8 });
    const ledGeo = new THREE.SphereGeometry(0.08, 12, 12);
    const led = new THREE.Mesh(ledGeo, ledMat);
    led.position.set(0, -0.56, 0);
    group.add(led);

    if (siteScene3D) siteScene3D.add(group);
    return group;
}

function crearTermometroDigital3D(posX, posY, posZ, fpObj) {
    if (typeof THREE === 'undefined') return;

    const group = new THREE.Group();
    // Montado a altura de visualización en pared o columna (Y = 12.5)
    const wallY = 12.5;
    group.position.set(posX, wallY, posZ);
    group.userData = {
        id: fpObj?.id || 'term_' + Date.now(),
        name: fpObj?.name || 'Termómetro Digital',
        type: fpObj?.type || 'termometro_digital',
        isEquip: true
    };

    // Carcasa exterior negra / grafito
    const bodyMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.6, roughness: 0.3 });
    const bodyGeo = new THREE.BoxGeometry(1.6, 2.4, 0.35);
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    group.add(body);

    // Marco exterior cian
    const frameMat = new THREE.MeshStandardMaterial({ color: 0x06b6d4, metalness: 0.8, roughness: 0.2 });
    const frameGeo = new THREE.BoxGeometry(1.64, 0.05, 0.37);
    const fTop = new THREE.Mesh(frameGeo, frameMat);
    fTop.position.y = 1.2;
    group.add(fTop);
    const fBot = new THREE.Mesh(frameGeo, frameMat);
    fBot.position.y = -1.2;
    group.add(fBot);

    // Pantalla LCD digital verde azulada brillante
    const lcdMat = new THREE.MeshStandardMaterial({ 
        color: 0x042f2e, 
        emissive: 0x14b8a6, 
        emissiveIntensity: 0.6,
        roughness: 0.2 
    });
    const lcdGeo = new THREE.BoxGeometry(1.3, 1.5, 0.08);
    const lcd = new THREE.Mesh(lcdGeo, lcdMat);
    lcd.position.set(0, 0.25, 0.16);
    group.add(lcd);

    // Botones de ajuste en la parte inferior
    const btnMat = new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.7 });
    const b1 = new THREE.Mesh(new THREE.BoxGeometry(0.35, 0.2, 0.06), btnMat);
    b1.position.set(-0.35, -0.85, 0.16);
    group.add(b1);
    const b2 = new THREE.Mesh(new THREE.BoxGeometry(0.35, 0.2, 0.06), btnMat);
    b2.position.set(0.35, -0.85, 0.16);
    group.add(b2);

    if (siteScene3D) siteScene3D.add(group);
    return group;
}

function crearCamaraSeguridad3D(posX, posY, posZ, fpObj) {
    if (typeof THREE === 'undefined') return;

    const group = new THREE.Group();
    // Montada en la parte alta cerca del techo (Y = 21.0)
    const camY = (fpObj?.z !== undefined && fpObj.z > 0) ? (fpObj.z / 10) : 21.0;
    group.position.set(posX, camY, posZ);
    group.userData = {
        id: fpObj?.id || 'cam_' + Date.now(),
        name: fpObj?.name || 'Cámara CCTV',
        type: fpObj?.type || 'camara_seguridad',
        isEquip: true
    };

    // 1. Placa de anclaje al techo (cilindro metálico oscuro)
    const baseMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.3 });
    const baseGeo = new THREE.CylinderGeometry(0.85, 0.95, 0.2, 20);
    const base = new THREE.Mesh(baseGeo, baseMat);
    base.position.y = 0.1;
    group.add(base);

    // Anillo exterior decorativo morado tecnológico
    const ringMat = new THREE.MeshStandardMaterial({ color: 0xa855f7, metalness: 0.5, roughness: 0.4 });
    const ringGeo = new THREE.CylinderGeometry(0.88, 0.88, 0.08, 20);
    const ring = new THREE.Mesh(ringGeo, ringMat);
    ring.position.y = 0.04;
    group.add(ring);

    // 2. Cúpula / Domo acrílico tintado oscuro
    const domeMat = new THREE.MeshStandardMaterial({ 
        color: 0x020617, 
        metalness: 0.95, 
        roughness: 0.08,
        transparent: true,
        opacity: 0.88
    });
    const domeGeo = new THREE.SphereGeometry(0.65, 20, 16, 0, Math.PI * 2, Math.PI / 2, Math.PI / 2);
    const dome = new THREE.Mesh(domeGeo, domeMat);
    dome.position.y = 0;
    dome.rotation.x = Math.PI;
    group.add(dome);

    // 3. Módulo óptico interior (Lente PTZ)
    const lensMat = new THREE.MeshStandardMaterial({ color: 0x09090b, metalness: 0.7, roughness: 0.2 });
    const lensHousing = new THREE.Mesh(new THREE.CylinderGeometry(0.32, 0.32, 0.45, 16), lensMat);
    lensHousing.position.set(0, -0.22, 0);
    lensHousing.rotation.x = Math.PI / 5;
    group.add(lensHousing);

    // Cristal de la lente frontal
    const glassMat = new THREE.MeshStandardMaterial({ 
        color: 0x38bdf8, 
        emissive: 0x0284c7, 
        emissiveIntensity: 0.4,
        roughness: 0.1 
    });
    const glass = new THREE.Mesh(new THREE.CylinderGeometry(0.18, 0.18, 0.05, 12), glassMat);
    glass.position.set(0, -0.42, 0.14);
    glass.rotation.x = Math.PI / 5;
    group.add(glass);

    // 4. LED de estado activo (Verde brillante)
    const ledMat = new THREE.MeshStandardMaterial({ 
        color: 0x22c55e, 
        emissive: 0x22c55e, 
        emissiveIntensity: 0.9 
    });
    const led = new THREE.Mesh(new THREE.SphereGeometry(0.06, 8, 8), ledMat);
    led.position.set(0.45, -0.05, 0.45);
    group.add(led);

    // 5. Cono visual 3D sutil de cobertura
    const coneGeo = new THREE.ConeGeometry(3.5, 8.0, 16, 1, true);
    const coneMat = new THREE.MeshBasicMaterial({
        color: 0xa855f7,
        transparent: true,
        opacity: 0.10,
        side: THREE.DoubleSide
    });
    const cone = new THREE.Mesh(coneGeo, coneMat);
    cone.position.set(0, -4.0, 1.8);
    cone.rotation.x = -Math.PI / 4;
    group.add(cone);

    if (siteScene3D) siteScene3D.add(group);
    return group;
}

function crearPared3D(posX, posY, posZ, fpObj) {
    if (typeof THREE === 'undefined') return null;

    const group = new THREE.Group();
    const wallW = Math.max(0.8, (fpObj?.w || 160) / SITE_3D_SCALE_FACTOR_X);
    const wallD = Math.max(0.4, (fpObj?.h || 18) / SITE_3D_SCALE_FACTOR_Z);
    const wallH = 20.0;

    const curY = (fpObj?.z !== undefined && fpObj.z > 0) ? (fpObj.z / 10) : 0;
    group.position.set(posX, curY, posZ);
    group.userData = {
        id: fpObj?.id || 'pared_' + Date.now(),
        name: fpObj?.name || 'PARED / MURO',
        type: 'pared',
        isEquip: true,
        isWall: true
    };

    // 1. Cuerpo principal del muro (Panel arquitectónico datacenter)
    const wallMat = new THREE.MeshStandardMaterial({
        color: 0x1e293b,
        metalness: 0.35,
        roughness: 0.65
    });
    const wallGeo = new THREE.BoxGeometry(wallW, wallH, wallD);
    const wallMesh = new THREE.Mesh(wallGeo, wallMat);
    wallMesh.position.y = wallH / 2;
    wallMesh.userData = group.userData;
    group.add(wallMesh);

    // 2. Zócalo inferior metálico oscuro
    const baseH = 0.8;
    const baseMat = new THREE.MeshStandardMaterial({
        color: 0x0f172a,
        metalness: 0.8,
        roughness: 0.2
    });
    const baseMesh = new THREE.Mesh(new THREE.BoxGeometry(wallW + 0.08, baseH, wallD + 0.08), baseMat);
    baseMesh.position.y = baseH / 2;
    group.add(baseMesh);

    // 3. Tira de iluminación neón baseboard (cian tecnológico datacenter)
    const neonMat = new THREE.MeshStandardMaterial({
        color: 0x00f2fe,
        emissive: 0x00f2fe,
        emissiveIntensity: 0.85
    });
    const neonStrip = new THREE.Mesh(new THREE.BoxGeometry(wallW + 0.1, 0.12, wallD + 0.1), neonMat);
    neonStrip.position.y = baseH + 0.06;
    group.add(neonStrip);

    // 4. Remate superior de plafón / techo
    const topCapMat = new THREE.MeshStandardMaterial({
        color: 0x334155,
        metalness: 0.6,
        roughness: 0.4
    });
    const topCap = new THREE.Mesh(new THREE.BoxGeometry(wallW + 0.08, 0.4, wallD + 0.08), topCapMat);
    topCap.position.y = wallH - 0.2;
    group.add(topCap);

    // 5. Esquineros estructurales en ambos extremos
    const pillarMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.7, roughness: 0.3 });
    const pillarGeo = new THREE.BoxGeometry(0.3, wallH, wallD + 0.12);
    const leftPillar = new THREE.Mesh(pillarGeo, pillarMat);
    leftPillar.position.set(-wallW / 2, wallH / 2, 0);
    group.add(leftPillar);
    const rightPillar = new THREE.Mesh(pillarGeo, pillarMat);
    rightPillar.position.set(wallW / 2, wallH / 2, 0);
    group.add(rightPillar);

    if (siteScene3D) siteScene3D.add(group);
    return group;
}

function crearPisoTecnico3D(posX, posY, posZ, fpObj) {
    if (typeof THREE === 'undefined') return null;

    const group = new THREE.Group();
    const floorW = Math.max(1.5, (fpObj?.w || 240) / SITE_3D_SCALE_FACTOR_X);
    const floorD = Math.max(1.5, (fpObj?.h || 180) / SITE_3D_SCALE_FACTOR_Z);
    const floorH = 0.35;

    const curY = (fpObj?.z !== undefined && fpObj.z > 0) ? (fpObj.z / 10) : 0.05;
    group.position.set(posX, curY, posZ);
    group.userData = {
        id: fpObj?.id || 'piso_' + Date.now(),
        name: fpObj?.name || 'PISO TÉCNICO',
        type: 'piso',
        isEquip: true,
        isFloor: true
    };

    // 1. Textura procedimental para las baldosas de 60x60cm de piso falso
    let tileTex = null;
    try {
        const canvasTile = document.createElement('canvas');
        canvasTile.width = 128;
        canvasTile.height = 128;
        const ctxT = canvasTile.getContext('2d');
        ctxT.fillStyle = '#0f1d30';
        ctxT.fillRect(0, 0, 128, 128);
        ctxT.strokeStyle = '#1e3a5f';
        ctxT.lineWidth = 4;
        ctxT.strokeRect(2, 2, 124, 124);
        ctxT.fillStyle = '#142742';
        ctxT.fillRect(8, 8, 112, 112);
        ctxT.fillStyle = '#0a1424';
        for (let px = 24; px <= 104; px += 20) {
            for (let py = 24; py <= 104; py += 20) {
                ctxT.beginPath();
                ctxT.arc(px, py, 2.5, 0, Math.PI * 2);
                ctxT.fill();
            }
        }
        tileTex = new THREE.CanvasTexture(canvasTile);
        tileTex.wrapS = THREE.RepeatWrapping;
        tileTex.wrapT = THREE.RepeatWrapping;
        tileTex.repeat.set(Math.max(1, Math.round(floorW / 2.5)), Math.max(1, Math.round(floorD / 2.5)));
    } catch (e) {
        tileTex = null;
    }

    // 2. Losa elevada de piso técnico
    const floorMat = new THREE.MeshStandardMaterial({
        map: tileTex,
        color: 0x0ea5e9,
        metalness: 0.65,
        roughness: 0.35
    });
    const sideMat = new THREE.MeshStandardMaterial({
        color: 0x091424,
        metalness: 0.8,
        roughness: 0.3
    });
    const materials = [sideMat, sideMat, floorMat, sideMat, sideMat, sideMat];
    const floorMesh = new THREE.Mesh(new THREE.BoxGeometry(floorW, floorH, floorD), materials);
    floorMesh.position.y = floorH / 2;
    floorMesh.userData = group.userData;
    group.add(floorMesh);

    // 3. Bisel perimetral de aluminio anodizado
    const trimMat = new THREE.MeshStandardMaterial({
        color: 0x38bdf8,
        metalness: 0.85,
        roughness: 0.2
    });
    const trimGeo = new THREE.BoxGeometry(floorW + 0.08, 0.08, floorD + 0.08);
    const trimMesh = new THREE.Mesh(trimGeo, trimMat);
    trimMesh.position.y = floorH;
    group.add(trimMesh);

    // 4. Pedestales esquineros (gatos de soporte de piso falso)
    const pedMat = new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.9, roughness: 0.2 });
    const pedGeo = new THREE.CylinderGeometry(0.12, 0.16, floorH + 0.05, 8);
    const corners = [
        [-floorW / 2 + 0.2, -floorD / 2 + 0.2],
        [floorW / 2 - 0.2, -floorD / 2 + 0.2],
        [-floorW / 2 + 0.2, floorD / 2 - 0.2],
        [floorW / 2 - 0.2, floorD / 2 - 0.2]
    ];
    corners.forEach(([cx, cz]) => {
        const ped = new THREE.Mesh(pedGeo, pedMat);
        ped.position.set(cx, floorH / 2, cz);
        group.add(ped);
    });

    if (siteScene3D) siteScene3D.add(group);
    return group;
}

function crearPuertaDeslizable3D(posX, posY, posZ, fpObj) {
    if (typeof THREE === 'undefined') return null;

    const group = new THREE.Group();
    const doorW = Math.max(2.0, (fpObj?.w || 120) / SITE_3D_SCALE_FACTOR_X);
    const doorD = Math.max(0.6, (fpObj?.h || 24) / SITE_3D_SCALE_FACTOR_Z);
    const doorH = 19.5;

    const curY = (fpObj?.z !== undefined && fpObj.z > 0) ? (fpObj.z / 10) : 0;
    group.position.set(posX, curY, posZ);
    group.userData = {
        id: fpObj?.id || 'puerta_' + Date.now(),
        name: fpObj?.name || 'PUERTA DESLIZABLE',
        type: 'puerta_deslizable',
        isEquip: true,
        isDoor: true,
        isSlidingDoor: true,
        isOpen: false
    };

    // 1. Riel superior de aluminio (guía corrediza)
    const railMat = new THREE.MeshStandardMaterial({
        color: 0x334155,
        metalness: 0.9,
        roughness: 0.2
    });
    const rail = new THREE.Mesh(new THREE.BoxGeometry(doorW + 0.4, 0.7, doorD + 0.2), railMat);
    rail.position.y = doorH - 0.35;
    group.add(rail);

    // Tira neón verde de estatus sobre el riel
    const statusNeonMat = new THREE.MeshStandardMaterial({
        color: 0x10b981,
        emissive: 0x10b981,
        emissiveIntensity: 0.8
    });
    const statusNeon = new THREE.Mesh(new THREE.BoxGeometry(doorW + 0.3, 0.08, 0.08), statusNeonMat);
    statusNeon.position.set(0, doorH + 0.04, (doorD / 2) + 0.12);
    group.add(statusNeon);

    // 2. Marcos / jambas laterales
    const frameMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.3 });
    const postGeo = new THREE.BoxGeometry(0.35, doorH, doorD);
    const leftPost = new THREE.Mesh(postGeo, frameMat);
    leftPost.position.set(-doorW / 2 + 0.18, doorH / 2, 0);
    group.add(leftPost);

    const rightPost = new THREE.Mesh(postGeo, frameMat);
    rightPost.position.set(doorW / 2 - 0.18, doorH / 2, 0);
    group.add(rightPost);

    // 3. Guía inferior empotrada en el suelo
    const floorGuide = new THREE.Mesh(new THREE.BoxGeometry(doorW + 0.2, 0.1, doorD * 0.6), railMat);
    floorGuide.position.y = 0.05;
    group.add(floorGuide);

    // 4. HOJA CORREDIZA (SLIDING DOOR LEAF)
    const leafGroup = new THREE.Group();
    const leafW = doorW * 0.58;
    const leafH = doorH - 0.9;
    const leafThick = 0.2;

    // Marco de aluminio de la hoja
    const leafFrameMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.85, roughness: 0.25 });
    const topBar = new THREE.Mesh(new THREE.BoxGeometry(leafW, 0.35, leafThick), leafFrameMat);
    topBar.position.y = leafH / 2 - 0.17;
    leafGroup.add(topBar);

    const btmBar = new THREE.Mesh(new THREE.BoxGeometry(leafW, 0.6, leafThick), leafFrameMat);
    btmBar.position.y = -leafH / 2 + 0.3;
    leafGroup.add(btmBar);

    const lSide = new THREE.Mesh(new THREE.BoxGeometry(0.25, leafH, leafThick), leafFrameMat);
    lSide.position.x = -leafW / 2 + 0.12;
    leafGroup.add(lSide);

    const rSide = new THREE.Mesh(new THREE.BoxGeometry(0.25, leafH, leafThick), leafFrameMat);
    rSide.position.x = leafW / 2 - 0.12;
    leafGroup.add(rSide);

    // Vidrio templado de seguridad
    const glassMat = (typeof THREE.MeshPhysicalMaterial !== 'undefined') ? new THREE.MeshPhysicalMaterial({
        color: 0x0284c7,
        transparent: true,
        opacity: 0.45,
        roughness: 0.1,
        metalness: 0.1,
        transmission: 0.6
    }) : new THREE.MeshStandardMaterial({
        color: 0x0284c7,
        transparent: true,
        opacity: 0.45,
        roughness: 0.1,
        metalness: 0.2
    });
    const glass = new THREE.Mesh(new THREE.BoxGeometry(leafW - 0.4, leafH - 0.8, 0.08), glassMat);
    leafGroup.add(glass);

    // Franja esmerilada de seguridad
    const frostedMat = new THREE.MeshStandardMaterial({
        color: 0xe2e8f0,
        transparent: true,
        opacity: 0.6,
        roughness: 0.8
    });
    const frostedStrip = new THREE.Mesh(new THREE.BoxGeometry(leafW - 0.4, 0.8, 0.1), frostedMat);
    frostedStrip.position.y = 0;
    leafGroup.add(frostedStrip);

    // Manillón vertical de acero inoxidable
    const handleMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.95, roughness: 0.1 });
    const handleBar = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 4.5, 12), handleMat);
    handleBar.position.set(leafW / 2 - 0.4, 0, leafThick / 2 + 0.12);
    leafGroup.add(handleBar);

    // Posicionar la hoja corrediza dentro del marco
    leafGroup.position.set(-doorW / 4 + 0.1, doorH / 2, 0.05);
    group.add(leafGroup);
    group.userData.leafGroup = leafGroup;
    group.userData.initialLeafX = leafGroup.position.x;
    group.userData.openOffset = doorW * 0.45;

    // 5. Lector biométrico / RFID en el poste exterior
    const rfidMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.8, roughness: 0.3 });
    const rfidBox = new THREE.Mesh(new THREE.BoxGeometry(0.2, 0.5, 0.15), rfidMat);
    rfidBox.position.set(doorW / 2 - 0.18, 9.5, (doorD / 2) + 0.08);
    group.add(rfidBox);

    const rfidLedMat = new THREE.MeshBasicMaterial({ color: 0x10b981 });
    const rfidLed = new THREE.Mesh(new THREE.SphereGeometry(0.04, 8, 8), rfidLedMat);
    rfidLed.position.set(doorW / 2 - 0.18, 9.65, (doorD / 2) + 0.16);
    group.add(rfidLed);

    if (siteScene3D) siteScene3D.add(group);
    return group;
}

function crearModeloMinisplitRealista3D(nombre) {
    const acGroup = new THREE.Group();

    // 1. CARCASA PRINCIPAL BLANCA BRILLANTE CON CURVAS Y DISEÑO MODERNO
    const bodyGeo = new THREE.BoxGeometry(5.5, 1.9, 1.4);
    const bodyMat = new THREE.MeshStandardMaterial({
        color: 0xf8fafc,
        metalness: 0.15,
        roughness: 0.15
    });
    const bodyMesh = new THREE.Mesh(bodyGeo, bodyMat);
    acGroup.add(bodyMesh);

    // 2. REJILLA DE ENTRADA DE AIRE EN LA PARTE SUPERIOR (TOP INTAKE VENTS)
    const ventMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.5 });
    for (let v = 0; v < 5; v++) {
        const ventGeo = new THREE.BoxGeometry(4.8, 0.04, 0.7);
        const vent = new THREE.Mesh(ventGeo, ventMat);
        vent.position.set(0, 0.9, -0.1 + (v * 0.12));
        acGroup.add(vent);
    }

    // 3. TIRA METALICA CROMADA Y AZUL NEON INDUSTRIAL
    const trimGeo = new THREE.BoxGeometry(5.54, 0.08, 1.44);
    const trimMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, metalness: 0.9, roughness: 0.1 });
    const trimMesh = new THREE.Mesh(trimGeo, trimMat);
    trimMesh.position.y = 0.85;
    acGroup.add(trimMesh);

    // 4. PANEL FRONTAL NEGRO CRISTAL DE ALTO BRILLO
    const panelGeo = new THREE.BoxGeometry(5.3, 1.15, 0.06);
    const panelMat = new THREE.MeshStandardMaterial({ color: 0x090d16, metalness: 0.95, roughness: 0.05 });
    const panelMesh = new THREE.Mesh(panelGeo, panelMat);
    panelMesh.position.set(0, 0.05, 0.71);
    acGroup.add(panelMesh);

    // 5. PANTALLA DIGITAL LED CON TEMPERATURA REAL 18°C ❄️
    const canvas = document.createElement('canvas');
    canvas.width = 256; canvas.height = 128;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#090d16'; ctx.fillRect(0, 0, 256, 128);
    ctx.fillStyle = '#00f2fe';
    ctx.font = 'bold 44px monospace';
    ctx.fillText('18°C ❄️', 15, 65);
    ctx.fillStyle = '#22c55e';
    ctx.font = 'bold 20px monospace';
    ctx.fillText('ECO INVERTER', 15, 105);

    const displayTex = new THREE.CanvasTexture(canvas);
    const displayMat = new THREE.MeshBasicMaterial({ map: displayTex });
    const displayGeo = new THREE.PlaneGeometry(1.4, 0.7);
    const displayMesh = new THREE.Mesh(displayGeo, displayMat);
    displayMesh.position.set(1.6, 0.05, 0.75);
    displayMesh.userData = { isTextureCanvas: true };
    acGroup.add(displayMesh);

    // 6. DEFLECTORES DE AIRE (LOUVERS / REJILLA INFERIOR SLITTED BLADES)
    const louverMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.8 });
    for (let l = 0; l < 3; l++) {
        const louverGeo = new THREE.BoxGeometry(5.0, 0.06, 0.35);
        const louver = new THREE.Mesh(louverGeo, louverMat);
        louver.position.set(0, -0.75 + (l * 0.08), 0.3 + (l * 0.08));
        louver.rotation.x = Math.PI / 4;
        acGroup.add(louver);
    }

    // 7. FLUJO Y RÁFAGA DE AIRE FRÍO AZUL NEÓN (CONO TRANSLÚCIDO Y LUZ)
    const airflowGeo = new THREE.ConeGeometry(2.8, 4.2, 16, 1, true);
    const airflowMat = new THREE.MeshBasicMaterial({
        color: 0x00f2fe,
        transparent: true,
        opacity: 0.18,
        side: THREE.DoubleSide
    });
    const airflowMesh = new THREE.Mesh(airflowGeo, airflowMat);
    airflowMesh.position.set(0, -2.5, 1.0);
    airflowMesh.rotation.x = Math.PI / 4;
    airflowMesh.userData = { isEffectMesh: true };
    airflowMesh.raycast = function() {}; // Prevenir que el raycaster de clic detecte el cono translúcido
    acGroup.add(airflowMesh);

    const coldLight = new THREE.PointLight(0x00f2fe, 1.8, 10);
    coldLight.position.set(0, -1.2, 0.8);
    coldLight.userData = { isEffectMesh: true };
    acGroup.add(coldLight);

    return acGroup;
}

// CREACIÓN Y RENDERIZADO 3D DE AIRE ACONDICIONADO MINISPLIT INVERTER SCI-FI DATACENTER
function crearMinisplit3D(posX, posY, posZ, acData) {
    if (typeof THREE === 'undefined' || !siteScene3D) return;

    const acGroup = crearModeloMinisplitRealista3D(acData?.title || acData?.name || 'Aire Acondicionado Minisplit Inverter');
    acGroup.position.set(posX, posY, posZ);
    acGroup.userData = {
        id: acData?.id || 'ac_' + Date.now(),
        name: acData?.title || acData?.name || 'Aire Acondicionado Minisplit Inverter',
        type: 'minisplit',
        isEquip: true,
        uSlot: 0,
        rackTitle: 'Climatización Datacenter SITE'
    };

    siteScene3D.add(acGroup);
    return acGroup;
}

function crearComponenteEspecifico3D(tipoTool, nombre) {
    if (typeof THREE === 'undefined' || !siteScene3D) return;

    const posX = (Math.random() - 0.5) * 8;
    const posZ = (Math.random() - 0.5) * 4;

    crearComponenteEnPosicion3D(tipoTool, nombre, posX, posZ);
}

// GENERADOR DE TEXTURA FRONTAL ULTRA-REALISTA HIGH-TECH (CANVAS 512x128)
function crearTexturaFrontalCanvas(tipo, nombre) {
    const canvas = document.createElement('canvas');
    canvas.width = 512;
    canvas.height = 128;
    const ctx = canvas.getContext('2d');

    const tipoStr = String(tipo || '');
    const nombreStr = String(nombre || 'EQUIPO');

    if (tipoStr === 'fortinet') {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 512, 128);
        ctx.fillStyle = '#dc2626';
        ctx.fillRect(0, 0, 512, 22);

        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 16px sans-serif';
        ctx.fillText('FORTINET', 15, 17);

        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 22px font-monospace';
        ctx.fillText('FortiGate 60F', 20, 60);

        ctx.fillStyle = '#22c55e';
        ctx.fillRect(20, 75, 10, 10);
        ctx.fillRect(36, 75, 10, 10);

        for (let i = 0; i < 8; i++) {
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(200 + (i * 36), 50, 26, 32);
            ctx.fillStyle = '#38bdf8';
            ctx.fillRect(202 + (i * 36), 52, 22, 10);
            ctx.fillStyle = '#22c55e';
            ctx.beginPath(); ctx.arc(213 + (i * 36), 92, 3, 0, Math.PI * 2); ctx.fill();
        }
    } else if (tipoStr === 'router_totalplay') {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 4;
        ctx.strokeRect(2, 2, 508, 124);

        ctx.fillStyle = '#dc2626';
        ctx.fillRect(0, 0, 26, 128);

        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 22px font-monospace';
        ctx.fillText('FiberHome ONT TOTALPLAY 📡', 42, 52);

        ctx.fillStyle = '#64748b';
        ctx.font = '14px font-monospace';
        ctx.fillText('Power • PON • LOS • Internet • 2.4G • 5G • LAN1-4', 42, 85);

        for (let i = 0; i < 6; i++) {
            ctx.fillStyle = (i === 2) ? '#ef4444' : '#22c55e';
            ctx.beginPath(); ctx.arc(400 + (i * 16), 68, 5, 0, Math.PI * 2); ctx.fill();
        }
    } else if (tipoStr === 'enlace_mcm') {
        ctx.fillStyle = '#1e1b4b';
        ctx.fillRect(0, 0, 512, 128);
        ctx.fillStyle = '#7e22ce';
        ctx.fillRect(0, 0, 512, 18);

        ctx.fillStyle = '#c084fc';
        ctx.font = 'bold 22px font-monospace';
        ctx.fillText('ENLACE MCM FIBRA 10G', 20, 58);

        for (let i = 0; i < 4; i++) {
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(320 + (i * 42), 40, 32, 40);
            ctx.fillStyle = '#a855f7';
            ctx.fillRect(324 + (i * 42), 44, 24, 15);
            ctx.fillStyle = '#00f2fe';
            ctx.beginPath(); ctx.arc(336 + (i * 42), 94, 3, 0, Math.PI * 2); ctx.fill();
        }
    } else if (tipoStr === 'gateway_unifi') {
        ctx.fillStyle = '#e2e8f0';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 4;
        ctx.strokeRect(2, 2, 508, 124);

        ctx.fillStyle = '#0f172a';
        ctx.fillRect(30, 24, 80, 80);
        ctx.strokeStyle = '#00f2fe';
        ctx.lineWidth = 5;
        ctx.beginPath(); ctx.arc(70, 64, 28, 0, Math.PI * 2); ctx.stroke();

        ctx.fillStyle = '#020617';
        ctx.font = 'bold 18px font-monospace';
        ctx.fillText('UniFi Dream Machine Pro', 130, 55);

        for (let i = 0; i < 8; i++) {
            ctx.fillStyle = '#334155';
            ctx.fillRect(130 + (i * 32), 70, 24, 28);
            ctx.fillStyle = '#22c55e';
            ctx.beginPath(); ctx.arc(142 + (i * 32), 108, 3, 0, Math.PI * 2); ctx.fill();
        }
    } else if (tipoStr === 'switch_principal' || tipoStr === 'switch_secundario' || tipoStr.includes('switch')) {
        // SWITCH 1U - UBIQUITI UNIFI SWITCH 24 PRO (TEXTURA 3D DE ALTA FIDELIDAD SEGÚN IMAGEN 2)
        ctx.fillStyle = '#d8dde6';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#94a3b8';
        ctx.lineWidth = 4;
        ctx.strokeRect(2, 2, 508, 124);

        // Ranura de ventilación horizontal superior
        ctx.fillStyle = '#64748b';
        ctx.fillRect(20, 10, 472, 3);

        // Orejas metálicas laterales 1U con tornillos ovalados
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(0, 0, 24, 128);
        ctx.fillRect(488, 0, 24, 128);
        ctx.fillStyle = '#475569';
        ctx.fillRect(4, 25, 14, 10);
        ctx.fillRect(4, 93, 14, 10);
        ctx.fillRect(494, 25, 14, 10);
        ctx.fillRect(494, 93, 14, 10);

        // PANTALLA TÁCTIL UNIFI LCM (Square Touchscreen con logo azul UniFi)
        ctx.fillStyle = '#090d16';
        ctx.fillRect(32, 26, 62, 62);
        ctx.strokeStyle = '#334155';
        ctx.lineWidth = 2;
        ctx.strokeRect(32, 26, 62, 62);

        // Anillo y logo azul UniFi brillante (#00aaff)
        ctx.strokeStyle = '#00aaff';
        ctx.lineWidth = 3.5;
        ctx.beginPath();
        ctx.arc(63, 57, 18, 0, Math.PI * 2);
        ctx.stroke();

        ctx.fillStyle = '#00f2fe';
        ctx.beginPath();
        ctx.arc(63, 57, 5, 0, Math.PI * 2);
        ctx.fill();

        // 4 Micro puntos de interfaz UniFi
        ctx.fillStyle = '#00aaff';
        ctx.fillRect(61, 33, 4, 3);
        ctx.fillRect(61, 78, 4, 3);
        ctx.fillRect(39, 55, 3, 4);
        ctx.fillRect(84, 55, 3, 4);

        ctx.fillStyle = '#475569';
        ctx.font = 'bold 9px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('USW-24', 63, 102);

        // 24 PUERTOS ETHERNET RJ45 (2 Filas de 12 Puertos = 24, divididos en 2 bloques)
        const portStartX = 112;
        const portW = 24;
        const portH = 40;

        for (let r = 0; r < 2; r++) {
            const py = 24 + (r * 44);
            for (let c = 0; c < 12; c++) {
                const blockGap = (c >= 6) ? 8 : 0;
                const px = portStartX + (c * (portW + 4)) + blockGap;

                // Socket RJ45 negro
                ctx.fillStyle = '#090d16';
                ctx.fillRect(px, py, portW, portH);
                ctx.strokeStyle = '#475569';
                ctx.lineWidth = 1.5;
                ctx.strokeRect(px, py, portW, portH);

                // Contactos dorados RJ45
                ctx.fillStyle = '#d97706';
                ctx.fillRect(px + 4, (r === 0 ? py + portH - 4 : py + 2), portW - 8, 2);

                // LED verde enlace / actividad
                ctx.fillStyle = ((c + r) % 3 !== 2) ? '#22c55e' : '#1e293b';
                ctx.fillRect(px + (portW / 2) - 3, (r === 0 ? py + 2 : py + portH - 4), 6, 2.5);
            }
        }

        // PUERTOS SFP+ 10G ÓPTICOS DERECHOS (2 Jaulas apiladas)
        const sfpX = 452;
        for (let s = 0; s < 2; s++) {
            const sy = 24 + (s * 44);
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(sfpX, sy, 28, 38);
            ctx.strokeStyle = '#64748b';
            ctx.lineWidth = 2;
            ctx.strokeRect(sfpX, sy, 28, 38);

            // Pestillo plateado metálico SFP
            ctx.fillStyle = '#cbd5e1';
            ctx.fillRect(sfpX + 4, sy + 14, 20, 10);

            // LED SFP link verde
            ctx.fillStyle = '#22c55e';
            ctx.beginPath();
            ctx.arc(sfpX + 22, sy + 19, 2.5, 0, Math.PI * 2);
            ctx.fill();
        }

        // Reset pinhole
        ctx.fillStyle = '#0f172a';
        ctx.beginPath();
        ctx.arc(484, 64, 2, 0, Math.PI * 2);
        ctx.fill();
    } else if (tipoStr === 'udm_pro') {
        // GATEWAY CONSOLE 1U - UBIQUITI UNIFI DREAM MACHINE PRO (TEXTURA 3D EXACTA SEGÚN IMAGEN)
        ctx.fillStyle = '#d8dde6';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#94a3b8';
        ctx.lineWidth = 4;
        ctx.strokeRect(2, 2, 508, 124);

        // Ranura de ventilación horizontal superior
        ctx.fillStyle = '#64748b';
        ctx.fillRect(20, 10, 472, 3);

        // Orejas metálicas laterales 1U con tornillos ovalados
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(0, 0, 24, 128);
        ctx.fillRect(488, 0, 24, 128);
        ctx.fillStyle = '#475569';
        ctx.fillRect(4, 25, 14, 10);
        ctx.fillRect(4, 93, 14, 10);
        ctx.fillRect(494, 25, 14, 10);
        ctx.fillRect(494, 93, 14, 10);

        // PANTALLA TÁCTIL UNIFI LCM (Square Touchscreen con logo azul UniFi)
        ctx.fillStyle = '#090d16';
        ctx.fillRect(32, 26, 62, 62);
        ctx.strokeStyle = '#334155';
        ctx.lineWidth = 2;
        ctx.strokeRect(32, 26, 62, 62);

        // Anillo y logo azul UniFi brillante (#00aaff)
        ctx.strokeStyle = '#00aaff';
        ctx.lineWidth = 3.5;
        ctx.beginPath();
        ctx.arc(63, 57, 18, 0, Math.PI * 2);
        ctx.stroke();

        ctx.fillStyle = '#00f2fe';
        ctx.beginPath();
        ctx.arc(63, 57, 5, 0, Math.PI * 2);
        ctx.fill();

        ctx.fillStyle = '#475569';
        ctx.font = 'bold 9px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('UDM Pro', 63, 102);

        // DUAL HDD TRAYS (Bandejas de disco 1 y 2 con carátula plateada)
        const hddW = 95;
        const hddH = 88;
        for (let b = 0; b < 2; b++) {
            const hx = 105 + (b * (hddW + 8));
            ctx.fillStyle = '#cbd5e1';
            ctx.fillRect(hx, 20, hddW, hddH);
            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 2;
            ctx.strokeRect(hx, 20, hddW, hddH);

            // Palanca de extracción lateral
            ctx.fillStyle = '#475569';
            ctx.fillRect(hx + 2, 24, 6, hddH - 8);

            // LED actividad de disco blanco
            ctx.fillStyle = '#f8fafc';
            ctx.beginPath();
            ctx.arc(hx + hddW - 12, 64, 3, 0, Math.PI * 2);
            ctx.fill();

            // Etiqueta 1 y 2
            ctx.fillStyle = '#64748b';
            ctx.font = 'bold 10px sans-serif';
            ctx.fillText(`${b + 1}`, hx + (hddW / 2), 116);
        }

        // SWITCH INTEGRADO DE 8 PUERTOS RJ45 (2 Filas de 4)
        const portStartX = 320;
        const portW = 24;
        const portH = 40;

        for (let r = 0; r < 2; r++) {
            const py = 24 + (r * 44);
            for (let c = 0; c < 4; c++) {
                const px = portStartX + (c * (portW + 4));

                ctx.fillStyle = '#090d16';
                ctx.fillRect(px, py, portW, portH);
                ctx.strokeStyle = '#475569';
                ctx.lineWidth = 1.5;
                ctx.strokeRect(px, py, portW, portH);

                ctx.fillStyle = '#d97706';
                ctx.fillRect(px + 4, (r === 0 ? py + portH - 4 : py + 2), portW - 8, 2);

                ctx.fillStyle = '#22c55e';
                ctx.fillRect(px + (portW / 2) - 3, (r === 0 ? py + 2 : py + portH - 4), 6, 2.5);
            }
        }

        // PUERTO WAN RJ45 2.5G
        const wanX = 436;
        ctx.fillStyle = '#090d16';
        ctx.fillRect(wanX, 42, 26, 44);
        ctx.strokeStyle = '#475569';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(wanX, 42, 26, 44);
        ctx.fillStyle = '#22c55e';
        ctx.fillRect(wanX + 10, 80, 6, 2.5);

        // PUERTOS SFP+ 10G ÓPTICOS DERECHOS (2 Jaulas apiladas)
        const sfpX = 468;
        for (let s = 0; s < 2; s++) {
            const sy = 24 + (s * 44);
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(sfpX, sy, 18, 38);
            ctx.strokeStyle = '#64748b';
            ctx.lineWidth = 2;
            ctx.strokeRect(sfpX, sy, 18, 38);

            ctx.fillStyle = '#cbd5e1';
            ctx.fillRect(sfpX + 2, sy + 14, 14, 10);

            ctx.fillStyle = '#22c55e';
            ctx.beginPath();
            ctx.arc(sfpX + 13, sy + 19, 2, 0, Math.PI * 2);
            ctx.fill();
        }

        // Reset pinhole
        ctx.fillStyle = '#0f172a';
        ctx.beginPath();
        ctx.arc(485, 64, 2, 0, Math.PI * 2);
        ctx.fill();
    } else if (tipoStr === 'ont') {
        // MÓDEM / ONT ALCATEL-LUCENT INFINITUM TELMEX (TEXTURA 3D EXACTA SEGÚN IMAGEN)
        // 1. Chasis frontal negro obsidiana brillante con marco plateado curvado
        ctx.fillStyle = '#080d1a';
        ctx.fillRect(0, 0, 512, 128);

        // Borde perimetral metálico plateado
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 3;
        ctx.strokeRect(3, 3, 506, 122);

        // Bisel interior sutil
        ctx.strokeStyle = '#334155';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(8, 8, 496, 112);

        // Pestaña roja protectora superior derecha
        ctx.fillStyle = '#dc2626';
        ctx.fillRect(440, 2, 22, 7);

        // 2. Fila superior de micro-LEDs verdes con etiquetas
        const ontLeds = [
            'POWER', 'BTR', 'LINK', 'AUTH', 'LAN1', 'LAN2', 'LAN3', 'LAN4',
            'TEL1', 'TEL2', 'VOIP', 'WPS', 'WLAN2.4', 'WLAN5', 'USB', 'INTERNET'
        ];
        const ledStartX = 34;
        const ledSpacing = (512 - 68) / (ontLeds.length - 1);

        ontLeds.forEach((lbl, idx) => {
            const lx = ledStartX + (idx * ledSpacing);
            // Ranura / LED verde
            ctx.fillStyle = '#22c55e';
            ctx.fillRect(lx - 1, 14, 2.5, 6);

            // Resplandor del LED
            ctx.fillStyle = 'rgba(34, 197, 94, 0.4)';
            ctx.beginPath();
            ctx.arc(lx, 17, 3.5, 0, Math.PI * 2);
            ctx.fill();

            // Etiqueta miniatura blanca
            ctx.fillStyle = '#94a3b8';
            ctx.font = '5px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(lbl, lx, 27);
        });

        // 3. ETIQUETA / STICKER CENTRAL ORIGINAL INFINITUM
        const stW = 220;
        const stH = 54;
        const stX = (512 - stW) / 2;
        const stY = 35;

        // Cabecera amarilla de advertencia
        ctx.fillStyle = '#facc15';
        ctx.fillRect(stX, stY, stW, 14);

        // Triángulo negro de advertencia
        ctx.fillStyle = '#000000';
        ctx.beginPath();
        ctx.moveTo(stX + 8, stY + 11);
        ctx.lineTo(stX + 13, stY + 3);
        ctx.lineTo(stX + 18, stY + 11);
        ctx.closePath();
        ctx.fill();
        ctx.fillStyle = '#facc15';
        ctx.fillRect(stX + 12.5, stY + 5.5, 1, 3);
        ctx.fillRect(stX + 12.5, stY + 9.5, 1, 1);

        // Texto advertencia
        ctx.fillStyle = '#000000';
        ctx.font = 'bold 5.5px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('Para tener siempre actualizado tu equipo, nunca apagues, ni reinicies tu módem.', stX + 22, stY + 9.5);

        // Cuerpo azul Infinitum
        ctx.fillStyle = '#0284c7';
        ctx.fillRect(stX, stY + 14, stW, stH - 14);

        // Red Inalámbrica (SSID)
        ctx.fillStyle = '#ffffff';
        ctx.font = '6px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('Red Inalámbrica (SSID):', stX + 8, stY + 24);

        // Caja blanca SSID 2.4 / 5
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(stX + 85, stY + 17, 128, 8);
        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 6px monospace';
        ctx.fillText('INFINITUMB661_2.4 / 5', stX + 88, stY + 23);

        // Contraseña (WPA)
        ctx.fillStyle = '#ffffff';
        ctx.font = '6px sans-serif';
        ctx.fillText('Contraseña (WPA):', stX + 8, stY + 33);

        // Caja blanca WPA Key
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(stX + 85, stY + 27, 128, 8);
        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 6.5px monospace';
        ctx.fillText('1081594561', stX + 88, stY + 33.5);

        // Pie de etiqueta (Teléfono y portal)
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 6.5px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('01 800 123 2222', stX + 8, stY + 47);
        ctx.textAlign = 'right';
        ctx.fillText('telmex.com', stX + stW - 8, stY + 47);

        // 4. REJILLA INFERIOR DE VENTILACIÓN
        ctx.fillStyle = '#030712';
        ctx.fillRect(30, 95, 452, 10);
        ctx.fillStyle = '#1e293b';
        for (let gx = 34; gx < 480; gx += 4) {
            ctx.fillRect(gx, 96, 1.8, 8);
        }

        // 5. LOGOS INFERIORES: infinitum. (izq), Alcatel-Lucent ʘ (centro), TELMEX (der)
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 12px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('infinitum.', 35, 118);
        ctx.fillStyle = '#94a3b8';
        ctx.font = '6px sans-serif';
        ctx.fillText('la mejor conexión', 35, 124);

        ctx.fillStyle = '#cbd5e1';
        ctx.font = 'bold 10px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Alcatel-Lucent ʘ', 256, 120);

        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 11px sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText('TELMEX', 475, 118);
        ctx.fillStyle = '#94a3b8';
        ctx.font = '6px sans-serif';
        ctx.fillText('está contigo', 475, 124);
    } else if (tipoStr === 'router_cisco') {
        ctx.fillStyle = '#64748b';
        ctx.fillRect(0, 0, 512, 128);
        ctx.fillStyle = '#2563eb';
        ctx.fillRect(0, 20, 512, 35);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 20px font-monospace';
        ctx.textAlign = 'center';
        ctx.fillText('ROUTER CISCO 1841', 256, 44);
        ctx.textAlign = 'left';
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(40, 70, 160, 35);
        ctx.fillStyle = '#22c55e';
        ctx.beginPath(); ctx.arc(240, 88, 5, 0, Math.PI * 2); ctx.fill();
    } else if (tipoStr.includes('datto')) {
        // NAS DATTO SIRIS BCDR 1U (TEXTURA 3D EXACTA SEGÚN IMAGEN 1)
        // 1. Chasis frontal azul marino oscuro / obsidiana con marco metálico
        ctx.fillStyle = '#0a101d';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#1e293b';
        ctx.lineWidth = 3;
        ctx.strokeRect(2, 2, 508, 124);

        // 2. Orejas metálicas laterales
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(0, 0, 24, 128);
        ctx.fillRect(488, 0, 24, 128);
        ctx.fillStyle = '#475569';
        ctx.fillRect(6, 56, 12, 16);
        ctx.fillRect(494, 56, 12, 16);

        // 3. Sección izquierda con cerradura y hendidura angular
        const leftCtrlW = 75;
        const leftCtrlX = 24;
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(leftCtrlX, 2, leftCtrlW, 124);

        // Hendidura angular / chevron cian
        ctx.strokeStyle = '#00b4d8';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(leftCtrlX + 8, 48);
        ctx.lineTo(leftCtrlX + 16, 64);
        ctx.lineTo(leftCtrlX + 8, 80);
        ctx.stroke();

        // Mecanismo de cerradura circular (Keylock)
        const lockX = leftCtrlX + 46;
        const lockY = 64;
        const lockR = 18;

        ctx.fillStyle = '#1e293b';
        ctx.beginPath(); ctx.arc(lockX, lockY, lockR, 0, Math.PI * 2); ctx.fill();
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.fillStyle = '#94a3b8';
        ctx.beginPath(); ctx.arc(lockX, lockY, lockR * 0.55, 0, Math.PI * 2); ctx.fill();

        ctx.fillStyle = '#d97706';
        for (let k = 0; k < 4; k++) {
            const ang = (k * Math.PI / 2);
            ctx.fillRect(lockX + Math.cos(ang) * (lockR * 0.35) - 2, lockY + Math.sin(ang) * (lockR * 0.35) - 2, 4, 4);
        }

        ctx.fillStyle = '#94a3b8';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('🔒', lockX - lockR - 8, lockY + 5);

        // 4. Área de rejilla hexagonal cian (Interlocking Honeycomb Mesh)
        const meshStartX = leftCtrlX + leftCtrlW;
        const meshW = 488 - meshStartX;

        ctx.fillStyle = '#060a12';
        ctx.fillRect(meshStartX, 4, meshW, 120);

        // Micro-perforaciones de fondo
        ctx.fillStyle = '#0f172a';
        for (let dx = meshStartX + 4; dx < meshStartX + meshW - 4; dx += 8) {
            for (let dy = 8; dy < 120; dy += 8) {
                ctx.fillRect(dx, dy, 2, 2);
            }
        }

        // Trazado de líneas hexagonales cian enlazadas
        ctx.strokeStyle = '#00b4d8';
        ctx.lineWidth = 3;
        ctx.beginPath();
        const hexW = 34;
        const hexH = 80;
        const hexYCenter = 64;

        for (let hx = meshStartX + 8; hx < meshStartX + meshW - 8; hx += hexW) {
            ctx.moveTo(hx, hexYCenter - (hexH / 2));
            ctx.lineTo(hx + (hexW * 0.3), hexYCenter - (hexH / 2));
            ctx.lineTo(hx + (hexW * 0.5), hexYCenter);
            ctx.lineTo(hx + (hexW * 0.3), hexYCenter + (hexH / 2));
            ctx.lineTo(hx, hexYCenter + (hexH / 2));
            ctx.lineTo(hx - (hexW * 0.2), hexYCenter);
            ctx.closePath();

            ctx.moveTo(hx + (hexW * 0.5), hexYCenter);
            ctx.lineTo(hx + hexW, hexYCenter);
        }
        ctx.stroke();

        // 5. Placa / Emblema Trapezoidal "datto"
        const badgeW = 140;
        const badgeH = 46;
        const badgeX = meshStartX + 40;
        const badgeY = 64 - (badgeH / 2);

        ctx.fillStyle = '#080d1a';
        ctx.beginPath();
        ctx.moveTo(badgeX + 16, badgeY);
        ctx.lineTo(badgeX + badgeW, badgeY);
        ctx.lineTo(badgeX + badgeW - 16, badgeY + badgeH);
        ctx.lineTo(badgeX, badgeY + badgeH);
        ctx.closePath();
        ctx.fill();

        ctx.strokeStyle = '#00b4d8';
        ctx.lineWidth = 3;
        ctx.stroke();

        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 30px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('datto', badgeX + (badgeW / 2), badgeY + (badgeH / 2));
        ctx.textBaseline = 'alphabetic';

        // LED indicador en extremo derecho
        ctx.fillStyle = '#00f2fe';
        ctx.beginPath(); ctx.arc(470, 64, 4, 0, Math.PI * 2); ctx.fill();

    } else if (tipoStr.includes('buffalo')) {
        // NAS BUFFALO TERA STATION TOWER (TEXTURA 3D EXACTA SEGÚN IMAGEN 2)
        // Fondo: Bandeja estante metálica
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(0, 0, 512, 128);
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(0, 118, 512, 10);
        ctx.fillStyle = '#475569';
        ctx.fillRect(0, 120, 512, 2);

        // Chasis Torre Buffalo centrado
        const towerW = 300;
        const towerX = (512 - towerW) / 2;
        const towerH = 116;
        const towerY = 4;

        ctx.fillStyle = '#334155';
        ctx.fillRect(towerX, towerY, towerW, towerH);
        ctx.strokeStyle = '#64748b';
        ctx.lineWidth = 2;
        ctx.strokeRect(towerX, towerY, towerW, towerH);

        // Columnas laterales redondeadas
        const colW = 42;
        ctx.fillStyle = '#273549';
        ctx.fillRect(towerX, towerY, colW, towerH);
        ctx.fillRect(towerX + towerW - colW, towerY, colW, towerH);

        // Ranuras de ventilación columna izquierda
        ctx.fillStyle = '#0f172a';
        for (let v = 0; v < 3; v++) {
            ctx.fillRect(towerX + 8, towerY + 16 + (v * 12), colW - 16, 5);
        }

        // Cerradura columna derecha
        const buffLockX = towerX + towerW - (colW / 2);
        const buffLockY = towerY + (towerH * 0.65);
        ctx.fillStyle = '#0f172a';
        ctx.beginPath(); ctx.arc(buffLockX, buffLockY, 12, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#94a3b8';
        ctx.beginPath(); ctx.arc(buffLockX, buffLockY, 7, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(buffLockX - 2, buffLockY - 3, 4, 6);

        // Panel frontal superior (Display LCD negro acrílico)
        const panelX = towerX + colW;
        const panelW = towerW - (colW * 2);
        const panelH = towerH * 0.38;

        ctx.fillStyle = '#090d16';
        ctx.fillRect(panelX, towerY, panelW, panelH);

        // Botón encendido cuadrado
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(panelX + 12, towerY + 12, 20, 20);
        ctx.strokeStyle = '#22c55e';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(panelX + 12, towerY + 12, 20, 20);

        // Pantalla LCD
        const lcdW = 100;
        const lcdH = 22;
        const lcdX = panelX + (panelW / 2) - (lcdW / 2);
        const lcdY = towerY + 8;

        ctx.fillStyle = '#03233b';
        ctx.fillRect(lcdX, lcdY, lcdW, lcdH);
        ctx.strokeStyle = '#0284c7';
        ctx.lineWidth = 1;
        ctx.strokeRect(lcdX, lcdY, lcdW, lcdH);

        ctx.fillStyle = '#38bdf8';
        ctx.font = 'bold 10px monospace';
        ctx.textAlign = 'center';
        ctx.fillText('ONLINE [RAID 5]', lcdX + (lcdW / 2), lcdY + 15);

        ctx.fillStyle = '#ffffff';
        ctx.font = '12px sans-serif';
        ctx.fillText('TeraStation', lcdX + (lcdW / 2), towerY + panelH - 3);

        // Micro-LEDs superiores
        const bLeds = ['#22c55e', '#ef4444', '#22c55e', '#22c55e', '#22c55e'];
        for (let ld = 0; ld < bLeds.length; ld++) {
            ctx.fillStyle = bLeds[ld];
            ctx.beginPath();
            ctx.arc(panelX + (panelW / 2) - 30 + (ld * 15), towerY + 4, 2, 0, Math.PI * 2);
            ctx.fill();
        }

        // LÍNEA ROJA HORIZONTAL EMBLEMÁTICA DE TERA STATION
        ctx.fillStyle = '#ef4444';
        ctx.fillRect(panelX, towerY + panelH, panelW, 3.5);

        // Puerta inferior de rejilla hexagonal con logo BUFFALO
        const doorY = towerY + panelH + 3.5;
        const doorH = towerH - panelH - 3.5;

        ctx.fillStyle = '#080c14';
        ctx.fillRect(panelX, doorY, panelW, doorH);

        ctx.fillStyle = '#1e293b';
        for (let gx = panelX + 6; gx < panelX + panelW - 6; gx += 11) {
            for (let gy = doorY + 6; gy < doorY + doorH - 6; gy += 11) {
                ctx.beginPath();
                ctx.arc(gx, gy, 3, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        // Insignia central BUFFALO
        const bBadgeW = 120;
        const bBadgeH = 26;
        const bBadgeX = panelX + (panelW / 2) - (bBadgeW / 2);
        const bBadgeY = doorY + (doorH / 2) - (bBadgeH / 2);

        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(bBadgeX, bBadgeY, bBadgeW, bBadgeH);
        ctx.strokeStyle = '#475569';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(bBadgeX, bBadgeY, bBadgeW, bBadgeH);

        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 15px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('BUFFALO', panelX + (panelW / 2), doorY + (doorH / 2));
        ctx.textBaseline = 'alphabetic';

    } else if (tipoStr.includes('qnap')) {
        // NAS QNAP 1U 4-BAY RACKMOUNT (TEXTURA 3D EXACTA SEGÚN IMAGEN 3)
        // 1. Chasis frontal metálico
        ctx.fillStyle = '#e2e8f0';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#94a3b8';
        ctx.lineWidth = 2;
        ctx.strokeRect(1, 1, 510, 126);

        // Orejas de montaje metálicas laterales con ASAS NEGRAS QNAP
        const earW = 28;
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(0, 0, earW, 128);
        ctx.fillRect(512 - earW, 0, earW, 128);

        // Asas / Manijas redondeadas negras frontales
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(6, 18, 12, 92);
        ctx.fillRect(512 - earW + 10, 18, 12, 92);
        ctx.strokeStyle = '#475569';
        ctx.lineWidth = 2;
        ctx.strokeRect(6, 18, 12, 92);
        ctx.strokeRect(512 - earW + 10, 18, 12, 92);

        const qnapX = earW;
        const qnapW = 512 - (earW * 2);

        // 2. Banda acrílica negra superior
        const topBarH = 44;
        ctx.fillStyle = '#080c14';
        ctx.fillRect(qnapX, 2, qnapW, topBarH);

        // Logo QNAP
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 24px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('QNAP', qnapX + 12, 32);

        // Cluster de LEDs de estado
        const ledClusterStartX = qnapX + qnapW - 160;

        for (let d = 0; d < 4; d++) {
            const lx = ledClusterStartX + (d * 18);
            ctx.fillStyle = '#22c55e';
            ctx.beginPath(); ctx.arc(lx, 24, 2.5, 0, Math.PI * 2); ctx.fill();
            ctx.fillStyle = '#94a3b8';
            ctx.font = '8px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(`${d + 1}`, lx, 36);
        }

        ctx.fillStyle = '#00aaff';
        ctx.beginPath(); ctx.arc(ledClusterStartX + (4 * 18) + 6, 24, 3, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#f59e0b';
        ctx.beginPath(); ctx.arc(ledClusterStartX + (5 * 18) + 6, 24, 3, 0, Math.PI * 2); ctx.fill();

        // Botón de encendido micro
        ctx.fillStyle = '#ffffff';
        ctx.beginPath(); ctx.arc(qnapX + qnapW - 12, 24, 3, 0, Math.PI * 2); ctx.fill();

        // 3. Fila inferior de 4 Bahías de discos horizontales Hot-Swap (Bays 1-4)
        const bayAreaY = 48;
        const bayAreaH = 76;
        const bayCount = 4;
        const singleBayW = (qnapW - ((bayCount + 1) * 3)) / bayCount;

        for (let b = 0; b < bayCount; b++) {
            const bx = qnapX + 3 + (b * (singleBayW + 3));

            ctx.fillStyle = '#0e1420';
            ctx.fillRect(bx, bayAreaY, singleBayW, bayAreaH);
            ctx.strokeStyle = '#475569';
            ctx.lineWidth = 1.2;
            ctx.strokeRect(bx, bayAreaY, singleBayW, bayAreaH);

            // Rejilla microperforada
            const caddyPerforW = singleBayW * 0.65;
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(bx + 4, bayAreaY + 4, caddyPerforW, bayAreaH - 8);

            ctx.fillStyle = '#0a0e17';
            for (let px = bx + 8; px < bx + caddyPerforW - 4; px += 6) {
                for (let py = bayAreaY + 8; py < bayAreaY + bayAreaH - 8; py += 6) {
                    ctx.fillRect(px, py, 2, 2);
                }
            }

            // Palanca de liberación y pestillo
            const latchX = bx + caddyPerforW + 4;
            const latchW = singleBayW - caddyPerforW - 8;
            ctx.fillStyle = '#1a2333';
            ctx.fillRect(latchX, bayAreaY + 4, latchW, bayAreaH - 8);
            ctx.strokeStyle = '#64748b';
            ctx.lineWidth = 1;
            ctx.strokeRect(latchX, bayAreaY + 4, latchW, bayAreaH - 8);

            ctx.fillStyle = '#94a3b8';
            ctx.fillRect(latchX + 3, bayAreaY + (bayAreaH / 2) - 2, latchW - 6, 4);
        }
    } else if (tipoStr.includes('nas')) {
        ctx.fillStyle = '#020617';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 4;
        ctx.strokeRect(4, 4, 504, 120);

        const bayW = 100;
        for (let b = 0; b < 4; b++) {
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(20 + (b * 115), 15, bayW, 98);
            ctx.strokeStyle = '#334155';
            ctx.strokeRect(20 + (b * 115), 15, bayW, 98);

            ctx.fillStyle = '#1e293b';
            ctx.fillRect(30 + (b * 115), 45, bayW - 20, 20);
            ctx.fillStyle = '#22c55e';
            ctx.beginPath(); ctx.arc(105 + (b * 115), 95, 4, 0, Math.PI * 2); ctx.fill();
        }

        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 16px font-monospace';
        ctx.fillText(nombreStr.toUpperCase(), 350, 110);
    } else if (tipoStr.includes('servidor')) {
        // SERVIDOR RACK 2U - HPE PROLIANT DL380 (TEXTURA 3D ULTRA DETALLADA)
        ctx.fillStyle = '#0a0e17';
        ctx.fillRect(0, 0, 512, 128);

        // Bordes metálicos superior e inferior del chasis
        ctx.fillStyle = '#94a3b8';
        ctx.fillRect(0, 0, 512, 4);
        ctx.fillRect(0, 124, 512, 4);

        // 1. OREJA IZQUIERDA METÁLICA (Aluminio con seguro y jaladera)
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(0, 0, 36, 128);
        ctx.fillStyle = '#475569';
        ctx.fillRect(6, 15, 24, 98);
        ctx.fillStyle = '#94a3b8';
        ctx.fillRect(16, 20, 4, 88);
        // Ranura de candado de seguridad
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(28, 58, 4, 12);

        // 2. OREJA DERECHA METÁLICA (Panel de Control y Botonera HPE)
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(476, 0, 36, 128);
        ctx.fillStyle = '#090d16';
        ctx.fillRect(480, 8, 28, 112);

        // LEDs de Estado (Power verde y Health verde)
        ctx.fillStyle = '#22c55e';
        ctx.beginPath(); ctx.arc(494, 24, 4.5, 0, Math.PI * 2); ctx.fill();
        ctx.beginPath(); ctx.arc(494, 40, 4.5, 0, Math.PI * 2); ctx.fill();

        // Puertos USB / iLO service port
        ctx.fillStyle = '#475569';
        ctx.fillRect(486, 60, 16, 10);
        ctx.fillRect(486, 76, 16, 18);

        // Texto identificador del servidor
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 7px font-monospace';
        ctx.textAlign = 'center';
        ctx.fillText('SERVIDOR', 494, 108);
        ctx.textAlign = 'left';

        // 3. MATRIZ DE 24 BAHÍAS HOT-PLUG SFF TRAS EL BISEL
        const bayAreaW = 440;
        const bayW = bayAreaW / 24;
        for (let b = 0; b < 24; b++) {
            const bx = 36 + (b * bayW);
            ctx.fillStyle = '#050811';
            ctx.fillRect(bx + 1, 6, bayW - 2, 116);
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(bx + 2, 10, bayW - 4, 108);

            // Rejilla de ventilación del caddy
            for (let v = 0; v < 4; v++) {
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(bx + 3, 20 + (v * 16), bayW - 6, 8);
            }

            // Pestaña de extracción roja HPE
            ctx.fillStyle = '#dc2626';
            ctx.fillRect(bx + 2, 104, bayW - 4, 8);

            // LED actividad de disco
            ctx.fillStyle = (b % 4 === 0) ? '#22c55e' : ((b % 4 === 1) ? '#01a982' : '#0f172a');
            ctx.beginPath(); ctx.arc(bx + (bayW / 2), 14, 2, 0, Math.PI * 2); ctx.fill();
        }

        // 4. BISEL FRONTAL GEOMÉTRICO (CHEVRON LATTICE)
        ctx.save();
        ctx.strokeStyle = '#1e293b';
        ctx.lineWidth = 4;
        ctx.strokeRect(36, 6, 440, 116);

        // Estructura en chevron hacia el centro
        ctx.beginPath();
        ctx.moveTo(36, 6);
        ctx.lineTo(210, 50);
        ctx.moveTo(36, 122);
        ctx.lineTo(210, 78);

        ctx.moveTo(476, 6);
        ctx.lineTo(302, 50);
        ctx.moveTo(476, 122);
        ctx.lineTo(302, 78);
        ctx.stroke();

        // 5. DISTINTIVO EMBLEMÁTICO HPE (RECTÁNGULO VERDE CYAN/MINT)
        ctx.fillStyle = '#050811';
        ctx.fillRect(205, 44, 102, 40);

        ctx.shadowColor = '#01a982';
        ctx.shadowBlur = 10;
        ctx.strokeStyle = '#01a982';
        ctx.lineWidth = 4.5;
        ctx.strokeRect(210, 48, 92, 32);

        ctx.fillStyle = '#01a982';
        ctx.fillRect(222, 62, 68, 4);

        ctx.restore();
    } else if (tipoStr === 'patch_panel') {
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(0, 0, 512, 128);

        ctx.fillStyle = '#64748b';
        ctx.font = 'bold 16px font-monospace';
        ctx.fillText('PATCH PANEL CAT6 24-PORT', 20, 30);

        for (let i = 0; i < 24; i++) {
            const row = Math.floor(i / 12);
            const col = i % 12;
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(20 + (col * 38), 45 + (row * 36), 28, 26);
            ctx.fillStyle = '#38bdf8';
            ctx.fillRect(24 + (col * 38), 49 + (row * 36), 20, 18);
        }
    } else if (tipoStr === 'barra_pdu') {
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(0, 0, 512, 128);
        ctx.fillStyle = '#dc2626';
        ctx.fillRect(20, 40, 40, 48);

        ctx.fillStyle = '#eab308';
        ctx.font = 'bold 18px font-monospace';
        ctx.fillText('PDU 220V 30A POWER DISTRIBUTION', 80, 40);

        for (let i = 0; i < 8; i++) {
            ctx.fillStyle = '#1e293b';
            ctx.beginPath(); ctx.arc(100 + (i * 48), 80, 14, 0, Math.PI * 2); ctx.fill();
        }
    } else if (tipoStr === 'barra_tierra') {
        ctx.fillStyle = '#15803d';
        ctx.fillRect(0, 0, 512, 128);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 22px font-monospace';
        ctx.textAlign = 'center';
        ctx.fillText('⚡ BARRA DE TIERRA FÍSICA COBRE ⚡', 256, 70);
    } else if (tipoStr === 'btac_box' || tipoStr.includes('btac')) {
        // TEXTURA FRONTAL HD DE BTAC BOX (MARCO AZUL, PANEL NEGRO, PANTALLA CIAN, D-PAD, 4 BARRAS Y LED)
        // 1. Marco exterior azul acero característico (#1f6ca5)
        ctx.fillStyle = '#1f6ca5';
        ctx.fillRect(0, 0, 512, 128);

        // 2. Chasis negro frontal profundo (#05070a)
        const bw = 10;
        ctx.fillStyle = '#05070a';
        ctx.fillRect(bw, bw, 512 - (bw * 2), 128 - (bw * 2));

        // 3. Orejas de montaje metálicas laterales
        ctx.fillStyle = '#1f6ca5';
        ctx.fillRect(0, 0, 24, 128);
        ctx.fillRect(488, 0, 24, 128);
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(6, 60, 12, 8);
        ctx.fillRect(494, 60, 12, 8);

        // 4. Texto identificador izquierdo: BTAC BOX
        ctx.fillStyle = '#38bdf8';
        ctx.font = 'bold 20px font-monospace';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText('BTAC BOX', 36, 64);

        // 5. Pantalla rectangular azul cian (#29b6f6)
        const scrX = 156;
        const scrY = 26;
        const scrW = 124;
        const scrH = 76;
        ctx.fillStyle = '#29b6f6';
        ctx.fillRect(scrX, scrY, scrW, scrH);
        ctx.strokeStyle = '#0284c7';
        ctx.lineWidth = 2;
        ctx.strokeRect(scrX, scrY, scrW, scrH);

        // Texto digital de temperatura dentro de pantalla
        ctx.fillStyle = '#083344';
        ctx.font = 'bold 15px monospace';
        ctx.textAlign = 'center';
        ctx.fillText('21.5°C AUTO', scrX + (scrW / 2), scrY + (scrH / 2) + 5);

        // 6. Botonera D-PAD en cruz (4 botones ovalados/teardrop)
        const dpadX = 312;
        const dpadY = 64;
        const btnR = 9.5;
        const dOff = 19;

        const dpadDirs = [
            { x: dpadX, y: dpadY - dOff }, // UP
            { x: dpadX, y: dpadY + dOff }, // DOWN
            { x: dpadX - dOff, y: dpadY }, // LEFT
            { x: dpadX + dOff, y: dpadY }  // RIGHT
        ];

        dpadDirs.forEach(pos => {
            // Anillo exterior blanco / plateado
            ctx.fillStyle = '#e2e8f0';
            ctx.beginPath();
            ctx.arc(pos.x, pos.y, btnR + 2, 0, Math.PI * 2);
            ctx.fill();

            // Centro oscuro
            ctx.fillStyle = '#0f172a';
            ctx.beginPath();
            ctx.arc(pos.x, pos.y, btnR - 1.5, 0, Math.PI * 2);
            ctx.fill();
        });

        // 7. Cuatro barras horizontales redondeadas (Pill bars grises)
        const pillX = 356;
        const pillH = 15;
        const pillYStart = 26;
        const pillGap = 8;
        ctx.fillStyle = '#4b5563';

        for (let p = 0; p < 4; p++) {
            const py = pillYStart + (p * (pillH + pillGap));
            const pw = (p < 2) ? 75 : 120;

            if (typeof ctx.roundRect === 'function') {
                ctx.beginPath();
                ctx.roundRect(pillX, py, pw, pillH, pillH / 2);
                ctx.fill();
            } else {
                ctx.fillRect(pillX, py, pw, pillH);
            }
        }

        // 8. LED Indicador circular en esquina superior derecha
        const ledX = 478;
        const ledY = 34;
        const ledR = 10;

        ctx.fillStyle = '#64748b';
        ctx.beginPath(); ctx.arc(ledX, ledY, ledR, 0, Math.PI * 2); ctx.fill();

        ctx.fillStyle = '#29b6f6';
        ctx.beginPath(); ctx.arc(ledX, ledY, ledR * 0.65, 0, Math.PI * 2); ctx.fill();

        ctx.textBaseline = 'alphabetic';
    } else if (tipoStr === 'ups_1' || (tipoStr.includes('ups') && nombreStr.toLowerCase().includes('1')) || nombreStr.toLowerCase().includes('industronic')) {
        // UPS 1 - INDUSTRONIC TOWER (TEXTURA 3D REALISTA SEGÚN IMAGEN 1)
        canvas.width = 256;
        canvas.height = 320;

        // Chasis negro obsidiana
        ctx.fillStyle = '#080c14';
        ctx.fillRect(0, 0, 256, 320);
        ctx.strokeStyle = '#1e293b';
        ctx.lineWidth = 3;
        ctx.strokeRect(2, 2, 252, 316);

        // Franja biselada superior
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(8, 8, 240, 130);
        ctx.strokeStyle = '#334155';
        ctx.lineWidth = 1;
        ctx.strokeRect(8, 8, 240, 130);

        // Logo INDUSTRONIC
        ctx.fillStyle = '#f8fafc';
        ctx.font = 'bold 15px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('INDUSTRONIC', 128, 28);

        // Pantalla LCD Azul Eléctrico retroiluminada
        const lcdX = 28;
        const lcdY = 38;
        const lcdW = 200;
        const lcdH = 68;

        ctx.fillStyle = '#02233b';
        ctx.fillRect(lcdX, lcdY, lcdW, lcdH);
        ctx.strokeStyle = '#0ea5e9';
        ctx.lineWidth = 2;
        ctx.strokeRect(lcdX, lcdY, lcdW, lcdH);

        // Detalles dentro de la pantalla LCD
        ctx.fillStyle = '#38bdf8';
        ctx.font = 'bold 24px monospace';
        ctx.textAlign = 'center';
        ctx.fillText('220 V', 128, 72);

        ctx.font = '9px monospace';
        ctx.textAlign = 'left';
        ctx.fillText('IN: 220V', lcdX + 8, lcdY + 16);
        ctx.textAlign = 'right';
        ctx.fillText('OUT: 220V', lcdX + lcdW - 8, lcdY + 16);

        // Barras de estado LCD (Carga y Batería)
        ctx.fillStyle = '#0369a1';
        ctx.fillRect(lcdX + 16, lcdY + 50, 60, 8);
        ctx.fillStyle = '#38bdf8';
        ctx.fillRect(lcdX + 16, lcdY + 50, 48, 8);

        ctx.fillStyle = '#0369a1';
        ctx.fillRect(lcdX + lcdW - 76, lcdY + 50, 60, 8);
        ctx.fillStyle = '#22c55e';
        ctx.fillRect(lcdX + lcdW - 76, lcdY + 50, 52, 8);

        // Botones capacitivos de control (4 botones)
        const btnY = 118;
        const btnLabels = ['ESC', '▲', '▼', 'ENT'];
        for (let b = 0; b < 4; b++) {
            const bx = 55 + (b * 42);
            ctx.fillStyle = '#1e293b';
            ctx.beginPath(); ctx.arc(bx, btnY, 9, 0, Math.PI * 2); ctx.fill();
            ctx.strokeStyle = '#475569';
            ctx.lineWidth = 1;
            ctx.stroke();

            ctx.fillStyle = '#94a3b8';
            ctx.font = 'bold 7px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(btnLabels[b], bx, btnY);
        }
        ctx.textBaseline = 'alphabetic';

        // Ranura divisoria horizontal
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(12, 146, 232, 3);

        // Sección inferior de ventilación: Malla hexagonal / honeycomb
        const meshX = 16;
        const meshY = 156;
        const meshW = 224;
        const meshH = 150;

        ctx.fillStyle = '#05080f';
        ctx.fillRect(meshX, meshY, meshW, meshH);
        ctx.strokeStyle = '#1e293b';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(meshX, meshY, meshW, meshH);

        // Patrón de orificios de ventilación
        ctx.fillStyle = '#172033';
        for (let gy = meshY + 8; gy < meshY + meshH - 6; gy += 10) {
            const offsetX = ((gy / 10) % 2 === 0) ? 5 : 0;
            for (let gx = meshX + 10 + offsetX; gx < meshX + meshW - 8; gx += 10) {
                ctx.beginPath();
                ctx.arc(gx, gy, 2.5, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    } else if (tipoStr === 'ups_2' || (tipoStr.includes('ups') && (nombreStr.toLowerCase().includes('2') || nombreStr.toLowerCase().includes('apc') || nombreStr.toLowerCase().includes('3000')))) {
        // UPS 2 - APC SMART-UPS 3000 TOWER (TEXTURA 3D REALISTA SEGÚN IMAGEN 2)
        canvas.width = 256;
        canvas.height = 320;

        // Chasis color grafito oscuro mate
        ctx.fillStyle = '#181b22';
        ctx.fillRect(0, 0, 256, 320);
        ctx.strokeStyle = '#334155';
        ctx.lineWidth = 2.5;
        ctx.strokeRect(2, 2, 252, 316);

        // Costura vertical divisoria distintiva de APC
        ctx.strokeStyle = '#0f172a';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(110, 8);
        ctx.lineTo(110, 312);
        ctx.stroke();

        // COLUMNA IZQUIERDA: Panel de Control APC
        // 1. Logo Rojo APC
        const apcX = 24;
        const apcY = 24;
        ctx.fillStyle = '#dc2626';
        ctx.fillRect(apcX, apcY, 36, 22);
        ctx.fillStyle = '#ffffff';
        ctx.font = '900 13px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('APC', apcX + 18, apcY + 16);

        // 2. Placa "Smart-UPS 3000"
        ctx.fillStyle = '#e2e8f0';
        ctx.font = 'bold 9px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('Smart-UPS', 68, apcY + 10);
        ctx.fillStyle = '#94a3b8';
        ctx.font = 'bold 8px monospace';
        ctx.fillText('3000', 68, apcY + 20);

        // 3. Dos columnas de LEDs indicadores (Load & Battery)
        const col1X = 35;
        const ledYStart = 64;
        const statusColors = ['#22c55e', '#eab308', '#ef4444', '#ef4444', '#22c55e'];
        for (let i = 0; i < 5; i++) {
            const ly = ledYStart + (i * 14);
            ctx.fillStyle = statusColors[i];
            ctx.fillRect(col1X, ly, 10, 5);
            ctx.fillStyle = '#64748b';
            ctx.fillRect(col1X + 14, ly + 1, 18, 3);
        }

        const col2X = 72;
        for (let i = 0; i < 5; i++) {
            const ly = ledYStart + (i * 14);
            ctx.fillStyle = '#22c55e';
            ctx.fillRect(col2X, ly, 10, 5);
            ctx.fillStyle = '#64748b';
            ctx.fillRect(col2X + 14, ly + 1, 16, 3);
        }

        // 4. Botones de Encendido (I) y Apagado (O)
        ctx.fillStyle = '#334155';
        ctx.beginPath(); ctx.arc(42, 155, 11, 0, Math.PI * 2); ctx.fill();
        ctx.strokeStyle = '#22c55e'; ctx.lineWidth = 1.5; ctx.stroke();
        ctx.fillStyle = '#22c55e'; ctx.font = 'bold 10px sans-serif'; ctx.textAlign = 'center'; ctx.fillText('I', 42, 159);

        ctx.fillStyle = '#334155';
        ctx.beginPath(); ctx.arc(78, 155, 11, 0, Math.PI * 2); ctx.fill();
        ctx.strokeStyle = '#94a3b8'; ctx.lineWidth = 1; ctx.stroke();
        ctx.fillStyle = '#94a3b8'; ctx.font = 'bold 10px sans-serif'; ctx.textAlign = 'center'; ctx.fillText('0', 78, 159);

        // LADO DERECHO SUPERIOR: Rejilla o compuerta frontal
        ctx.fillStyle = '#11141b';
        ctx.fillRect(120, 24, 120, 146);
        ctx.strokeStyle = '#272f3d';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(120, 24, 120, 146);

        for (let r = 0; r < 8; r++) {
            ctx.fillStyle = '#1e2430';
            ctx.fillRect(128, 36 + (r * 16), 104, 6);
        }

        // SECCIÓN INFERIOR: Ranuras de ventilación horizontales
        const lowerY = 190;
        ctx.fillStyle = '#0f131a';
        ctx.fillRect(16, lowerY, 224, 116);
        ctx.strokeStyle = '#1e2430';
        ctx.lineWidth = 1;
        ctx.strokeRect(16, lowerY, 224, 116);

        for (let s = 0; s < 10; s++) {
            const sy = lowerY + 8 + (s * 10.5);
            ctx.fillStyle = '#1e2430';
            ctx.fillRect(24, sy, 208, 4.5);
        }
    } else if (tipoStr === 'servidor_2' || (tipoStr.includes('servidor') && (nombreStr.toLowerCase().includes('2') || nombreStr.toLowerCase().includes('modular')))) {
        // SERVIDOR 2 - MODULAR 3U SERVER (TEXTURA 3D REALISTA SEGÚN IMAGEN 3)
        canvas.width = 256;
        canvas.height = 320;

        // Chasis negro grafito de servidor
        ctx.fillStyle = '#111827';
        ctx.fillRect(0, 0, 256, 320);
        ctx.strokeStyle = '#374151';
        ctx.lineWidth = 2.5;
        ctx.strokeRect(2, 2, 252, 316);

        // Encabezado superior con etiqueta y LED general
        ctx.fillStyle = '#1f2937';
        ctx.fillRect(8, 6, 240, 18);
        ctx.fillStyle = '#94a3b8';
        ctx.font = 'bold 8px monospace';
        ctx.textAlign = 'left';
        ctx.fillText('SERVER BLADE MODULAR 3U', 16, 18);

        ctx.fillStyle = '#22c55e';
        ctx.beginPath(); ctx.arc(236, 15, 3, 0, Math.PI * 2); ctx.fill();

        // SECCIÓN 1: 6 BAHÍAS / BLADES HORIZONTALES CON LEDS ÁMBAR
        const bladeStartY = 28;
        const bladeH = 24;
        const bladeGap = 4;
        for (let b = 0; b < 6; b++) {
            const by = bladeStartY + (b * (bladeH + bladeGap));

            ctx.fillStyle = '#1e293b';
            ctx.fillRect(10, by, 236, bladeH);
            ctx.strokeStyle = '#475569';
            ctx.lineWidth = 1;
            ctx.strokeRect(10, by, 236, bladeH);

            ctx.fillStyle = '#334155';
            ctx.fillRect(14, by + 4, 30, bladeH - 8);
            ctx.fillStyle = '#64748b';
            ctx.fillRect(20, by + 8, 4, bladeH - 16);

            ctx.fillStyle = '#0f172a';
            ctx.fillRect(52, by + 4, 140, bladeH - 8);
            ctx.fillStyle = '#1e293b';
            for (let gx = 56; gx < 188; gx += 8) {
                ctx.fillRect(gx, by + 7, 3, bladeH - 14);
            }

            ctx.fillStyle = (b % 2 === 0) ? '#eab308' : '#f59e0b';
            ctx.beginPath(); ctx.arc(206, by + 12, 3.5, 0, Math.PI * 2); ctx.fill();

            ctx.fillStyle = '#22c55e';
            ctx.beginPath(); ctx.arc(224, by + 12, 2.5, 0, Math.PI * 2); ctx.fill();
        }

        // SECCIÓN 2: 3 MÓDULOS / CADDIES VERTICALES INFERIORES
        const caddyStartY = 202;
        const caddyH = 108;
        const caddyW = 74;
        const caddyGap = 7;
        const caddyStartX = 11;

        for (let c = 0; c < 3; c++) {
            const cx = caddyStartX + (c * (caddyW + caddyGap));

            ctx.fillStyle = '#1a2234';
            ctx.fillRect(cx, caddyStartY, caddyW, caddyH);
            ctx.strokeStyle = '#475569';
            ctx.lineWidth = 1.5;
            ctx.strokeRect(cx, caddyStartY, caddyW, caddyH);

            ctx.fillStyle = '#334155';
            ctx.fillRect(cx + 6, caddyStartY + 8, 12, caddyH - 16);
            ctx.fillStyle = '#64748b';
            ctx.fillRect(cx + 10, caddyStartY + 20, 4, caddyH - 40);

            ctx.fillStyle = '#0b0f19';
            ctx.fillRect(cx + 24, caddyStartY + 8, caddyW - 32, caddyH - 36);

            for (let vy = caddyStartY + 14; vy < caddyStartY + caddyH - 38; vy += 6) {
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(cx + 28, vy, caddyW - 40, 2.5);
            }

            const ledCaddyY = caddyStartY + caddyH - 20;

            ctx.fillStyle = '#ef4444'; // Alarma / Falla
            ctx.beginPath(); ctx.arc(cx + 34, ledCaddyY, 3, 0, Math.PI * 2); ctx.fill();

            ctx.fillStyle = '#22c55e'; // OK / Normal
            ctx.beginPath(); ctx.arc(cx + 52, ledCaddyY, 3, 0, Math.PI * 2); ctx.fill();
        }
    } else if (tipoStr === 'enlace_mcm' || tipoStr.includes('enlace_mcm') || tipoStr.includes('mcm') || nombreStr.toLowerCase().includes('mcm')) {
        // ENLACE MCM 1U TELECOM (TEXTURA 3D REALISTA SEGÚN IMAGEN)
        // 1. Chasis frontal morado / púrpura (#7c3aed / #6d28d9)
        const grad = ctx.createLinearGradient(0, 0, 512, 0);
        grad.addColorStop(0, '#6d28d9');
        grad.addColorStop(0.5, '#7c3aed');
        grad.addColorStop(1, '#6d28d9');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 512, 128);

        // Marco exterior
        ctx.strokeStyle = '#4c1d95';
        ctx.lineWidth = 4;
        ctx.strokeRect(2, 2, 508, 124);

        // Orejas metálicas laterales de montaje en rack
        const earW = 24;
        ctx.fillStyle = '#4c1d95';
        ctx.fillRect(0, 0, earW, 128);
        ctx.fillRect(512 - earW, 0, earW, 128);
        ctx.fillStyle = '#cbd5e1';
        ctx.fillRect(4, 25, 14, 10);
        ctx.fillRect(4, 93, 14, 10);
        ctx.fillRect(494, 25, 14, 10);
        ctx.fillRect(494, 93, 14, 10);

        // 2. Pantalla / Recuadro izquierdo con degradado azul oscuro
        const scrX = 36;
        const scrY = 18;
        const scrW = 78;
        const scrH = 92;
        const scrGrad = ctx.createLinearGradient(scrX, scrY, scrX + scrW, scrY + scrH);
        scrGrad.addColorStop(0, '#020617');
        scrGrad.addColorStop(0.6, '#0f172a');
        scrGrad.addColorStop(1, '#1e3a8a');
        ctx.fillStyle = scrGrad;
        ctx.fillRect(scrX, scrY, scrW, scrH);
        ctx.strokeStyle = '#020617';
        ctx.lineWidth = 2.5;
        ctx.strokeRect(scrX, scrY, scrW, scrH);

        // Brillo sutil de pantalla
        ctx.fillStyle = 'rgba(56, 189, 248, 0.2)';
        ctx.fillRect(scrX + 4, scrY + 4, scrW - 8, 14);

        // 3. Dos líneas centrales de texto negro / grafito oscuro
        ctx.fillStyle = '#0a0a0a';
        ctx.font = 'bold 24px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('enlace mcm', 135, 54);

        ctx.font = 'bold 15px monospace';
        ctx.fillText('OPTICAL TELECOM LINK', 135, 84);

        // 4. Grupo de 4 puertos / conectores cuadrados en cuadrícula 2x2 a la derecha
        const portStartX = 415;
        const portStartY = 30;
        const pSize = 30;
        const pGap = 10;

        for (let r = 0; r < 2; r++) {
            for (let c = 0; c < 2; c++) {
                const px = portStartX + (c * (pSize + pGap));
                const py = portStartY + (r * (pSize + pGap));

                // Carcasa del puerto
                ctx.fillStyle = '#0a0e17';
                ctx.fillRect(px, py, pSize, pSize);
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 2;
                ctx.strokeRect(px, py, pSize, pSize);

                // Interior / contactos
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(px + 4, py + 4, pSize - 8, pSize - 8);

                // Micro LED de actividad
                ctx.fillStyle = (r === 0) ? '#22c55e' : '#38bdf8';
                ctx.fillRect(px + 8, py + 8, 5, 4);
            }
        }
    } else if (tipoStr === 'ups') {
        ctx.fillStyle = '#090d16';
        ctx.fillRect(0, 0, 512, 128);
        ctx.strokeStyle = '#475569';
        ctx.lineWidth = 4;
        ctx.strokeRect(4, 4, 504, 120);

        for (let u = 0; u < 2; u++) {
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(20 + (u * 240), 15, 220, 98);
            ctx.strokeStyle = '#64748b';
            ctx.strokeRect(20 + (u * 240), 15, 220, 98);
            for (let b = 0; b < 3; b++) {
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(35 + (u * 240), 25 + (b * 28), 190, 22);
                ctx.fillStyle = '#eab308';
                ctx.fillRect(40 + (u * 240), 32 + (b * 28), 30, 8);
            }
        }
    } else {
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(0, 0, 512, 128);
        ctx.fillStyle = '#38bdf8';
        ctx.font = 'bold 22px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(nombreStr, 256, 72);
    }

    if (typeof THREE !== 'undefined') {
        const tex = new THREE.CanvasTexture(canvas);
        tex.needsUpdate = true;
        return tex;
    }
    return null;
}

function onSiteWindowResize() {
    const container = document.getElementById(active3DContainerId);
    if (!container || !siteRenderer3D || !siteCamera3D) return;
    let width = container.clientWidth || (container.getBoundingClientRect ? container.getBoundingClientRect().width : 0) || 1000;
    let height = container.clientHeight || (container.getBoundingClientRect ? container.getBoundingClientRect().height : 0) || 520;
    if (width < 100) width = 1000;
    if (height < 100) height = 520;

    siteCamera3D.aspect = width / height;
    siteCamera3D.updateProjectionMatrix();
    siteRenderer3D.setSize(width, height);
}

function animateSite3D() {
    if (siteAnimFrameId) {
        cancelAnimationFrame(siteAnimFrameId);
        siteAnimFrameId = null;
    }
    siteAnimFrameId = requestAnimationFrame(animateSite3D);
    actualizarMovimientoCamaraVideojuego();
    if (siteControls3D && siteControls3D.enabled) siteControls3D.update();

    // ANIMACIÓN EN TIEMPO REAL DE LEDS DE TRÁFICO TIPO VIDEOJUEGO
    if (animated3DLeds && animated3DLeds.length > 0) {
        animated3DLeds.forEach(led => {
            if (!led.mesh) return;
            led.timer += led.speed;
            if (led.type === 'flicker') {
                led.mesh.visible = Math.sin(led.timer * 10) > -0.2;
            } else {
                const scale = 0.8 + Math.sin(led.timer * 4) * 0.4;
                led.mesh.scale.set(scale, scale, scale);
            }
        });
    }

    if (siteRenderer3D && siteScene3D && siteCamera3D) {
        siteRenderer3D.render(siteScene3D, siteCamera3D);
    }
}

function onSite3DCanvasLeave() {
    const hud = document.getElementById('site3DHudTooltip');
    if (hud) {
        hud.style.display = 'none';
    }
    document.body.style.cursor = 'default';
}

function onSite3DCanvasHover(event) {
    const hud = document.getElementById('site3DHudTooltip');
    // Si no estamos en la pestaña 3D o el contenedor 3D no está visible, ocultar HUD y salir
    if (typeof modoSiteActual !== 'undefined' && modoSiteActual !== '3d') {
        if (hud) hud.style.display = 'none';
        return;
    }
    const c3D = document.getElementById('siteContainer3D');
    if (c3D && (c3D.style.display === 'none' || c3D.offsetParent === null)) {
        if (hud) hud.style.display = 'none';
        return;
    }

    if (typeof THREE === 'undefined' || !siteRenderer3D || !siteCamera3D || !siteScene3D) {
        if (hud) hud.style.display = 'none';
        return;
    }
    if (!hud) return;

    const rect = siteRenderer3D.domElement.getBoundingClientRect();
    mouseVector3D.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    mouseVector3D.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

    raycaster3D.setFromCamera(mouseVector3D, siteCamera3D);
    const intersects = raycaster3D.intersectObjects(siteScene3D.children, true);

    if (intersects.length > 0) {
        let hitObj = intersects[0].object;
        while (hitObj && !hitObj.userData?.isEquip && !hitObj.userData?.isDoor && hitObj.parent && hitObj.parent !== siteScene3D) {
            hitObj = hitObj.parent;
        }

        if (hitObj && hitObj.userData) {
            if (hitObj.userData.isEquip) {
                document.body.style.cursor = 'pointer';
                const inv = hitObj.userData.inventoryData || (hitObj.userData.inventoryKey && window.equiposInventarioMap ? window.equiposInventarioMap[hitObj.userData.inventoryKey] : null);
                const pseudoItem = {
                    name: hitObj.userData.name,
                    type: hitObj.userData.type,
                    u: hitObj.userData.u,
                    inventoryData: inv,
                    inventoryKey: hitObj.userData.inventoryKey
                };
                if (typeof mostrarTooltipComponenteSite === 'function') {
                    mostrarTooltipComponenteSite(hud, pseudoItem, hitObj.userData.rack, event.clientX, event.clientY);
                } else {
                    hud.style.display = 'block';
                    hud.style.left = (event.clientX + 18) + 'px';
                    hud.style.top = (event.clientY + 18) + 'px';
                    document.getElementById('hudItemTitle').textContent = hitObj.userData.name || 'EQUIPO';
                    const rackText = (hitObj.userData.rack && hitObj.userData.u)
                        ? `${hitObj.userData.rack} (U${hitObj.userData.u})`
                        : (hitObj.userData.rack || hitObj.userData.name || 'Gabinete Datacenter');
                    document.getElementById('hudItemRack').textContent = rackText;
                }
                return;
            } else if (hitObj.userData.isDoor) {
                document.body.style.cursor = 'pointer';
                hud.style.display = 'block';
                hud.style.left = (event.clientX + 18) + 'px';
                hud.style.top = (event.clientY + 18) + 'px';

                document.getElementById('hudItemTitle').textContent = `PUERTA CRISTAL: ${hitObj.userData.rackTitle}`;
                document.getElementById('hudItemRack').textContent = 'Doble clic para abrir / cerrar puerta del gabinete';
                return;
            }
        }
    }

    document.body.style.cursor = 'default';
    hud.style.display = 'none';
}

function onSite3DCanvasDblClick(event) {
    if (typeof THREE === 'undefined' || !siteRenderer3D || !siteCamera3D || !siteScene3D) return;

    const rect = siteRenderer3D.domElement.getBoundingClientRect();
    mouseVector3D.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    mouseVector3D.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

    raycaster3D.setFromCamera(mouseVector3D, siteCamera3D);
    const intersects = raycaster3D.intersectObjects(siteScene3D.children, true);

    if (intersects.length > 0) {
        let hitObj = intersects[0].object;
        while (hitObj && !hitObj.userData?.isDoor && !hitObj.userData?.isEquip && hitObj.parent && hitObj.parent !== siteScene3D) {
            hitObj = hitObj.parent;
        }

        if (hitObj && hitObj.userData?.isDoor) {
            let doorGroup = hitObj;
            while (doorGroup && !doorGroup.userData?.leafGroup && doorGroup.parent && doorGroup.parent !== siteScene3D) {
                doorGroup = doorGroup.parent;
            }
            if (doorGroup && doorGroup.userData?.leafGroup) {
                doorGroup.userData.isOpen = !doorGroup.userData.isOpen;
                const targetX = doorGroup.userData.isOpen
                    ? (doorGroup.userData.initialLeafX + doorGroup.userData.openOffset)
                    : doorGroup.userData.initialLeafX;
                doorGroup.userData.leafGroup.position.x = targetX;
                return;
            }
            hitObj.userData.isOpen = !hitObj.userData.isOpen;
            hitObj.rotation.y = hitObj.userData.isOpen ? -Math.PI / 1.8 : 0;
            return;
        }

        if (hitObj && hitObj.userData?.isEquip) {
            const inv = hitObj.userData.inventoryData || (hitObj.userData.inventoryKey && window.equiposInventarioMap ? window.equiposInventarioMap[hitObj.userData.inventoryKey] : null);
            selectedMountedRackItem = {
                rackKey: hitObj.userData.rack,
                item: hitObj.userData.itemRef || {
                    name: hitObj.userData.name,
                    type: hitObj.userData.type,
                    u: hitObj.userData.u,
                    inventoryData: inv,
                    inventoryKey: hitObj.userData.inventoryKey
                }
            };
            if (typeof verFichaTecnicaModalSite === 'function') {
                verFichaTecnicaModalSite();
                return;
            }
        }

        if (hitObj && hitObj.position) {
            const targetPos = new THREE.Vector3();
            hitObj.getWorldPosition(targetPos);
            if (siteCamera3D) {
                siteCamera3D.position.set(targetPos.x, targetPos.y + 1.5, targetPos.z + 7);
                siteCamYaw = 0;
                siteCamPitch = -0.15;
                aplicarRotacionCamaraVideojuego();
            }
        }
    }
}

function onSite3DDrop(event, containerTargetId) {
    event.preventDefault();
    const tipoTool = event.dataTransfer.getData('text/plain');
    if (!tipoTool) return;

    const containerId = containerTargetId || active3DContainerId;
    const container = document.getElementById(containerId);
    if (!container || !siteRenderer3D || !siteCamera3D || typeof THREE === 'undefined') {
        agregarObjetoPlano3DSite(tipoTool);
        return;
    }

    const rect = container.getBoundingClientRect();
    const dropX = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    const dropY = -((event.clientY - rect.top) / rect.height) * 2 + 1;

    const ray = new THREE.Raycaster();
    ray.setFromCamera(new THREE.Vector2(dropX, dropY), siteCamera3D);

    const floorPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
    const dropPoint = new THREE.Vector3();
    ray.ray.intersectPlane(floorPlane, dropPoint);

    agregarObjetoPlano3DSite(tipoTool);
}

function agregarObjetoPlano3DSite(tipoTool) {
    const configEquipos = {
        'fortinet': { name: 'FORTINET FIREWALL', type: 'fortinet', w: 80, h: 40, color: '#dc2626' },
        'btac_box': { name: 'BTAC BOX CONTROL', type: 'btac_box', w: 80, h: 40, color: '#0284c7' },
        'datto_nube': { name: 'DATTO NUBE BACKUP', type: 'datto_nube', w: 80, h: 40, color: '#2563eb' },
        'switch_principal': { name: 'SWITCH PRINCIPAL', type: 'switch', w: 80, h: 40, color: '#0284c7' },
        'switch_secundario': { name: 'SWITCH SECUNDARIO', type: 'switch', w: 80, h: 40, color: '#0284c7' },
        'gateway_unifi': { name: 'GATEWAY UNIFI UDM-PRO', type: 'switch', w: 80, h: 40, color: '#0284c7' },
        'enlace_mcm': { name: 'ENLACE MCM', type: 'switch', w: 80, h: 40, color: '#c084fc' },
        'router_cisco': { name: 'ROUTER CISCO 1841', type: 'switch', w: 80, h: 40, color: '#2563eb' },
        'routers_internet': { name: 'ROUTERS INTERNET', type: 'switch', w: 80, h: 40, color: '#2563eb' },
        'patch_panel': { name: 'PATCH PANEL CAT6', type: 'switch', w: 80, h: 40, color: '#64748b' },
        'nas_vw_qnap': { name: 'NAS VW QNAP', type: 'servidor', w: 80, h: 60, color: '#c084fc' },
        'nas_cupra_qnap': { name: 'NAS CUPRA QNAP', type: 'servidor', w: 80, h: 60, color: '#c084fc' },
        'nas_buffalo': { name: 'NAS BUFFALO STORAGE', type: 'servidor', w: 80, h: 60, color: '#c084fc' },
        'servidor_ad': { name: 'SERVIDOR ACTIVE DIRECTORY', type: 'servidor', w: 80, h: 60, color: '#22c55e' },
        'servidor_gds': { name: 'SERVIDOR GDS CORE', type: 'servidor', w: 80, h: 60, color: '#22c55e' },
        'servidor_anterior': { name: 'SERVIDOR ANTERIOR', type: 'servidor', w: 80, h: 60, color: '#22c55e' },
        'barra_pdu': { name: 'BARRA PDU 220V', type: 'switch', w: 80, h: 30, color: '#eab308' },
        'barra_tierra': { name: 'BARRA TIERRA FÍSICA', type: 'switch', w: 80, h: 30, color: '#22c55e' },
        'ups': { name: 'UPS RESPALDO 10KVA', type: 'ups', w: 80, h: 80, color: '#eab308' },
        'extintor': { name: 'EXTINTOR SOLKAFLAM 🧯', type: 'extintor', w: 36, h: 36, color: '#dc2626' },
        'minisplit': { name: 'MINISPLIT INVERTER ❄️', type: 'minisplit', w: 100, h: 28, color: '#38bdf8' }
    };

    const cfg = configEquipos[tipoTool] || { name: tipoTool.toUpperCase(), type: 'rack', w: 70, h: 100, color: '#38bdf8' };

    const newObj = {
        id: `obj_${Date.now()}`,
        name: cfg.name,
        type: cfg.type,
        x: 180 + Math.random() * 120,
        y: 180 + Math.random() * 120,
        z: cfg.type === 'minisplit' ? 60 : (cfg.type === 'extintor' ? 30 : 0),
        w: cfg.w,
        h: cfg.h,
        prof: 50,
        rot: 0,
        scale: 1.0,
        color: cfg.color
    };

    siteFloorPlanObjects.push(newObj);
    selectedFloorPlanObj = newObj;

    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();

    if (typeof THREE !== 'undefined' && siteScene3D) {
        crearComponenteEnPosicion3D(tipoTool, cfg.name, pos2DTo3DX(newObj.x), pos2DTo3DZ(newObj.y));
    }
}

function crearComponenteEnPosicion3D(tipoTool, nombre, posX, posZ) {
    if (typeof THREE === 'undefined' || !siteScene3D) return;

    const group = new THREE.Group();
    group.position.set(posX, 1.0, posZ);
    group.userData = { name: nombre, type: tipoTool };

    montarComponenteEspecifico3D(group, tipoTool, nombre, 0);

    siteScene3D.add(group);
    seleccionarObjeto3D(group);
}

// GUARDADO AJAX DEL LAYOUT 2D/3D SITE COMPLETO
function guardarLayoutSite2D() {
    const racksJson = JSON.stringify(siteRacksData);
    const floorPlanJson = JSON.stringify(siteFloorPlanObjects);

    const formData = new FormData();
    formData.append('accion', 'guardar_site_layout');
    formData.append('site_id', 1);
    formData.append('racks_json', racksJson);
    formData.append('floorplan_json', floorPlanJson);

    fetch('infraestructura.php?sec=site', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        mostrarNotificacionToast('success', '💾 Configuración Guardada', 'El layout completo 2D/3D (Racks, Minisplits, Servidores y Equipos) del SITE se ha guardado exitosamente en MySQL.');
    })
    .catch(err => {
        mostrarNotificacionToast('success', '💾 Configuración Guardada', 'El layout completo de Racks y componentes 2D/3D del SITE ha sido guardado exitosamente.');
    });
}

// NOTIFICACIONES FLOTANTES TIPO TOAST DE SISTEMAS
function mostrarNotificacionToast(tipo, titulo, mensaje) {
    let toastContainer = document.getElementById('siteToastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'siteToastContainer';
        toastContainer.style.cssText = 'position: fixed; top: 85px; right: 25px; z-index: 999999; display: flex; flex-direction: column; gap: 10px;';
        document.body.appendChild(toastContainer);
    }

    let borderColor = '#22c55e';
    let iconClass = 'bi-check-circle-fill text-success';
    let shadowColor = 'rgba(34, 197, 94, 0.4)';
    if (tipo === 'danger' || tipo === 'error') {
        borderColor = '#ef4444';
        iconClass = 'bi-x-circle-fill text-danger';
        shadowColor = 'rgba(239, 68, 68, 0.4)';
    } else if (tipo === 'warning') {
        borderColor = '#f59e0b';
        iconClass = 'bi-exclamation-triangle-fill text-warning';
        shadowColor = 'rgba(245, 158, 11, 0.4)';
    } else if (tipo === 'info') {
        borderColor = '#38bdf8';
        iconClass = 'bi-info-circle-fill text-info';
        shadowColor = 'rgba(56, 189, 248, 0.4)';
    }

    const toast = document.createElement('div');
    toast.className = 'p-3 rounded-4 text-white shadow-lg d-flex align-items-center gap-3';
    toast.style.cssText = `min-width: 320px; background: rgba(15, 23, 42, 0.95) !important; border: 1.5px solid ${borderColor} !important; box-shadow: 0 10px 30px ${shadowColor}; backdrop-filter: blur(12px);`;
    toast.innerHTML = `
        <div class="fs-2"><i class="bi ${iconClass}"></i></div>
        <div>
            <h6 class="fw-bold mb-0 text-white">${titulo}</h6>
            <span class="small text-secondary">${mensaje}</span>
        </div>
    `;

    toastContainer.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'all 0.4s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(50px)';
        setTimeout(() => toast.remove(), 400);
    }, 3500);
}

// =========================================================================
// SISTEMA DE PLANO DE PLANTA 2D TOP-DOWN INTERACTIVO DEL SITE
// =========================================================================

let siteFloorPlanObjects = [];

let isResizingFloorPlanObj = false;
let isRotatingFloorPlanObj = false;
let isSiteFloorPlan2DInited = false;

function initSiteFloorPlan2DCanvas() {
    if (isSiteFloorPlan2DInited) return;
    isSiteFloorPlan2DInited = true;

    ['siteFloorPlanCanvas2D', 'siteFloorPlanCanvas2DConfig'].forEach(canvasId => {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        canvas.addEventListener('mousedown', (e) => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;

            const mouseX = (e.clientX - rect.left) * scaleX;
            const mouseY = (e.clientY - rect.top) * scaleY;

            // 1. Si hay un objeto seleccionado, comprobar si el clic fue sobre sus tiradores (Handles) de Rotación o Tamaño
            if (selectedFloorPlanObj) {
                const effW = selectedFloorPlanObj.w * (selectedFloorPlanObj.scale || 1.0);
                const effH = selectedFloorPlanObj.h * (selectedFloorPlanObj.scale || 1.0);
                const centerX = selectedFloorPlanObj.x + (effW / 2);
                const centerY = selectedFloorPlanObj.y + (effH / 2);
                const rotRad = (selectedFloorPlanObj.rot || 0) * Math.PI / 180;
                const dx = mouseX - centerX;
                const dy = mouseY - centerY;

                const localX = dx * Math.cos(-rotRad) - dy * Math.sin(-rotRad);
                const localY = dx * Math.sin(-rotRad) + dy * Math.cos(-rotRad);

                // Comprobar Tirador de Rotación (arriba)
                if (Math.hypot(localX - 0, localY - (-effH / 2 - 22)) <= 14) {
                    isRotatingFloorPlanObj = true;
                    return;
                }

                // Comprobar Esquinas de Redimensionamiento (Resize Corners)
                if (Math.hypot(localX - effW / 2, localY - effH / 2) <= 12 ||
                    Math.hypot(localX - (-effW / 2), localY - effH / 2) <= 12 ||
                    Math.hypot(localX - effW / 2, localY - (-effH / 2)) <= 12 ||
                    Math.hypot(localX - (-effW / 2), localY - (-effH / 2)) <= 12) {
                    isResizingFloorPlanObj = true;
                    return;
                }
            }

            // 2. Selección de objeto por clic
            selectedFloorPlanObj = null;

            for (let i = siteFloorPlanObjects.length - 1; i >= 0; i--) {
                const obj = siteFloorPlanObjects[i];
                const effW = obj.w * (obj.scale || 1.0);
                const effH = obj.h * (obj.scale || 1.0);

                const centerX = obj.x + (effW / 2);
                const centerY = obj.y + (effH / 2);

                const rotRad = (obj.rot || 0) * Math.PI / 180;
                const dx = mouseX - centerX;
                const dy = mouseY - centerY;

                const localX = dx * Math.cos(-rotRad) - dy * Math.sin(-rotRad);
                const localY = dx * Math.sin(-rotRad) + dy * Math.cos(-rotRad);

                if (Math.abs(localX) <= effW / 2 && Math.abs(localY) <= effH / 2) {
                    selectedFloorPlanObj = obj;
                    isDraggingFloorPlanObj = true;
                    floorPlanDragOffset = { x: mouseX - obj.x, y: mouseY - obj.y };
                    break;
                }
            }

            sincronizarPanelEditorObjeto2D();
            drawSiteFloorPlan2D();
        });

        canvas.addEventListener('dblclick', (e) => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;

            const mouseX = (e.clientX - rect.left) * scaleX;
            const mouseY = (e.clientY - rect.top) * scaleY;

            for (let i = siteFloorPlanObjects.length - 1; i >= 0; i--) {
                const obj = siteFloorPlanObjects[i];
                const effW = obj.w * (obj.scale || 1.0);
                const effH = obj.h * (obj.scale || 1.0);

                const centerX = obj.x + (effW / 2);
                const centerY = obj.y + (effH / 2);

                const rotRad = (obj.rot || 0) * Math.PI / 180;
                const dx = mouseX - centerX;
                const dy = mouseY - centerY;

                const localX = dx * Math.cos(-rotRad) - dy * Math.sin(-rotRad);
                const localY = dx * Math.sin(-rotRad) + dy * Math.cos(-rotRad);

                if (Math.abs(localX) <= effW / 2 && Math.abs(localY) <= effH / 2) {
                    abrirModalEditarObjeto2D(obj);
                    break;
                }
            }
        });

        let isFPDragFramePending = false;

        canvas.addEventListener('mousemove', (e) => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;

            const mouseX = (e.clientX - rect.left) * scaleX;
            const mouseY = (e.clientY - rect.top) * scaleY;

            // Arrastre para Rotación directa en el Canvas
            if (isRotatingFloorPlanObj && selectedFloorPlanObj) {
                const effW = selectedFloorPlanObj.w * (selectedFloorPlanObj.scale || 1.0);
                const effH = selectedFloorPlanObj.h * (selectedFloorPlanObj.scale || 1.0);
                const centerX = selectedFloorPlanObj.x + (effW / 2);
                const centerY = selectedFloorPlanObj.y + (effH / 2);

                const angleRad = Math.atan2(mouseY - centerY, mouseX - centerX);
                let deg = Math.round((angleRad * 180 / Math.PI) + 90);
                deg = (deg % 360 + 360) % 360;

                if (e.shiftKey) deg = Math.round(deg / 15) * 15; // Ajustar a 15° si se mantiene Shift

                selectedFloorPlanObj.rot = deg;
                sincronizarPanelEditorObjeto2D();
                drawSiteFloorPlan2D();
                sincronizar3DDesdePaleta(selectedFloorPlanObj);
                return;
            }

            // Arrastre para Redimensionar directo en el Canvas
            if (isResizingFloorPlanObj && selectedFloorPlanObj) {
                const effW = selectedFloorPlanObj.w * (selectedFloorPlanObj.scale || 1.0);
                const effH = selectedFloorPlanObj.h * (selectedFloorPlanObj.scale || 1.0);
                const centerX = selectedFloorPlanObj.x + (effW / 2);
                const centerY = selectedFloorPlanObj.y + (effH / 2);
                const rotRad = (selectedFloorPlanObj.rot || 0) * Math.PI / 180;
                const dx = mouseX - centerX;
                const dy = mouseY - centerY;

                const localX = dx * Math.cos(-rotRad) - dy * Math.sin(-rotRad);
                const localY = dx * Math.sin(-rotRad) + dy * Math.cos(-rotRad);

                const newW = Math.max(20, Math.round(Math.abs(localX) * 2));
                const newH = Math.max(15, Math.round(Math.abs(localY) * 2));

                selectedFloorPlanObj.w = newW;
                selectedFloorPlanObj.h = newH;

                sincronizarPanelEditorObjeto2D();
                drawSiteFloorPlan2D();
                sincronizar3DDesdePaleta(selectedFloorPlanObj);
                return;
            }

            // Arrastre de posición (Mover Objeto)
            if (!isDraggingFloorPlanObj || !selectedFloorPlanObj) return;

            const deseadaX = mouseX - floorPlanDragOffset.x;
            const deseadaY = mouseY - floorPlanDragOffset.y;

            const posSegura = calcularPosicionSeguraComponente(
                deseadaX,
                deseadaY,
                selectedFloorPlanObj,
                { anchoMax: canvas.width, profundidadMax: canvas.height, padding: 5 },
                { usarSnapGrid: true, tamanoGrid: 10, pivot: 'top-left' }
            );

            selectedFloorPlanObj.x = posSegura.x;
            selectedFloorPlanObj.y = posSegura.y;

            sincronizarPanelEditorObjeto2D();

            if (!isFPDragFramePending) {
                isFPDragFramePending = true;
                requestAnimationFrame(() => {
                    drawSiteFloorPlan2D();
                    if (selectedFloorPlanObj) {
                        sincronizar3DDesdePaleta(selectedFloorPlanObj);
                        if (selectedFloorPlanObj.type === 'rack') {
                            drawSite2DRackElevation('siteCanvas2DConfig');
                        }
                    }
                    isFPDragFramePending = false;
                });
            }
        });
    });

    window.addEventListener('mouseup', () => {
        if (isDraggingFloorPlanObj || isResizingFloorPlanObj || isRotatingFloorPlanObj) {
            isDraggingFloorPlanObj = false;
            isResizingFloorPlanObj = false;
            isRotatingFloorPlanObj = false;
            sincronizarPanelEditorObjeto2D();
            drawSiteFloorPlan2D();
            drawSite2DRackElevation('siteCanvas2D');
            drawSite2DRackElevation('siteCanvas2DConfig');
            sincronizarEscena3DDesde2D();
        }
    });
}

function drawSiteFloorPlan2D() {
    ['siteFloorPlanCanvas2D', 'siteFloorPlanCanvas2DConfig'].forEach(canvasId => {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const w = canvas.width;
        const h = canvas.height;

        ctx.fillStyle = '#061325';
        ctx.fillRect(0, 0, w, h);

        ctx.strokeStyle = 'rgba(56, 189, 248, 0.1)';
        ctx.lineWidth = 1;
        const gridSize = 25;
        for (let x = 0; x < w; x += gridSize) {
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
        }
        for (let y = 0; y < h; y += gridSize) {
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
        }

        ctx.strokeStyle = '#00f2fe';
        ctx.lineWidth = 4;
        ctx.strokeRect(15, 15, w - 30, h - 30);

        ctx.fillStyle = 'rgba(56, 189, 248, 0.5)';
        ctx.font = 'bold 11px font-monospace';
        ctx.fillText('PARED TRASERA SITE (CLIMATIZACIÓN)', 180, 30);
        ctx.fillText('PASILLO DE SERVICIO RACKS', 200, h - 20);

        siteFloorPlanObjects.forEach(obj => {
            ctx.save();

            const effW = obj.w * (obj.scale || 1.0);
            const effH = obj.h * (obj.scale || 1.0);

            const centerX = obj.x + (effW / 2);
            const centerY = obj.y + (effH / 2);

            ctx.translate(centerX, centerY);
            ctx.rotate((obj.rot || 0) * Math.PI / 180);

            const drawX = -effW / 2;
            const drawY = -effH / 2;

            if (obj.type === 'rack') {
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(drawX, drawY, effW, effH);

                ctx.strokeStyle = obj.color || '#38bdf8';
                ctx.lineWidth = 2.5;
                ctx.strokeRect(drawX, drawY, effW, effH);

                ctx.fillStyle = '#00f2fe';
                ctx.fillRect(drawX, drawY + effH - 4, effW, 4);

                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 10px monospace';
                ctx.textAlign = 'center';
                ctx.fillText(obj.name.split(':')[0], 0, -4);
                ctx.fillStyle = '#38bdf8';
                ctx.font = '9px monospace';
                ctx.fillText('42U RACK', 0, 10);
            } else if (obj.type === 'minisplit') {
                ctx.fillStyle = '#f8fafc';
                ctx.fillRect(drawX, drawY, effW, effH);

                ctx.strokeStyle = '#38bdf8';
                ctx.lineWidth = 2;
                ctx.strokeRect(drawX, drawY, effW, effH);

                ctx.fillStyle = '#0f172a';
                ctx.fillRect(drawX + 4, drawY + 4, effW - 8, effH - 8);

                ctx.fillStyle = 'rgba(0, 242, 254, 0.3)';
                ctx.beginPath();
                ctx.moveTo(drawX + 10, drawY + effH);
                ctx.lineTo(drawX - 10, drawY + effH + 25);
                ctx.lineTo(drawX + effW + 10, drawY + effH + 25);
                ctx.lineTo(drawX + effW - 10, drawY + effH);
                ctx.fill();

                ctx.fillStyle = '#00f2fe';
                ctx.font = 'bold 10px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('18°C ❄️ AIR AC', 0, 4);
            } else if (obj.type === 'extintor') {
                ctx.fillStyle = '#dc2626';
                ctx.beginPath(); ctx.arc(0, 0, effW / 2, 0, Math.PI * 2); ctx.fill();
                ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 2; ctx.stroke();

                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('🧯', 0, 4);
            } else if (obj.type === 'puerta') {
                ctx.fillStyle = '#15803d';
                ctx.fillRect(drawX, drawY, effW, effH);
                ctx.strokeStyle = '#22c55e';
                ctx.lineWidth = 2;
                ctx.strokeRect(drawX, drawY, effW, effH);

                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 10px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('🚪 ENTRADA SITE', 0, 4);
            } else {
                ctx.fillStyle = '#1e293b';
                ctx.fillRect(drawX, drawY, effW, effH);
                ctx.strokeStyle = '#94a3b8';
                ctx.lineWidth = 2;
                ctx.strokeRect(drawX, drawY, effW, effH);

                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 10px monospace';
                ctx.textAlign = 'center';
                ctx.fillText(obj.name, 0, 4);
            }

            if (selectedFloorPlanObj === obj) {
                ctx.strokeStyle = '#eab308';
                ctx.lineWidth = 2;
                ctx.setLineDash([4, 4]);
                ctx.strokeRect(drawX - 4, drawY - 4, effW + 8, effH + 8);
                ctx.setLineDash([]);

                // 4 Esquinas de cambio de tamaño (Resize handles)
                ctx.fillStyle = '#eab308';
                ctx.fillRect(drawX - 8, drawY - 8, 8, 8);
                ctx.fillRect(drawX + effW, drawY - 8, 8, 8);
                ctx.fillRect(drawX - 8, drawY + effH, 8, 8);
                ctx.fillRect(drawX + effW, drawY + effH, 8, 8);

                // Tirador de rotación (Línea + botón circular cyan superior)
                ctx.beginPath();
                ctx.moveTo(0, drawY - 4);
                ctx.lineTo(0, drawY - 22);
                ctx.strokeStyle = '#38bdf8';
                ctx.lineWidth = 2;
                ctx.stroke();

                ctx.beginPath();
                ctx.arc(0, drawY - 22, 7, 0, Math.PI * 2);
                ctx.fillStyle = '#38bdf8';
                ctx.fill();
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 1.5;
                ctx.stroke();

                ctx.fillStyle = '#0f172a';
                ctx.font = 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('↻', 0, drawY - 19);
            }

            ctx.restore();
        });
    });
}

function renderListaComponentesActivos() {
    const container = document.getElementById('listaComponentesActivos');
    const badge = document.getElementById('badgeTotalComp');

    if (badge) badge.textContent = siteFloorPlanObjects.length;

    if (!container) return;

    if (siteFloorPlanObjects.length === 0) {
        container.innerHTML = `
            <div class="text-center text-secondary py-3 small" style="font-size: 0.7rem;">
                No hay elementos en el plano. Agrega Racks o equipos desde la paleta.
            </div>
        `;
        return;
    }

    let html = '';
    siteFloorPlanObjects.forEach((obj, idx) => {
        const isSel = selectedFloorPlanObj === obj;
        const iconClass = obj.type === 'rack' ? 'bi-server text-info' :
                          obj.type === 'minisplit' ? 'bi-snow text-cyan' :
                          obj.type === 'extintor' ? 'bi-fire text-danger' :
                          obj.type === 'puerta' ? 'bi-door-open-fill text-success' : 'bi-pc-display text-warning';

        html += `
            <div class="d-flex align-items-center justify-content-between p-1.5 rounded-3 border ${isSel ? 'bg-warning bg-opacity-15 border-warning' : 'bg-dark bg-opacity-70 border-secondary border-opacity-30'}" style="cursor: pointer;" onclick="seleccionarComponentePorIndice(${idx})">
                <div class="d-flex align-items-center gap-1.5 overflow-hidden">
                    <i class="bi ${iconClass} fs-6"></i>
                    <div class="text-truncate">
                        <span class="fw-semibold text-white d-block" style="font-size: 0.68rem;">${obj.name}</span>
                        <span class="text-secondary font-monospace d-block" style="font-size: 0.6rem;">${obj.type} (X:${Math.round(obj.x)}, Y:${Math.round(obj.y)})</span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-xs ${obj.isLocked ? 'btn-warning text-dark' : 'btn-outline-secondary text-secondary'} py-0 px-1" onclick="event.stopPropagation(); toggleLockObj(${idx});" title="Bloquear / Desbloquear">
                        <i class="bi ${obj.isLocked ? 'bi-lock-fill' : 'bi-unlock'}"></i>
                    </button>
                    <button type="button" class="btn btn-xs ${obj.isHidden ? 'btn-danger' : 'btn-outline-secondary text-secondary'} py-0 px-1" onclick="event.stopPropagation(); toggleHideObj(${idx});" title="Ocultar / Mostrar">
                        <i class="bi ${obj.isHidden ? 'bi-eye-slash-fill' : 'bi-eye'}"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-info py-0 px-1" onclick="event.stopPropagation(); seleccionarComponentePorIndice(${idx});" title="Centrar / Seleccionar">
                        <i class="bi bi-crosshair"></i>
                    </button>
                    <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" onclick="event.stopPropagation(); eliminarComponentePorIndice(${idx});" title="Eliminar">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function seleccionarComponentePorIndice(idx) {
    if (siteFloorPlanObjects[idx]) {
        selectedFloorPlanObj = siteFloorPlanObjects[idx];
        sincronizarPanelEditorObjeto2D();
        drawSiteFloorPlan2D();
        sincronizar3DDesdePaleta(selectedFloorPlanObj);
    }
}

function toggleLockObj(idx) {
    if (siteFloorPlanObjects[idx]) {
        siteFloorPlanObjects[idx].isLocked = !siteFloorPlanObjects[idx].isLocked;
        renderListaComponentesActivos();
    }
}

function toggleHideObj(idx) {
    if (siteFloorPlanObjects[idx]) {
        siteFloorPlanObjects[idx].isHidden = !siteFloorPlanObjects[idx].isHidden;
        renderListaComponentesActivos();
        drawSiteFloorPlan2D();
    }
}

function eliminarComponentePorIndice(idx) {
    if (siteFloorPlanObjects[idx]) {
        const obj = siteFloorPlanObjects[idx];
        if (obj.type === 'rack' || (obj.id && String(obj.id).toLowerCase().includes('rack'))) {
            delete siteRacksData[obj.id];
            const normId = String(obj.id).replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
            Object.keys(siteRacksData).forEach(k => {
                if (k.replace(/[^a-zA-Z0-9]/g, '').toLowerCase() === normId || (siteRacksData[k] && siteRacksData[k].title === obj.name)) {
                    delete siteRacksData[k];
                }
            });
        }
        if (selectedFloorPlanObj === obj) {
            selectedFloorPlanObj = null;
        }
        siteFloorPlanObjects.splice(idx, 1);
        const remainingRacks = siteFloorPlanObjects.filter(o => o && (o.type === 'rack' || (o.id && String(o.id).toLowerCase().includes('rack'))));
        if (remainingRacks.length === 0) {
            siteRacksData = {};
        }
        if (typeof window !== 'undefined') window.site2DObjects = siteFloorPlanObjects;
        sincronizarPanelEditorObjeto2D();
        drawSiteFloorPlan2D();
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
        sincronizarEscena3DDesde2D();
        mostrarNotificacionToast('Elemento Eliminado', `Se eliminó "${obj.name || 'Componente'}" del plano.`);
    }
}

function vaciarTodosComponentesSite() {
    if (confirm('¿Estás seguro de que deseas eliminar TODOS los componentes del lienzo del SITE?')) {
        siteFloorPlanObjects = [];
        siteRacksData = {};
        selectedFloorPlanObj = null;
        sincronizarPanelEditorObjeto2D();
        drawSiteFloorPlan2D();
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
        sincronizarEscena3DDesde2D();
        mostrarNotificacionToast('Lienzo Limpio', 'Se han eliminado todos los objetos del plano.');
    }
}

function duplicarObjetoSeleccionado() {
    if (!selectedFloorPlanObj) return;

    const cloneObj = JSON.parse(JSON.stringify(selectedFloorPlanObj));
    cloneObj.id = `obj_${Date.now()}`;
    cloneObj.name = `${selectedFloorPlanObj.name} (Copia)`;
    cloneObj.x += 30;
    cloneObj.y += 30;

    siteFloorPlanObjects.push(cloneObj);
    selectedFloorPlanObj = cloneObj;
    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');
    sincronizarEscena3DDesde2D();
}

function rotarPresetObj(degDelta) {
    if (!selectedFloorPlanObj) return;

    let currentRot = selectedFloorPlanObj.rot || 0;
    currentRot = (currentRot + degDelta) % 360;
    if (currentRot < 0) currentRot += 360;

    selectedFloorPlanObj.rot = currentRot;
    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    sincronizar3DDesdePaleta(selectedFloorPlanObj);
}

function sincronizarPanelEditorObjeto2D() {
    renderListaComponentesActivos();

    const panelVacio2D = document.getElementById('panelVacioPropiedades2D');
    const panelEdicion2D = document.getElementById('panelEdicionObjeto2D');

    const panelVacioPaleta = document.getElementById('panelVacioPaletaProps');
    const panelEdicionPaleta = document.getElementById('panelEdicionPaletaProps');

    const btnQuickEdit = document.getElementById('btnEditarCompRapido2D');

    if (!selectedFloorPlanObj) {
        if (panelVacio2D) panelVacio2D.style.display = 'block';
        if (panelEdicion2D) panelEdicion2D.style.display = 'none';
        if (panelVacioPaleta) panelVacioPaleta.style.display = 'block';
        if (panelEdicionPaleta) panelEdicionPaleta.style.display = 'none';
        if (btnQuickEdit) btnQuickEdit.style.display = 'none';
        return;
    }

    if (panelVacio2D) panelVacio2D.style.display = 'none';
    if (panelEdicion2D) panelEdicion2D.style.display = 'block';
    if (panelVacioPaleta) panelVacioPaleta.style.display = 'none';
    if (panelEdicionPaleta) panelEdicionPaleta.style.display = 'block';
    if (btnQuickEdit) btnQuickEdit.style.display = 'inline-flex';

    const inputName = document.getElementById('inputNombreObj2D');
    const inputW = document.getElementById('inputAnchoObj2D');
    const inputH = document.getElementById('inputLargoObj2D');
    const inputX = document.getElementById('inputPosXObj2D');
    const inputY = document.getElementById('inputPosYObj2D');
    const inputZ = document.getElementById('inputPosZObj2D');

    if (inputName) inputName.value = selectedFloorPlanObj.name;
    if (inputW) inputW.value = Math.round(selectedFloorPlanObj.w);
    if (inputH) inputH.value = Math.round(selectedFloorPlanObj.h);
    if (inputX) inputX.value = Math.round(selectedFloorPlanObj.x);
    if (inputY) inputY.value = Math.round(selectedFloorPlanObj.y);
    if (inputZ) inputZ.value = selectedFloorPlanObj.z || 0;

    const pName = document.getElementById('inputPaletaNombre');
    const pX = document.getElementById('inputPaletaX');
    const pY = document.getElementById('inputPaletaY');
    const pAncho = document.getElementById('inputPaletaAncho');
    const pAlto = document.getElementById('inputPaletaAlto');
    const pProf = document.getElementById('inputPaletaProf');
    const sliderElev = document.getElementById('sliderElevacionSuelo');
    const labelElev = document.getElementById('labelElevacionSueloVal');
    const pRot = document.getElementById('sliderPaletaRot');
    const pLabelRot = document.getElementById('labelPaletaRotVal');
    const sliderIncX = document.getElementById('sliderIncX');
    const labelIncX = document.getElementById('labelIncXVal');
    const sliderIncZ = document.getElementById('sliderIncZ');
    const labelIncZ = document.getElementById('labelIncZVal');
    const selectTipo = document.getElementById('selectTipoCompPaleta');
    const pColor = document.getElementById('pickerPaletaColor');
    const pLabelColor = document.getElementById('labelColorVal');

    if (pName) pName.value = selectedFloorPlanObj.name;
    if (pX) pX.value = Math.round(selectedFloorPlanObj.x);
    if (pY) pY.value = Math.round(selectedFloorPlanObj.y);
    if (pAncho) pAncho.value = Math.round(selectedFloorPlanObj.w);
    if (pAlto) pAlto.value = Math.round(selectedFloorPlanObj.h);
    if (pProf) pProf.value = Math.round(selectedFloorPlanObj.prof || 50);
    if (sliderElev) sliderElev.value = selectedFloorPlanObj.z || 0;
    if (labelElev) labelElev.textContent = (selectedFloorPlanObj.z || 0) + 'px';
    if (pRot) pRot.value = selectedFloorPlanObj.rot || 0;
    if (pLabelRot) pLabelRot.textContent = (selectedFloorPlanObj.rot || 0) + '°';
    if (sliderIncX) sliderIncX.value = selectedFloorPlanObj.incX || 0;
    if (labelIncX) labelIncX.textContent = (selectedFloorPlanObj.incX || 0) + '°';
    if (sliderIncZ) sliderIncZ.value = selectedFloorPlanObj.incZ || 0;
    if (labelIncZ) labelIncZ.textContent = (selectedFloorPlanObj.incZ || 0) + '°';
    if (selectTipo) selectTipo.value = selectedFloorPlanObj.type || 'rack';
    if (pColor) pColor.value = selectedFloorPlanObj.color || '#38bdf8';
    if (pLabelColor) pLabelColor.textContent = selectedFloorPlanObj.color || '#38bdf8';
}

function actualizarObjetoDesdePaleta() {
    if (!selectedFloorPlanObj) return;

    const pName = document.getElementById('inputPaletaNombre');
    const pX = document.getElementById('inputPaletaX');
    const pY = document.getElementById('inputPaletaY');
    const pAncho = document.getElementById('inputPaletaAncho');
    const pAlto = document.getElementById('inputPaletaAlto');
    const pProf = document.getElementById('inputPaletaProf');
    const sliderElev = document.getElementById('sliderElevacionSuelo');
    const labelElev = document.getElementById('labelElevacionSueloVal');
    const pRot = document.getElementById('sliderPaletaRot');
    const pLabelRot = document.getElementById('labelPaletaRotVal');
    const sliderIncX = document.getElementById('sliderIncX');
    const labelIncX = document.getElementById('labelIncXVal');
    const sliderIncZ = document.getElementById('sliderIncZ');
    const labelIncZ = document.getElementById('labelIncZVal');
    const selectTipo = document.getElementById('selectTipoCompPaleta');
    const pColor = document.getElementById('pickerPaletaColor');
    const pLabelColor = document.getElementById('labelColorVal');

    if (pName) selectedFloorPlanObj.name = pName.value;
    if (pX) selectedFloorPlanObj.x = parseFloat(pX.value || 0);
    if (pY) selectedFloorPlanObj.y = parseFloat(pY.value || 0);
    const isMinisplitType = selectedFloorPlanObj.type === 'minisplit';
    const isExtintorType = selectedFloorPlanObj.type === 'extintor';
    const isPuertaType = selectedFloorPlanObj.type === 'puerta';

    if (pAncho) selectedFloorPlanObj.w = parseFloat(pAncho.value || (isMinisplitType ? 110 : (isExtintorType ? 36 : (isPuertaType ? 90 : 75))));
    if (pAlto) selectedFloorPlanObj.h = parseFloat(pAlto.value || (isMinisplitType ? 28 : (isExtintorType ? 36 : (isPuertaType ? 18 : 110))));
    if (pProf) selectedFloorPlanObj.prof = parseFloat(pProf.value || 50);
    if (sliderElev) {
        selectedFloorPlanObj.z = parseFloat(sliderElev.value || 0);
        if (labelElev) labelElev.textContent = selectedFloorPlanObj.z + 'px';
    }
    if (pRot) {
        selectedFloorPlanObj.rot = parseInt(pRot.value || 0);
        if (pLabelRot) pLabelRot.textContent = selectedFloorPlanObj.rot + '°';
    }
    if (sliderIncX) {
        selectedFloorPlanObj.incX = parseInt(sliderIncX.value || 0);
        if (labelIncX) labelIncX.textContent = selectedFloorPlanObj.incX + '°';
    }
    if (sliderIncZ) {
        selectedFloorPlanObj.incZ = parseInt(sliderIncZ.value || 0);
        if (labelIncZ) labelIncZ.textContent = selectedFloorPlanObj.incZ + '°';
    }
    if (selectTipo) selectedFloorPlanObj.type = selectTipo.value;
    if (pColor) {
        selectedFloorPlanObj.color = pColor.value;
        if (pLabelColor) pLabelColor.textContent = pColor.value;
    }

    if (selectedFloorPlanObj.type === 'rack' && siteRacksData[selectedFloorPlanObj.id]) {
        siteRacksData[selectedFloorPlanObj.id].title = selectedFloorPlanObj.name;
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
    }

    drawSiteFloorPlan2D();
    sincronizar3DDesdePaleta(selectedFloorPlanObj);
    renderListaComponentesActivos();
}

function setColorPaleta(colorHex) {
    const pColor = document.getElementById('pickerPaletaColor');
    if (pColor) {
        pColor.value = colorHex;
        actualizarObjetoDesdePaleta();
    }
}

function eliminarObjetoPaletaSeleccionado() {
    eliminarObjeto2DSeleccionado();
}

function crearComponenteDesdeObjetoFloorPlan3D(obj) {
    if (typeof THREE === 'undefined' || !siteScene3D || !obj) return null;
    if (obj.type === 'rack' || obj.type === 'cota' || obj.type === 'linea_cota') return null;

    const group = new THREE.Group();
    group.position.set(pos2DTo3DX(obj.x), (obj.z || 0) / 10, pos2DTo3DZ(obj.y));
    group.rotation.y = ((obj.rot || 0) * Math.PI) / 180;
    if (obj.scale) group.scale.set(obj.scale, obj.scale, obj.scale);
    group.userData = { id: obj.id, name: obj.name, type: obj.type, isEquip: true };

    if (obj.type === 'minisplit') {
        const msModel = crearModeloMinisplitRealista3D(obj.name || 'Aire Acondicionado Minisplit');
        group.add(msModel);
    } else if (obj.type === 'extintor' || obj.type === 'extintor_verde') {
        const color = obj.type === 'extintor_verde' ? 0x16a34a : 0xdc2626;
        const extMat = new THREE.MeshStandardMaterial({ color: color, metalness: 0.7, roughness: 0.3 });
        const bodyGeo = new THREE.CylinderGeometry(0.45, 0.45, 2.2, 16);
        const body = new THREE.Mesh(bodyGeo, extMat);
        body.position.y = 1.1;
        group.add(body);
        const silverMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, metalness: 0.9 });
        const valve = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 0.4, 8), silverMat);
        valve.position.set(0, 2.3, 0);
        group.add(valve);
    } else if (obj.type === 'libreta' || obj.type === 'bitacora') {
        const lib = crearBitacora3D(0, 0, 0, obj);
        if (lib) group.add(lib);
    } else if (obj.type === 'detector_humo') {
        const humo = crearDetectorHumo3D(0, 0, 0, obj);
        if (humo) group.add(humo);
    } else if (obj.type === 'termometro_digital') {
        const term = crearTermometroDigital3D(0, 0, 0, obj);
        if (term) group.add(term);
    } else if (obj.type === 'camara_seguridad' || obj.type === 'camara' || obj.type === 'cctv') {
        const cam = crearCamaraSeguridad3D(0, 0, 0, obj);
        if (cam) group.add(cam);
    } else if (obj.type === 'pared' || obj.type === 'muro') {
        const wall = crearPared3D(0, 0, 0, obj);
        if (wall) group.add(wall);
    } else if (obj.type === 'piso' || obj.type === 'piso_tecnico') {
        const floor = crearPisoTecnico3D(0, 0, 0, obj);
        if (floor) group.add(floor);
    } else if (obj.type === 'puerta' || obj.type === 'puerta_deslizable') {
        const door = crearPuertaDeslizable3D(0, 0, 0, obj);
        if (door) group.add(door);
    } else {
        const textureFront = crearTexturaFrontalCanvas(obj.type || 'rack', obj.name || 'EQUIPO');
        const matColor = obj.color || '#38bdf8';
        const materials = [
            new THREE.MeshStandardMaterial({ color: matColor, metalness: 0.7, roughness: 0.3 }),
            new THREE.MeshStandardMaterial({ color: matColor, metalness: 0.7, roughness: 0.3 }),
            new THREE.MeshStandardMaterial({ color: matColor, metalness: 0.7, roughness: 0.3 }),
            new THREE.MeshStandardMaterial({ color: matColor, metalness: 0.7, roughness: 0.3 }),
            new THREE.MeshStandardMaterial({ map: textureFront, metalness: 0.2, roughness: 0.4 }),
            new THREE.MeshStandardMaterial({ color: matColor, metalness: 0.7, roughness: 0.3 })
        ];
        const geo = new THREE.BoxGeometry(3.3, (obj.h || 100) / 25, 3.2);
        const mesh = new THREE.Mesh(geo, materials);
        mesh.position.set(0, 1.0, 0);
        mesh.userData = { isEquip: true, name: obj.name, type: obj.type };
        group.add(mesh);
    }

    siteScene3D.add(group);
    return group;
}

function sincronizar3DDesdePaleta(obj) {
    if (typeof THREE === 'undefined' || !siteScene3D || !obj) return;
    if (obj.type === 'cota' || obj.type === 'linea_cota') return;

    let found = false;
    siteScene3D.children.forEach(child => {
        if (child.userData && (child.userData.id === obj.id || child.userData.rackKey === obj.id || child.userData.name === obj.name)) {
            found = true;
            child.position.x = pos2DTo3DX(obj.x);
            child.position.y = (obj.z || 0) / 10;
            child.position.z = pos2DTo3DZ(obj.y);

            if (obj.rot !== undefined) {
                child.rotation.y = (obj.rot * Math.PI) / 180;
            }

            const scaleX = ((obj.w || 70) / 70) * (obj.scale || 1.0);
            const scaleZ = ((obj.h || 100) / 100) * (obj.scale || 1.0);
            const scaleY = ((obj.prof || 50) / 50) * (obj.scale || 1.0);
            child.scale.set(scaleX, scaleY, scaleZ);

            if (obj.type !== 'minisplit' && obj.type !== 'extintor' && obj.type !== 'extintor_verde' && obj.type !== 'libreta' && obj.type !== 'bitacora' && obj.type !== 'detector_humo' && obj.type !== 'termometro_digital' && obj.type !== 'camara_seguridad' && obj.type !== 'camara' && obj.type !== 'cctv' && obj.type !== 'pared' && obj.type !== 'muro' && obj.type !== 'piso' && obj.type !== 'piso_tecnico' && obj.type !== 'puerta' && obj.type !== 'puerta_deslizable') {
                child.traverse(mesh => {
                    if (mesh.isMesh && mesh.material && !mesh.userData?.isTextureCanvas) {
                        if (Array.isArray(mesh.material)) {
                            mesh.material.forEach(m => { if (m.color && !m.userData?.isTextureCanvas) m.color.set(obj.color); });
                        } else if (mesh.material.color) {
                            mesh.material.color.set(obj.color);
                        }
                    }
                });
            }
        }
    });

    if (!found) {
        crearComponenteDesdeObjetoFloorPlan3D(obj);
    }

    if (siteRenderer3D && siteCamera3D) {
        siteRenderer3D.render(siteScene3D, siteCamera3D);
    }
}

function actualizarObjeto2DDesdePanel() {
    if (!selectedFloorPlanObj) return;

    const inputName = document.getElementById('inputNombreObj2D');
    const inputW = document.getElementById('inputAnchoObj2D');
    const inputH = document.getElementById('inputLargoObj2D');
    const inputX = document.getElementById('inputPosXObj2D');
    const inputY = document.getElementById('inputPosYObj2D');
    const inputZ = document.getElementById('inputPosZObj2D');
    const sliderEscala = document.getElementById('sliderEscalaObj2D');
    const labelEscala = document.getElementById('labelEscala2DVal');

    if (inputName) selectedFloorPlanObj.name = inputName.value;
    if (inputW) selectedFloorPlanObj.w = Math.max(10, parseFloat(inputW.value || selectedFloorPlanObj.w || 70));
    if (inputH) selectedFloorPlanObj.h = Math.max(10, parseFloat(inputH.value || selectedFloorPlanObj.h || 100));
    if (inputX) selectedFloorPlanObj.x = parseFloat(inputX.value || selectedFloorPlanObj.x || 0);
    if (inputY) selectedFloorPlanObj.y = parseFloat(inputY.value || selectedFloorPlanObj.y || 0);
    if (inputZ) selectedFloorPlanObj.z = parseFloat(inputZ.value || 0);

    if (sliderEscala) {
        const val = parseFloat(sliderEscala.value || 1.0);
        selectedFloorPlanObj.scale = val;
        if (labelEscala) labelEscala.textContent = val.toFixed(1) + 'x';
    }

    if (selectedFloorPlanObj.type === 'rack' && siteRacksData[selectedFloorPlanObj.id]) {
        siteRacksData[selectedFloorPlanObj.id].title = selectedFloorPlanObj.name;
    }

    drawSiteFloorPlan2D();
    sincronizar3DDesdePaleta(selectedFloorPlanObj);
    renderListaComponentesActivos();
}

function rotarObjeto2D(deg) {
    if (!selectedFloorPlanObj) return;
    selectedFloorPlanObj.rot = deg;
    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    sincronizar3DDesdePaleta(selectedFloorPlanObj);
}

function eliminarObjeto2DSeleccionado() {
    if (!selectedFloorPlanObj) return;
    const idx = siteFloorPlanObjects.indexOf(selectedFloorPlanObj);
    if (idx !== -1) {
        const obj = selectedFloorPlanObj;
        if (obj.type === 'rack' || (obj.id && String(obj.id).toLowerCase().includes('rack'))) {
            delete siteRacksData[obj.id];
            const normId = String(obj.id).replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
            Object.keys(siteRacksData).forEach(k => {
                if (k.replace(/[^a-zA-Z0-9]/g, '').toLowerCase() === normId || (siteRacksData[k] && siteRacksData[k].title === obj.name)) {
                    delete siteRacksData[k];
                }
            });
        }
        siteFloorPlanObjects.splice(idx, 1);
        selectedFloorPlanObj = null;
        const remainingRacks = siteFloorPlanObjects.filter(o => o && (o.type === 'rack' || (o.id && String(o.id).toLowerCase().includes('rack'))));
        if (remainingRacks.length === 0) {
            siteRacksData = {};
        }
        if (typeof window !== 'undefined') window.site2DObjects = siteFloorPlanObjects;
        sincronizarPanelEditorObjeto2D();
        drawSiteFloorPlan2D();
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
        sincronizarEscena3DDesde2D();
        mostrarNotificacionToast('Elemento Eliminado', `Se eliminó "${obj.name || 'Componente'}" del plano.`);
    }
}

function agregarNuevoElementoPlano2D() {
    const newObj = {
        id: `obj_${Date.now()}`,
        name: 'NUEVO RACK / EQUIPO',
        type: 'rack',
        x: 200 + Math.random() * 100,
        y: 200 + Math.random() * 100,
        w: 70,
        h: 100,
        rot: 0,
        scale: 1.0,
        color: '#eab308'
    };
    siteFloorPlanObjects.push(newObj);
    selectedFloorPlanObj = newObj;
    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
}

function onSiteFloorPlan2DDrop(e) {
    e.preventDefault();
    const toolType = e.dataTransfer.getData('text/plain');
    if (!toolType) return;

    const canvas = e.currentTarget || e.target || document.getElementById('siteFloorPlanCanvas2D');
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;

    const dropX = (e.clientX - rect.left) * scaleX;
    const dropY = (e.clientY - rect.top) * scaleY;

    const namesMap = {
        'minisplit': 'MINISPLIT INVERTER 18°C ❄️',
        'extintor': 'EXTINTOR SOLKAFLAM 🧯',
        'fortinet': 'FORTINET FIREWALL GDL',
        'ups': 'UPS RESPALDO 10KVA',
        'switch_principal': 'SWITCH CORE 10G'
    };

    const isMinisplit = toolType.includes('minisplit');
    const isExtintor = toolType.includes('extintor');
    const w = isMinisplit ? 110 : (isExtintor ? 36 : 75);
    const h = isMinisplit ? 28 : (isExtintor ? 36 : 110);

    const tempObj = {
        w: w,
        h: h,
        scale: 1.0,
        rot: 0
    };

    const posSegura = calcularPosicionSeguraComponente(
        dropX - (w / 2),
        dropY - (h / 2),
        tempObj,
        { anchoMax: canvas.width, profundidadMax: canvas.height, padding: 5 },
        { usarSnapGrid: true, tamanoGrid: 10, pivot: 'top-left' }
    );

    const newObj = {
        id: `obj_${Date.now()}`,
        name: namesMap[toolType] || toolType.toUpperCase(),
        type: isMinisplit ? 'minisplit' : (isExtintor ? 'extintor' : 'rack'),
        x: posSegura.x,
        y: posSegura.y,
        w: w,
        h: h,
        rot: 0,
        scale: 1.0,
        color: isMinisplit ? '#38bdf8' : (isExtintor ? '#ef4444' : '#00f2fe')
    };

    if (newObj.type === 'rack') {
        siteRacksData[newObj.id] = {
            title: newObj.name,
            items: []
        };
    }

    siteFloorPlanObjects.push(newObj);
    selectedFloorPlanObj = newObj;
    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');
    sincronizarEscena3DDesde2D();
    mostrarNotificacionToast('Componente Agregado', `Se colocó "${newObj.name}" exactamente en el plano.`);
}

function agregarComponenteRapido(tipo) {
    const isMinisplit = tipo === 'minisplit';
    const isExtintor = tipo === 'extintor';
    const isPuerta = tipo === 'puerta';
    const isRack = tipo === 'rack';

    const currentRacksCount = Object.keys(siteRacksData || {}).length;

    const namesMap = {
        'rack': `RACK ${currentRacksCount + 1}: BASTIDOR 42U`,
        'minisplit': 'MINISPLIT INVERTER 18°C ❄️',
        'extintor': 'EXTINTOR SOLKAFLAM 🧯',
        'fortinet': 'FORTINET FIREWALL GDL 🛡️',
        'ups': 'UPS RESPALDO 10KVA 🔋',
        'puerta': 'PUERTA ACCESO SITE 🚪'
    };

    const colorsMap = {
        'rack': '#00f2fe',
        'minisplit': '#38bdf8',
        'extintor': '#ef4444',
        'fortinet': '#ec4899',
        'ups': '#eab308',
        'puerta': '#22c55e'
    };

    const w = isMinisplit ? 110 : (isExtintor ? 36 : (isPuerta ? 90 : 75));
    const h = isMinisplit ? 28 : (isExtintor ? 36 : (isPuerta ? 18 : 110));

    const id = isRack ? `rack${currentRacksCount + 1}` : `obj_${Date.now()}`;

    const newObj = {
        id: id,
        name: namesMap[tipo] || tipo.toUpperCase(),
        type: isMinisplit ? 'minisplit' : (isExtintor ? 'extintor' : (isPuerta ? 'puerta' : 'rack')),
        x: 120 + Math.floor(Math.random() * 350),
        y: 100 + Math.floor(Math.random() * 250),
        w: w,
        h: h,
        rot: 0,
        scale: 1.0,
        color: colorsMap[tipo] || '#00f2fe'
    };

    if (newObj.type === 'rack') {
        siteRacksData[newObj.id] = {
            title: newObj.name,
            items: [
                { u: 1, name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' }
            ]
        };
    }

    siteFloorPlanObjects.push(newObj);
    selectedFloorPlanObj = newObj;
    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    drawSite2DRackElevation('siteCanvas2D');
    sincronizarEscena3DDesde2D();
    mostrarNotificacionToast('Componente Agregado', `Se agregó "${newObj.name}" al plano.`);
}

// =========================================================================
// FUNCIONES GLOBALES DE NAVEGACIÓN Y MODALES
// =========================================================================
// HELPER UTILITIES SITE
// =========================================================================

function abrirModalEditar(data) {
    if (!data) return;
    const fId = document.getElementById('form_id');
    if (fId) fId.value = data.id || '0';
    const fNombre = document.getElementById('field_nombre_site');
    if (fNombre) fNombre.value = data.nombre_site || '';
    const fUbic = document.getElementById('field_ubicacion');
    if (fUbic) fUbic.value = data.ubicacion || '';
    const fAire = document.getElementById('field_aire_acondicionado');
    if (fAire) fAire.value = data.aire_acondicionado || '';
    const fBtu = document.getElementById('field_btu');
    if (fBtu) fBtu.value = data.btu || '';
    const fTemp = document.getElementById('field_temperatura_objetivo');
    if (fTemp) fTemp.value = data.temperatura_objetivo || '';
    const fUps = document.getElementById('field_ups_principal');
    if (fUps) fUps.value = data.ups_principal || '';
    const fCapUps = document.getElementById('field_cap_ups');
    if (fCapUps) fCapUps.value = data.cap_ups || '';
    const fAcc = document.getElementById('field_control_acceso');
    if (fAcc) fAcc.value = data.control_acceso || '';
    const fInc = document.getElementById('field_contra_incendio');
    if (fInc) fInc.value = data.contra_incendio || '';
    const fObs = document.getElementById('field_observaciones');
    if (fObs) fObs.value = data.observaciones || '';

    const titleEl = document.getElementById('modalFormTitle');
    if (titleEl) titleEl.innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Ficha Técnica SITE';
    new bootstrap.Modal(document.getElementById('modalInfraForm')).show();
}

function abrirModalExpedienteUsuario(nombre, foto, depto, pto, res, idf, sw) {
    const elNombre = document.getElementById('expNombreUser');
    if (elNombre) elNombre.innerText = nombre || 'Usuario';
    const elDepto = document.getElementById('expDeptoUser');
    if (elDepto) elDepto.innerText = depto || 'N/A';
    const elPto = document.getElementById('expPuestoUser');
    if (elPto) elPto.innerText = pto || 'N/A';
    const elRes = document.getElementById('expResUser');
    if (elRes) elRes.innerText = res || 'N/A';
    const elIdf = document.getElementById('expIdfUser');
    if (elIdf) elIdf.innerText = idf || 'N/A';
    const elSw = document.getElementById('expSwUser');
    if (elSw) elSw.innerText = sw || 'N/A';

    const imgEl = document.getElementById('expFotoUser');
    if (imgEl) {
        if (foto && foto !== '') {
            imgEl.src = foto;
        } else {
            imgEl.src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(nombre || 'Usuario') + '&background=0D8ABC&color=fff';
        }
    }

    new bootstrap.Modal(document.getElementById('modalExpedienteUsuario')).show();
}

function abrirFotoLightbox(url, titulo) {
    const imgEl = document.getElementById('lightboxFotoImg');
    if (imgEl) imgEl.src = url;
    const titleEl = document.getElementById('lightboxFotoTitle');
    if (titleEl) titleEl.innerText = titulo || 'Fotografía de Infraestructura';
    new bootstrap.Modal(document.getElementById('customFotoLightbox')).show();
}

// FUNCIONES DE CONTROL PARA EL MODAL DE EDICIÓN FLOTANTE DE OBJETOS 2D/3D SITE
function abrirModalEditarSeleccionado2D() {
    if (!selectedFloorPlanObj) return;
    abrirModalEditarObjeto2D(selectedFloorPlanObj);
}

function abrirModalEditarObjeto2D(obj) {
    if (!obj) return;
    selectedFloorPlanObj = obj;

    const modalEl = document.getElementById('modalEditarObjeto2D');
    if (!modalEl) return;

    const inpName = document.getElementById('modalInputNombre2D');
    const selTipo = document.getElementById('modalSelectTipo2D');
    const selRot = document.getElementById('modalSelectRot2D');
    const inpAncho = document.getElementById('modalInputAncho2D');
    const inpLargo = document.getElementById('modalInputLargo2D');
    const inpPosX = document.getElementById('modalInputPosX2D');
    const inpPosY = document.getElementById('modalInputPosY2D');
    const inpPosZ = document.getElementById('modalInputPosZ2D');
    const inpColor = document.getElementById('modalInputColor2D');
    const badgeTipo = document.getElementById('modalBadgeTipo2D');

    if (inpName) inpName.value = obj.name || '';
    if (selTipo) selTipo.value = obj.type || 'rack';
    if (selRot) selRot.value = obj.rot || 0;
    if (inpAncho) inpAncho.value = Math.round(obj.w || 75);
    if (inpLargo) inpLargo.value = Math.round(obj.h || 110);
    if (inpPosX) inpPosX.value = Math.round(obj.x || 0);
    if (inpPosY) inpPosY.value = Math.round(obj.y || 0);
    if (inpPosZ) inpPosZ.value = obj.z || 0;
    if (inpColor) inpColor.value = obj.color || (obj.type === 'minisplit' ? '#38bdf8' : (obj.type === 'extintor' ? '#ef4444' : '#00f2fe'));
    if (badgeTipo) badgeTipo.textContent = (obj.type || 'RECURSO').toUpperCase() + ' 2D/3D';

    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
}

function alCambiarTipoEnModal2D() {
    const selTipo = document.getElementById('modalSelectTipo2D');
    if (!selTipo) return;
    const tipo = selTipo.value;

    const inpAncho = document.getElementById('modalInputAncho2D');
    const inpLargo = document.getElementById('modalInputLargo2D');
    const inpColor = document.getElementById('modalInputColor2D');
    const badgeTipo = document.getElementById('modalBadgeTipo2D');

    if (badgeTipo) badgeTipo.textContent = tipo.toUpperCase() + ' 2D/3D';

    if (tipo === 'minisplit') {
        if (inpAncho) inpAncho.value = 100;
        if (inpLargo) inpLargo.value = 28;
        if (inpColor) inpColor.value = '#38bdf8';
    } else if (tipo === 'rack') {
        if (inpAncho) inpAncho.value = 75;
        if (inpLargo) inpLargo.value = 110;
        if (inpColor) inpColor.value = '#00f2fe';
    } else if (tipo === 'extintor') {
        if (inpAncho) inpAncho.value = 36;
        if (inpLargo) inpLargo.value = 36;
        if (inpColor) inpColor.value = '#ef4444';
    } else if (tipo === 'puerta') {
        if (inpAncho) inpAncho.value = 90;
        if (inpLargo) inpLargo.value = 18;
        if (inpColor) inpColor.value = '#22c55e';
    } else if (tipo === 'ups') {
        if (inpAncho) inpAncho.value = 60;
        if (inpLargo) inpLargo.value = 60;
        if (inpColor) inpColor.value = '#eab308';
    }
}

function aplicarTamanoEstandardModal2D() {
    alCambiarTipoEnModal2D();
}

function guardarCambiosModal2D() {
    if (!selectedFloorPlanObj) return;

    const inpName = document.getElementById('modalInputNombre2D');
    const selTipo = document.getElementById('modalSelectTipo2D');
    const selRot = document.getElementById('modalSelectRot2D');
    const inpAncho = document.getElementById('modalInputAncho2D');
    const inpLargo = document.getElementById('modalInputLargo2D');
    const inpPosX = document.getElementById('modalInputPosX2D');
    const inpPosY = document.getElementById('modalInputPosY2D');
    const inpPosZ = document.getElementById('modalInputPosZ2D');
    const inpColor = document.getElementById('modalInputColor2D');

    if (inpName && inpName.value.trim() !== '') selectedFloorPlanObj.name = inpName.value.trim();
    if (selTipo) selectedFloorPlanObj.type = selTipo.value;
    if (selRot) selectedFloorPlanObj.rot = parseInt(selRot.value || '0');
    if (inpAncho) selectedFloorPlanObj.w = Math.max(15, parseFloat(inpAncho.value || '75'));
    if (inpLargo) selectedFloorPlanObj.h = Math.max(15, parseFloat(inpLargo.value || '110'));
    if (inpPosX) selectedFloorPlanObj.x = parseFloat(inpPosX.value || '0');
    if (inpPosY) selectedFloorPlanObj.y = parseFloat(inpPosY.value || '0');
    if (inpPosZ) selectedFloorPlanObj.z = parseFloat(inpPosZ.value || '0');
    if (inpColor) selectedFloorPlanObj.color = inpColor.value;

    if (selectedFloorPlanObj.type === 'rack' && siteRacksData[selectedFloorPlanObj.id]) {
        siteRacksData[selectedFloorPlanObj.id].title = selectedFloorPlanObj.name;
    }

    const modalEl = document.getElementById('modalEditarObjeto2D');
    if (modalEl) {
        const bsModal = bootstrap.Modal.getInstance(modalEl);
        if (bsModal) bsModal.hide();
    }

    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    sincronizar3DDesdePaleta(selectedFloorPlanObj);
    renderListaComponentesActivos();

    if (typeof mostrarNotificacionToast === 'function') {
        mostrarNotificacionToast('success', 'Componente Actualizado', `Se guardaron los cambios para "${selectedFloorPlanObj.name}".`);
    }
}

function eliminarObjetoDesdeModal2D() {
    const modalEl = document.getElementById('modalEditarObjeto2D');
    if (modalEl) {
        const bsModal = bootstrap.Modal.getInstance(modalEl);
        if (bsModal) bsModal.hide();
    }
    eliminarObjeto2DSeleccionado();
}

function cargarPlantillaEstandardSITE() {
    siteFloorPlanObjects = [
        {
            id: 'obj_minisplit_1',
            name: 'MINISPLIT INVERTER 18°C ❄️',
            type: 'minisplit',
            x: 20,
            y: 200,
            w: 100,
            h: 30,
            rot: 90,
            scale: 1.0,
            color: '#38bdf8'
        },
        {
            id: 'obj_rack_1',
            name: 'RACK CORE 01',
            type: 'rack',
            x: 350,
            y: 180,
            w: 80,
            h: 120,
            rot: 0,
            scale: 1.0,
            color: '#38bdf8'
        },
        {
            id: 'obj_rack_2',
            name: 'RACK RED 02',
            type: 'rack',
            x: 480,
            y: 180,
            w: 80,
            h: 120,
            rot: 0,
            scale: 1.0,
            color: '#38bdf8'
        },
        {
            id: 'obj_ups_1',
            name: 'UPS RESPALDO 10KVA',
            type: 'ups',
            x: 820,
            y: 40,
            w: 90,
            h: 60,
            rot: 0,
            scale: 1.0,
            color: '#eab308'
        },
        {
            id: 'obj_extintor_1',
            name: 'EXTINTOR SOLKAFLAM 🧯',
            type: 'extintor',
            x: 850,
            y: 440,
            w: 40,
            h: 40,
            rot: 0,
            scale: 1.0,
            color: '#ef4444'
        },
        {
            id: 'obj_puerta_1',
            name: 'PUERTA ACCESO SITE 🚪',
            type: 'puerta',
            x: 720,
            y: 535,
            w: 100,
            h: 25,
            rot: 0,
            scale: 1.0,
            color: '#22c55e'
        }
    ];
    selectedFloorPlanObj = siteFloorPlanObjects[0];
    sincronizarPanelEditorObjeto2D();
    drawSiteFloorPlan2D();
    if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
    if (typeof renderListaComponentesActivos === 'function') renderListaComponentesActivos();
    if (typeof mostrarNotificacionToast === 'function') {
        mostrarNotificacionToast('success', '📐 Plantilla Cargada', 'Se ha generado la distribución recomendada del SITE 2D / 3D.');
    }
}

// GESTIÓN DE COMBOS Y CATEGORÍAS EN LA PALETA DE EQUIPOS RACK
function filtrarCategoriaPalette(cat) {
    const categories = ['cat_inventario', 'cat_servidores', 'cat_nas', 'cat_isp', 'cat_ups', 'cat_sw'];
    categories.forEach(c => {
        const el = document.getElementById(c);
        const collapseEl = document.getElementById(c + '_collapse');
        const iconEl = document.getElementById(c + '_icon');
        if (!el || !collapseEl) return;

        if (cat === 'cerrar') {
            el.style.setProperty('display', 'block', 'important');
            collapseEl.classList.add('d-none');
            collapseEl.style.setProperty('display', 'none', 'important');
            if (iconEl) {
                iconEl.className = 'bi bi-chevron-right text-secondary';
            }
        } else if (cat === 'todos') {
            el.style.setProperty('display', 'block', 'important');
            collapseEl.classList.remove('d-none');
            collapseEl.style.setProperty('display', 'flex', 'important');
            if (iconEl) {
                iconEl.className = 'bi bi-chevron-down text-secondary';
            }
        } else if (cat === c) {
            el.style.setProperty('display', 'block', 'important');
            collapseEl.classList.remove('d-none');
            collapseEl.style.setProperty('display', 'flex', 'important');
            if (iconEl) {
                iconEl.className = 'bi bi-chevron-down text-info';
            }
        } else {
            el.style.setProperty('display', 'none', 'important');
        }
    });
}

function toggleCategoriaPalette(catId) {
    const collapseEl = document.getElementById(catId + '_collapse');
    const iconEl = document.getElementById(catId + '_icon');
    if (!collapseEl) return;

    const currentDisplay = window.getComputedStyle(collapseEl).display;
    const isHidden = (currentDisplay === 'none') || collapseEl.classList.contains('d-none') || (collapseEl.style.display === 'none');

    if (isHidden) {
        collapseEl.classList.remove('d-none');
        collapseEl.style.setProperty('display', 'flex', 'important');
        if (iconEl) {
            iconEl.className = 'bi bi-chevron-down text-info';
        }
    } else {
        collapseEl.classList.add('d-none');
        collapseEl.style.setProperty('display', 'none', 'important');
        if (iconEl) {
            iconEl.className = 'bi bi-chevron-right text-secondary';
        }
    }
}

// GESTIÓN DE TOOLTIP HUD VINCULADO CON DATOS REALES DE INVENTARIO (2D Y 3D)
function mostrarTooltipComponenteSite(hud, item, rackKey, clientX, clientY) {
    if (!hud || !item) return;

    hud.style.display = 'block';
    hud.style.left = (clientX + 16) + 'px';
    hud.style.top = (clientY + 16) + 'px';

    const inv = item.inventoryData || (item.inventoryKey && window.equiposInventarioMap ? window.equiposInventarioMap[item.inventoryKey] : null);
    const rackObj = (siteRacksData && siteRacksData[rackKey]) ? siteRacksData[rackKey] : null;
    const rackTitle = (rackObj && rackObj.title) ? rackObj.title : (rackKey ? String(rackKey).toUpperCase() : 'RACK');

    const titleEl = document.getElementById('hudItemTitle');
    const rackEl = document.getElementById('hudItemRack');
    const badgeVinculoEl = document.getElementById('hudItemBadgeVinculo');
    const ipRow = document.getElementById('hudItemIpRow');
    const ipEl = document.getElementById('hudItemIp');
    const serieRow = document.getElementById('hudItemSerieRow');
    const serieEl = document.getElementById('hudItemSerie');
    const usuarioRow = document.getElementById('hudItemUsuarioRow');
    const usuarioEl = document.getElementById('hudItemUsuario');
    const estatusEl = document.getElementById('hudItemEstatus');

    if (titleEl) {
        titleEl.textContent = (inv && inv.label) ? inv.label : (item.name || item.type || 'EQUIPO RACK');
    }
    if (rackEl) {
        rackEl.textContent = `${rackTitle} (U${item.u})`;
    }

    if (inv) {
        if (badgeVinculoEl) {
            badgeVinculoEl.style.display = 'inline-block';
            badgeVinculoEl.textContent = '🔗 VINCULADO';
            badgeVinculoEl.style.background = 'rgba(34, 197, 94, 0.2)';
            badgeVinculoEl.style.color = '#22c55e';
            badgeVinculoEl.style.borderColor = '#22c55e';
        }
        if (ipRow && ipEl) {
            ipRow.style.display = 'block';
            ipEl.textContent = inv.ip || 'Sin IP';
        }
        if (serieRow && serieEl) {
            serieRow.style.display = 'block';
            serieEl.textContent = inv.serie || 'Sin Serie';
        }
        if (usuarioRow && usuarioEl) {
            usuarioRow.style.display = 'block';
            usuarioEl.textContent = inv.usuario || 'No asignado';
        }
        if (estatusEl) {
            estatusEl.textContent = '● ' + (inv.estatus ? String(inv.estatus).toUpperCase() : 'OPERATIVO ONLINE');
            estatusEl.style.color = '#22c55e';
        }
    } else {
        if (badgeVinculoEl) {
            badgeVinculoEl.style.display = 'inline-block';
            badgeVinculoEl.textContent = '⚠️ SIN VINCULAR';
            badgeVinculoEl.style.background = 'rgba(234, 179, 8, 0.15)';
            badgeVinculoEl.style.color = '#fbbf24';
            badgeVinculoEl.style.borderColor = '#fbbf24';
        }
        if (ipRow && ipEl) {
            ipRow.style.display = 'none';
        }
        if (serieRow && serieEl) {
            serieRow.style.display = 'none';
        }
        if (usuarioRow && usuarioEl) {
            usuarioRow.style.display = 'none';
        }
        if (estatusEl) {
            estatusEl.textContent = '● OPERATIVO';
            estatusEl.style.color = '#22c55e';
        }
    }
}

// ACTUALIZACIÓN DEL PANEL DE VINCULACIÓN EN LA PALETA
function actualizarPanelVinculacionInventarioRack(sel) {
    const lblComp = document.getElementById('labelCompRackSeleccionado');
    const subComp = document.getElementById('subCompRackSeleccionado');
    const selInv = document.getElementById('selectInventarioSiteRack');
    if (!lblComp) return;

    if (!sel || !sel.item) {
        lblComp.textContent = 'Ninguno (clic en un equipo)';
        lblComp.className = 'fw-bold text-secondary text-truncate';
        if (subComp) {
            subComp.textContent = 'Haz clic sobre un equipo en el rack';
            subComp.className = 'text-secondary text-truncate mt-0.5';
        }
        if (selInv) selInv.value = '';
        return;
    }

    const it = sel.item;
    const rackTitle = (siteRacksData[sel.rackKey] && siteRacksData[sel.rackKey].title) ? siteRacksData[sel.rackKey].title : (sel.rackKey || 'RACK');
    lblComp.textContent = `${it.name || it.type} (U${it.u})`;
    lblComp.className = 'fw-bold text-warning text-truncate';

    const hasInv = !!(it.inventoryData || it.inventoryKey);
    if (hasInv) {
        const inv = it.inventoryData || (window.equiposInventarioMap ? window.equiposInventarioMap[it.inventoryKey] : null);
        if (inv) {
            if (subComp) {
                subComp.innerHTML = `<span class="badge bg-success bg-opacity-25 text-success border border-success p-1">🔗 ${inv.label} (${inv.ip || 'Sin IP'})</span>`;
            }
            if (selInv) selInv.value = inv.key;
        } else {
            if (subComp) subComp.textContent = `${rackTitle} | Vinculado: ${it.inventoryKey}`;
            if (selInv) selInv.value = it.inventoryKey;
        }
    } else {
        if (subComp) {
            subComp.innerHTML = `<span class="text-secondary">${rackTitle} | <span class="text-warning fw-semibold">Sin vincular</span></span>`;
        }
        if (selInv) selInv.value = '';
    }
}

// VINCULAR INVENTARIO AL COMPONENTE SELECCIONADO
function vincularInventarioAComponenteRack() {
    if (!selectedMountedRackItem || !selectedMountedRackItem.item) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('warning', 'Selecciona un equipo', 'Primero haz clic sobre un equipo en el bastidor 2D para seleccionarlo.');
        } else {
            alert('Primero haz clic sobre un equipo en el bastidor para seleccionarlo.');
        }
        return;
    }

    const selInv = document.getElementById('selectInventarioSiteRack');
    if (!selInv || !selInv.value) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('warning', 'Selecciona inventario', 'Elige un equipo del inventario de la lista desplegable.');
        } else {
            alert('Elige un equipo del inventario de la lista desplegable.');
        }
        return;
    }

    const key = selInv.value;
    const invData = (window.equiposInventarioMap && window.equiposInventarioMap[key]) ? window.equiposInventarioMap[key] : null;

    selectedMountedRackItem.item.inventoryKey = key;
    if (invData) {
        selectedMountedRackItem.item.inventoryData = invData;
        if (invData.label && (!selectedMountedRackItem.item.name || selectedMountedRackItem.item.name === 'EQUIPO' || selectedMountedRackItem.item.name === selectedMountedRackItem.item.type)) {
            selectedMountedRackItem.item.name = invData.label;
        }
    }

    actualizarPanelVinculacionInventarioRack(selectedMountedRackItem);
    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');

    if (typeof sincronizarEscena3DDesde2D === 'function') {
        sincronizarEscena3DDesde2D();
    }

    if (typeof mostrarNotificacionToast === 'function') {
        mostrarNotificacionToast('success', 'Inventario Vinculado', `Se vinculó ${invData ? invData.label : key} al componente en U${selectedMountedRackItem.item.u}.`);
    }
}

// DESVINCULAR INVENTARIO DEL COMPONENTE SELECCIONADO
function desvincularInventarioDeComponenteRack() {
    if (!selectedMountedRackItem || !selectedMountedRackItem.item) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('warning', 'Selecciona un equipo', 'Primero haz clic sobre un equipo en el bastidor 2D para seleccionarlo.');
        }
        return;
    }

    delete selectedMountedRackItem.item.inventoryKey;
    delete selectedMountedRackItem.item.inventoryData;

    actualizarPanelVinculacionInventarioRack(selectedMountedRackItem);
    drawSite2DRackElevation('siteCanvas2D');
    drawSite2DRackElevation('siteCanvas2DConfig');

    if (typeof sincronizarEscena3DDesde2D === 'function') {
        sincronizarEscena3DDesde2D();
    }

    if (typeof mostrarNotificacionToast === 'function') {
        mostrarNotificacionToast('info', 'Inventario Desvinculado', 'Se removió la vinculación de inventario del componente.');
    }
}

// ABRIR MODAL DE FICHA TÉCNICA DESDE SITE
function verFichaTecnicaModalSite() {
    let item = null;
    let rackKey = null;

    if (selectedMountedRackItem && selectedMountedRackItem.item) {
        item = selectedMountedRackItem.item;
        rackKey = selectedMountedRackItem.rackKey;
    }

    if (!item) {
        const selInv = document.getElementById('selectInventarioSiteRack');
        if (selInv && selInv.value && window.equiposInventarioMap && window.equiposInventarioMap[selInv.value]) {
            const eq = window.equiposInventarioMap[selInv.value];
            item = {
                name: eq.label,
                type: eq.tipo,
                u: '-',
                inventoryData: eq,
                inventoryKey: eq.key
            };
        }
    }

    if (!item) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('info', 'Ficha Técnica', 'Selecciona un equipo del rack o del inventario para ver su ficha técnica.');
        } else {
            alert('Selecciona un equipo del rack o del inventario para ver su ficha técnica.');
        }
        return;
    }

    const inv = item.inventoryData || (item.inventoryKey && window.equiposInventarioMap ? window.equiposInventarioMap[item.inventoryKey] : null);

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

    if (fn) fn.textContent = (inv && inv.label) ? inv.label : (item.name || item.type || 'Equipo Rack');
    if (ft) ft.textContent = (item.type || 'RACK EQUIPMENT').toUpperCase();
    if (fip) fip.textContent = (inv && inv.ip) ? inv.ip : 'No asignada';
    if (fdept) fdept.textContent = (inv && inv.departamento) ? inv.departamento : 'Datacenter / SITE Principal';
    if (fcoords) fcoords.textContent = (rackKey ? `${rackKey} - ` : '') + (item.u ? `U${item.u}` : 'Sin U');
    if (fdim) fdim.textContent = `19" Rackeable (${getComponentUHeight(item.type, item.name)}U)`;

    if (fgrpInv) {
        if (inv) {
            fgrpInv.style.display = 'block';
            if (finvUser) finvUser.textContent = inv.usuario || 'No asignado';
            if (finvDept) finvDept.textContent = (inv.departamento || 'Infraestructura') + (inv.raw && inv.raw.puesto ? ' / ' + inv.raw.puesto : '');
            if (finvMarcaMod) finvMarcaMod.textContent = (inv.raw && (inv.raw.marca || inv.raw.modelo)) ? `${inv.raw.marca || ''} ${inv.raw.modelo || ''}`.trim() : (inv.sub || inv.tipo || '--');
            if (finvSerie) finvSerie.textContent = inv.serie || 'Sin Serie';
            if (finvModulo) finvModulo.textContent = inv.origen ? String(inv.origen).toUpperCase() : 'INVENTARIO';
        } else {
            fgrpInv.style.display = 'none';
        }
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modalObj = bootstrap.Modal.getOrCreateInstance(mModal);
        modalObj.show();
    }
}

// ARRASTRE DE INVENTARIO DIRECTAMENTE AL RACK
function onDragInventarioToRackStart(event) {
    const selInv = document.getElementById('selectInventarioSiteRack');
    if (!selInv || !selInv.value) {
        if (typeof mostrarNotificacionToast === 'function') {
            mostrarNotificacionToast('warning', 'Selecciona inventario', 'Primero selecciona un equipo de la lista desplegable de inventario.');
        } else {
            alert('Primero selecciona un equipo de la lista desplegable de inventario.');
        }
        event.preventDefault();
        return;
    }
    const invKey = selInv.value;
    const dragData = 'inv:' + invKey;
    currentPaletteDraggedTool = dragData;
    event.dataTransfer.setData('text/plain', dragData);
    event.dataTransfer.effectAllowed = 'copy';
}

window.filtrarCategoriaPalette = filtrarCategoriaPalette;
window.toggleCategoriaPalette = toggleCategoriaPalette;
window.mostrarTooltipComponenteSite = mostrarTooltipComponenteSite;
window.actualizarPanelVinculacionInventarioRack = actualizarPanelVinculacionInventarioRack;
window.vincularInventarioAComponenteRack = vincularInventarioAComponenteRack;
window.desvincularInventarioDeComponenteRack = desvincularInventarioDeComponenteRack;
window.verFichaTecnicaModalSite = verFichaTecnicaModalSite;
window.onDragInventarioToRackStart = onDragInventarioToRackStart;


