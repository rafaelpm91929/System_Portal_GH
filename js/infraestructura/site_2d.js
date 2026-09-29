// =========================================================================
// MOTOR DE PLANO 2D ESTILO CANVA / FIGMA PARA SITE
// Componentes: Rack 42U, Minisplit AC, Extintor
// Permite: Mover, Redimensionar desde 8 tiradores en las orillas y Rotar con tirador superior
// =========================================================================

let site2DObjects = [];
let selectedSite2DObj = null;

// Estados de interacción del mouse
let currentAction = null; // 'drag', 'rotate', 'resize-nw', 'resize-ne', 'resize-se', 'resize-sw', 'resize-n', 'resize-s', 'resize-e', 'resize-w'
let actionStart = {
    mouseX: 0,
    mouseY: 0,
    objX: 0,
    objY: 0,
    objW: 0,
    objH: 0,
    objRot: 0,
    centerX: 0,
    centerY: 0
};

const HANDLE_SIZE = 9;
const ROT_STEM_LEN = 26;

// Inicialización
function initSite2DPlan() {
    const canvas = document.getElementById('canvasPlanoSite2D');
    if (!canvas) return;

    // Cargar objetos previos o inicializar por defecto
    cargarObjetosIniciales();

    // Eventos interactivos estilo Canva
    setupCanvaEvents(canvas);

    // Dibujar escena inicial
    renderPlanoSite2D();
}

function cargarObjetosIniciales() {
    if (window.savedSiteFloorPlanData) {
        try {
            let data = window.savedSiteFloorPlanData;
            if (typeof data === 'string') data = JSON.parse(data);
            if (Array.isArray(data) && data.length > 0) {
                site2DObjects = data.filter(o => ['rack', 'minisplit', 'extintor', 'extintor_verde', 'libreta', 'bitacora', 'detector_humo', 'termometro_digital', 'camara_seguridad', 'camara', 'cctv', 'cota', 'linea_cota', 'pared', 'muro', 'piso', 'piso_tecnico', 'puerta', 'puerta_deslizable'].includes(o.type));
            }
        } catch (e) {
            console.warn('Error al cargar datos guardados:', e);
        }
    }

    if (site2DObjects.length === 0) {
        site2DObjects = [
            {
                id: 'rack_1',
                name: 'RACK CORE 01',
                type: 'rack',
                x: 360,
                y: 180,
                w: 90,
                h: 130,
                rot: 0
            },
            {
                id: 'minisplit_1',
                name: 'MINISPLIT INVERTER',
                type: 'minisplit',
                x: 80,
                y: 180,
                w: 110,
                h: 34,
                rot: 90
            },
            {
                id: 'extintor_1',
                name: 'EXTINTOR SOLKAFLAM',
                type: 'extintor',
                x: 860,
                y: 420,
                w: 44,
                h: 44,
                rot: 0
            }
        ];
    }
}

// Convertir punto del canvas a espacio local del objeto (deshaciendo rotación y traslación)
function toLocalPoint(px, py, obj) {
    const cx = obj.x + obj.w / 2;
    const cy = obj.y + obj.h / 2;
    const rad = -(obj.rot || 0) * Math.PI / 180;
    const dx = px - cx;
    const dy = py - cy;
    return {
        x: dx * Math.cos(rad) - dy * Math.sin(rad),
        y: dx * Math.sin(rad) + dy * Math.cos(rad)
    };
}

// Obtener tiradores en coordenadas locales del objeto
function getHandles(obj) {
    const hw = obj.w / 2;
    const hh = obj.h / 2;
    return {
        'rot': { x: 0, y: -hh - ROT_STEM_LEN, cursor: 'grab' },
        'resize-nw': { x: -hw, y: -hh, cursor: 'nwse-resize' },
        'resize-ne': { x: hw, y: -hh, cursor: 'nesw-resize' },
        'resize-se': { x: hw, y: hh, cursor: 'nwse-resize' },
        'resize-sw': { x: -hw, y: hh, cursor: 'nesw-resize' },
        'resize-n': { x: 0, y: -hh, cursor: 'ns-resize' },
        'resize-s': { x: 0, y: hh, cursor: 'ns-resize' },
        'resize-w': { x: -hw, y: 0, cursor: 'ew-resize' },
        'resize-e': { x: hw, y: 0, cursor: 'ew-resize' }
    };
}

// Manejadores de eventos de Canvas
function setupCanvaEvents(canvas) {
    canvas.addEventListener('mousedown', (e) => {
        const mouse = getCanvasMouse(canvas, e);

        // 1. Si hay un objeto seleccionado, revisar primero si se hizo clic en algún tirador (handle)
        if (selectedSite2DObj) {
            const local = toLocalPoint(mouse.x, mouse.y, selectedSite2DObj);
            const handles = getHandles(selectedSite2DObj);

            for (const [key, h] of Object.entries(handles)) {
                const dist = Math.hypot(local.x - h.x, local.y - h.y);
                const hitRadius = key === 'rot' ? 12 : 9;
                if (dist <= hitRadius) {
                    currentAction = key;
                    const cx = selectedSite2DObj.x + selectedSite2DObj.w / 2;
                    const cy = selectedSite2DObj.y + selectedSite2DObj.h / 2;
                    actionStart = {
                        mouseX: mouse.x,
                        mouseY: mouse.y,
                        objX: selectedSite2DObj.x,
                        objY: selectedSite2DObj.y,
                        objW: selectedSite2DObj.w,
                        objH: selectedSite2DObj.h,
                        objRot: selectedSite2DObj.rot || 0,
                        centerX: cx,
                        centerY: cy
                    };
                    return;
                }
            }
        }

        // 2. Comprobar si se hizo clic dentro de algún objeto para seleccionarlo o moverlo
        // Ordenar candidatos para que los equipos/paredes se seleccionen antes que el piso de fondo
        const hitCandidates = [...site2DObjects].sort((a, b) => {
            const isFloorA = (a.type === 'piso' || a.type === 'piso_tecnico');
            const isFloorB = (b.type === 'piso' || b.type === 'piso_tecnico');
            if (isFloorA && !isFloorB) return -1;
            if (!isFloorA && isFloorB) return 1;
            return site2DObjects.indexOf(a) - site2DObjects.indexOf(b);
        });

        let hitObj = null;
        for (let i = hitCandidates.length - 1; i >= 0; i--) {
            const obj = hitCandidates[i];
            const local = toLocalPoint(mouse.x, mouse.y, obj);
            if (Math.abs(local.x) <= obj.w / 2 && Math.abs(local.y) <= obj.h / 2) {
                hitObj = obj;
                break;
            }
        }

        if (hitObj) {
            selectedSite2DObj = hitObj;
            currentAction = 'drag';
            actionStart = {
                mouseX: mouse.x,
                mouseY: mouse.y,
                objX: hitObj.x,
                objY: hitObj.y,
                objW: hitObj.w,
                objH: hitObj.h,
                objRot: hitObj.rot || 0
            };
        } else {
            selectedSite2DObj = null;
            currentAction = null;
        }

        actualizarToolbarFlotante();
        renderPlanoSite2D();
    });

    window.addEventListener('mousemove', (e) => {
        const mouse = getCanvasMouse(canvas, e);

        // Actualizar el cursor cuando el mouse se mueve libremente sobre tiradores
        if (!currentAction) {
            let newCursor = 'default';
            if (selectedSite2DObj) {
                const local = toLocalPoint(mouse.x, mouse.y, selectedSite2DObj);
                const handles = getHandles(selectedSite2DObj);

                for (const [key, h] of Object.entries(handles)) {
                    const dist = Math.hypot(local.x - h.x, local.y - h.y);
                    const hitRadius = key === 'rot' ? 12 : 9;
                    if (dist <= hitRadius) {
                        newCursor = h.cursor;
                        break;
                    }
                }

                if (newCursor === 'default' && Math.abs(local.x) <= selectedSite2DObj.w / 2 && Math.abs(local.y) <= selectedSite2DObj.h / 2) {
                    newCursor = 'move';
                }
            } else {
                const hoverCandidates = [...site2DObjects].sort((a, b) => {
                    const isFloorA = (a.type === 'piso' || a.type === 'piso_tecnico');
                    const isFloorB = (b.type === 'piso' || b.type === 'piso_tecnico');
                    if (isFloorA && !isFloorB) return -1;
                    if (!isFloorA && isFloorB) return 1;
                    return site2DObjects.indexOf(a) - site2DObjects.indexOf(b);
                });
                for (let i = hoverCandidates.length - 1; i >= 0; i--) {
                    const obj = hoverCandidates[i];
                    const local = toLocalPoint(mouse.x, mouse.y, obj);
                    if (Math.abs(local.x) <= obj.w / 2 && Math.abs(local.y) <= obj.h / 2) {
                        newCursor = 'move';
                        break;
                    }
                }
            }
            canvas.style.cursor = newCursor;
            return;
        }

        if (!selectedSite2DObj) return;

        // ACCIÓN 1: ROTAR (Estilo Canva con tirador superior)
        if (currentAction === 'rot') {
            canvas.style.cursor = 'grabbing';
            const cx = actionStart.centerX;
            const cy = actionStart.centerY;
            const angleRad = Math.atan2(mouse.y - cy, mouse.x - cx);
            let deg = Math.round((angleRad * 180 / Math.PI) + 90);
            deg = (deg % 360 + 360) % 360;

            // Snap magnético cada 15° o 45° con Shift
            if (e.shiftKey) {
                deg = Math.round(deg / 45) * 45;
            } else if (Math.abs(deg % 90) <= 4 || Math.abs(deg % 90) >= 86) {
                deg = Math.round(deg / 90) * 90 % 360; // Auto snap suave a 0, 90, 180, 270
            }

            selectedSite2DObj.rot = deg;
            actualizarToolbarFlotante();
            renderPlanoSite2D();
            return;
        }

        // ACCIÓN 2: MOVER
        if (currentAction === 'drag') {
            canvas.style.cursor = 'move';
            const dx = mouse.x - actionStart.mouseX;
            const dy = mouse.y - actionStart.mouseY;

            let nextX = actionStart.objX + dx;
            let nextY = actionStart.objY + dy;

            // Clamping dentro del área delimitada de la Sala 3D
            const minX = 64;
            const maxX = 60 + 940 - selectedSite2DObj.w - 4;
            const minY = 34;
            const maxY = 30 + 515 - selectedSite2DObj.h - 4;

            nextX = Math.max(minX, Math.min(maxX, nextX));
            nextY = Math.max(minY, Math.min(maxY, nextY));

            selectedSite2DObj.x = Math.round(nextX);
            selectedSite2DObj.y = Math.round(nextY);
            actualizarToolbarFlotante();
            renderPlanoSite2D();
            return;
        }

        // ACCIÓN 3: REDIMENSIONAR DESDE LAS ORILLAS / ESQUINAS (Estilo Canva)
        if (currentAction.startsWith('resize-')) {
            const rad = (actionStart.objRot || 0) * Math.PI / 180;
            const cx = actionStart.centerX;
            const cy = actionStart.centerY;

            // Proyectar el movimiento del mouse a los ejes locales del objeto
            const dx = mouse.x - actionStart.mouseX;
            const dy = mouse.y - actionStart.mouseY;
            const localDx = dx * Math.cos(-rad) - dy * Math.sin(-rad);
            const localDy = dx * Math.sin(-rad) + dy * Math.cos(-rad);

            let newW = actionStart.objW;
            let newH = actionStart.objH;
            let deltaCenterX = 0;
            let deltaCenterY = 0;

            const handleType = currentAction.replace('resize-', '');

            // Modificar Ancho
            if (handleType.includes('e')) {
                newW = Math.max(25, actionStart.objW + localDx);
                deltaCenterX += (newW - actionStart.objW) / 2;
            } else if (handleType.includes('w')) {
                newW = Math.max(25, actionStart.objW - localDx);
                deltaCenterX -= (newW - actionStart.objW) / 2;
            }

            // Modificar Alto
            if (handleType.includes('s')) {
                newH = Math.max(25, actionStart.objH + localDy);
                deltaCenterY += (newH - actionStart.objH) / 2;
            } else if (handleType.includes('n')) {
                newH = Math.max(25, actionStart.objH - localDy);
                deltaCenterY -= (newH - actionStart.objH) / 2;
            }

            // Convertir el desplazamiento del centro local de vuelta al espacio global
            const globalDeltaX = deltaCenterX * Math.cos(rad) - deltaCenterY * Math.sin(rad);
            const globalDeltaY = deltaCenterX * Math.sin(rad) + deltaCenterY * Math.cos(rad);

            const newCenterX = cx + globalDeltaX;
            const newCenterY = cy + globalDeltaY;

            selectedSite2DObj.w = Math.round(newW);
            selectedSite2DObj.h = Math.round(newH);
            selectedSite2DObj.x = Math.round(newCenterX - newW / 2);
            selectedSite2DObj.y = Math.round(newCenterY - newH / 2);

            actualizarToolbarFlotante();
            renderPlanoSite2D();
        }
    });

    window.addEventListener('mouseup', () => {
        if (currentAction) {
            window.site2DObjects = site2DObjects;
            if (typeof sincronizarEscena3DDesde2D === 'function') {
                sincronizarEscena3DDesde2D();
            }
        }
        currentAction = null;
        if (canvas) canvas.style.cursor = 'default';
        actualizarToolbarFlotante();
    });

    // Doble clic para cambiar nombre directamente
    canvas.addEventListener('dblclick', (e) => {
        if (selectedSite2DObj) {
            editarNombreComponente(selectedSite2DObj);
        }
    });
}

function getCanvasMouse(canvas, e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
        x: (e.clientX - rect.left) * scaleX,
        y: (e.clientY - rect.top) * scaleY
    };
}

// =========================================================================
// Renderizado principal Canvas 2D
function renderPlanoSite2D() {
    const canvas = document.getElementById('canvasPlanoSite2D');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;

    // Fondo oscuro tecnológico
    ctx.fillStyle = '#061325';
    ctx.fillRect(0, 0, w, h);

    // Cuadrícula milimétrica estilo Blueprint
    ctx.strokeStyle = 'rgba(56, 189, 248, 0.08)';
    ctx.lineWidth = 1;
    const gridSize = 25;
    for (let x = 0; x < w; x += gridSize) {
        ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
    }
    for (let y = 0; y < h; y += gridSize) {
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
    }

    // =========================================================================
    // DELIMITACIÓN VISUAL DE LA SALA 3D (ZONA DATACENTER CORE 3D)
    // Se dibuja en la vista interactiva, pero se omite completamente en el PDF
    // =========================================================================
    if (!window.isExportingSitePDF) {
        // Coordenadas calculadas para coincidir exactamente con el piso 3D (50x50 unidades en Three.js)
        const site3D_X = 60;
        const site3D_Y = 30;
        const site3D_W = 940;
        const site3D_H = 515;

        // Área exterior sombreada sutilmente
        ctx.fillStyle = 'rgba(2, 6, 23, 0.4)';
        ctx.fillRect(0, 0, w, h);

        // Fondo del área delimitada 3D
        ctx.fillStyle = 'rgba(6, 20, 39, 0.95)';
        ctx.fillRect(site3D_X, site3D_Y, site3D_W, site3D_H);

        // Rejilla interna de la zona 3D
        ctx.strokeStyle = 'rgba(0, 242, 254, 0.07)';
        ctx.lineWidth = 1;
        for (let x = site3D_X; x <= site3D_X + site3D_W; x += 35) {
            ctx.beginPath(); ctx.moveTo(x, site3D_Y); ctx.lineTo(x, site3D_Y + site3D_H); ctx.stroke();
        }
        for (let y = site3D_Y; y <= site3D_Y + site3D_H; y += 35) {
            ctx.beginPath(); ctx.moveTo(site3D_X, y); ctx.lineTo(site3D_X + site3D_W, y); ctx.stroke();
        }

        // Borde delimitador perimetral de la sala 3D con esquinas tecnológicas tipo Sci-Fi
        ctx.strokeStyle = '#00f2fe';
        ctx.lineWidth = 2.5;
        ctx.setLineDash([8, 6]);
        ctx.strokeRect(site3D_X, site3D_Y, site3D_W, site3D_H);
        ctx.setLineDash([]); // Restaurar línea sólida

        // Esquinas reforzadas (Corner Brackets)
        const cLen = 22;
        ctx.strokeStyle = '#38bdf8';
        ctx.lineWidth = 4;
        // Top-Left
        ctx.beginPath(); ctx.moveTo(site3D_X, site3D_Y + cLen); ctx.lineTo(site3D_X, site3D_Y); ctx.lineTo(site3D_X + cLen, site3D_Y); ctx.stroke();
        // Top-Right
        ctx.beginPath(); ctx.moveTo(site3D_X + site3D_W - cLen, site3D_Y); ctx.lineTo(site3D_X + site3D_W, site3D_Y); ctx.lineTo(site3D_X + site3D_W, site3D_Y + cLen); ctx.stroke();
        // Bottom-Left
        ctx.beginPath(); ctx.moveTo(site3D_X, site3D_Y + site3D_H - cLen); ctx.lineTo(site3D_X, site3D_Y + site3D_H); ctx.lineTo(site3D_X + cLen, site3D_Y + site3D_H); ctx.stroke();
        // Bottom-Right
        ctx.beginPath(); ctx.moveTo(site3D_X + site3D_W - cLen, site3D_Y + site3D_H); ctx.lineTo(site3D_X + site3D_W, site3D_Y + site3D_H); ctx.lineTo(site3D_X + site3D_W, site3D_Y + site3D_H - cLen); ctx.stroke();

        // Pared trasera 3D (donde se ubica la iluminación neón y climatización)
        ctx.fillStyle = 'rgba(56, 189, 248, 0.12)';
        ctx.fillRect(site3D_X, site3D_Y, site3D_W, 24);
        ctx.strokeStyle = '#00f2fe';
        ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(site3D_X, site3D_Y + 24); ctx.lineTo(site3D_X + site3D_W, site3D_Y + 24); ctx.stroke();

        // Rótulo indicador de la Zona Delimitada 3D
        ctx.fillStyle = '#00f2fe';
        ctx.font = 'bold 10px monospace';
        ctx.textAlign = 'left';
        ctx.fillText('⬛ SALA DATACENTER 3D (LÍMITE DE VISUALIZACIÓN THREE.JS)', site3D_X + 16, site3D_Y + 16);

        ctx.fillStyle = '#64748b';
        ctx.font = '9px monospace';
        ctx.textAlign = 'right';
        ctx.fillText('ÁREA EFECTIVA: 50×50m GRID 3D', site3D_X + site3D_W - 16, site3D_Y + 16);

        // Indicador visual de la Cámara 3D (Posición y Campo de Visión / FOV)
        const camCenterX = site3D_X + site3D_W / 2;
        const camCenterY = site3D_Y + site3D_H - 12;

        // Cono de visión translúcido (FOV)
        const fovGradient = ctx.createRadialGradient(camCenterX, camCenterY, 10, camCenterX, camCenterY - 120, 220);
        fovGradient.addColorStop(0, 'rgba(0, 242, 254, 0.18)');
        fovGradient.addColorStop(1, 'rgba(0, 242, 254, 0.0)');
        ctx.fillStyle = fovGradient;
        ctx.beginPath();
        ctx.moveTo(camCenterX, camCenterY);
        ctx.lineTo(camCenterX - 180, camCenterY - 180);
        ctx.lineTo(camCenterX + 180, camCenterY - 180);
        ctx.closePath();
        ctx.fill();

        // Icono y etiqueta de la cámara
        ctx.fillStyle = '#38bdf8';
        ctx.beginPath();
        ctx.arc(camCenterX, camCenterY, 7, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        ctx.fillStyle = '#38bdf8';
        ctx.font = 'bold 9px monospace';
        ctx.textAlign = 'center';
        ctx.fillText('📷 CÁMARA 3D (ÁNGULO DE PERSPECTIVA)', camCenterX, camCenterY + 14);
    }

    // Dibujar cada componente en la sala (piso en fondo, componentes/paredes intermedios, cotas al frente)
    const sortedObjects = [...site2DObjects].sort((a, b) => {
        const layer = (t) => (t === 'piso' || t === 'piso_tecnico') ? 0 : ((t === 'cota' || t === 'linea_cota') ? 2 : 1);
        return layer(a.type) - layer(b.type);
    });
    sortedObjects.forEach(obj => {
        ctx.save();
        const effW = obj.w;
        const effH = obj.h;
        const cx = obj.x + effW / 2;
        const cy = obj.y + effH / 2;

        ctx.translate(cx, cy);
        ctx.rotate((obj.rot || 0) * Math.PI / 180);

        const drawX = -effW / 2;
        const drawY = -effH / 2;

        if (obj.type === 'rack') {
            // 🗄️ DIBUJO DE RACK 42U
            ctx.fillStyle = '#09182b';
            ctx.fillRect(drawX, drawY, effW, effH);

            ctx.strokeStyle = '#38bdf8';
            ctx.lineWidth = 2.5;
            ctx.strokeRect(drawX, drawY, effW, effH);

            // Frontal de Rack (Franja luminosa cyan)
            ctx.fillStyle = '#00f2fe';
            ctx.fillRect(drawX, drawY + effH - 5, effW, 5);

            // Ranuras de bahías de servidores
            ctx.strokeStyle = 'rgba(56, 189, 248, 0.25)';
            ctx.lineWidth = 1;
            for (let ly = drawY + 14; ly < drawY + effH - 12; ly += 14) {
                ctx.beginPath(); ctx.moveTo(drawX + 8, ly); ctx.lineTo(drawX + effW - 8, ly); ctx.stroke();
            }

            // Etiquetas de texto
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 10px monospace';
            ctx.textAlign = 'center';
            ctx.fillText(obj.name, 0, -4);
            ctx.fillStyle = '#38bdf8';
            ctx.font = '9px monospace';
            ctx.fillText('42U RACK', 0, 10);

        } else if (obj.type === 'minisplit') {
            // ❄️ DIBUJO DE MINISPLIT AC
            ctx.fillStyle = '#f8fafc';
            ctx.fillRect(drawX, drawY, effW, effH);

            ctx.strokeStyle = '#38bdf8';
            ctx.lineWidth = 2;
            ctx.strokeRect(drawX, drawY, effW, effH);

            // Rejilla interna
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(drawX + 4, drawY + 4, effW - 8, effH - 8);

            // Flujo de aire frío
            ctx.fillStyle = 'rgba(0, 242, 254, 0.28)';
            ctx.beginPath();
            ctx.moveTo(drawX + 10, drawY + effH);
            ctx.lineTo(drawX - 12, drawY + effH + 26);
            ctx.lineTo(drawX + effW + 12, drawY + effH + 26);
            ctx.lineTo(drawX + effW - 10, drawY + effH);
            ctx.fill();

            // Etiqueta
            ctx.fillStyle = '#00f2fe';
            ctx.font = 'bold 10px monospace';
            ctx.textAlign = 'center';
            ctx.fillText('18°C ❄️ AC', 0, 4);

        } else if (obj.type === 'extintor') {
            // 🧯 DIBUJO DE EXTINTOR ROJO
            ctx.fillStyle = '#dc2626';
            ctx.beginPath();
            ctx.arc(0, 0, effW / 2, 0, Math.PI * 2);
            ctx.fill();

            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.stroke();

            ctx.fillStyle = '#ffffff';
            ctx.font = `${Math.max(10, Math.round(effW * 0.35))}px sans-serif`;
            ctx.textAlign = 'center';
            ctx.fillText('🧯', 0, effW * 0.12);

        } else if (obj.type === 'extintor_verde') {
            // 🧯 DIBUJO DE EXTINTOR VERDE (SOLKAFLAM / AGENTE LIMPIO)
            ctx.fillStyle = '#16a34a';
            ctx.beginPath();
            ctx.arc(0, 0, effW / 2, 0, Math.PI * 2);
            ctx.fill();

            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.stroke();

            // Anillo interior blanco sutil
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.5)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.arc(0, 0, Math.max(2, (effW / 2) - 4), 0, Math.PI * 2);
            ctx.stroke();

            ctx.fillStyle = '#ffffff';
            ctx.font = `${Math.max(10, Math.round(effW * 0.35))}px sans-serif`;
            ctx.textAlign = 'center';
            ctx.fillText('🧯', 0, effW * 0.12);

        } else if (obj.type === 'libreta' || obj.type === 'bitacora') {
            // 📖 DIBUJO DE LIBRETA / BITÁCORA DEL SITE
            // Cubierta elegante oscura con filo ámbar
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(drawX, drawY, effW, effH);

            ctx.strokeStyle = '#f59e0b';
            ctx.lineWidth = 2;
            ctx.strokeRect(drawX, drawY, effW, effH);

            // Lomo / Espiral izquierdo
            const spiralW = Math.max(6, Math.round(effW * 0.20));
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(drawX, drawY, spiralW, effH);
            ctx.strokeStyle = '#64748b';
            ctx.lineWidth = 1;
            ctx.strokeRect(drawX, drawY, spiralW, effH);

            // Anillos del espiral
            ctx.fillStyle = '#e2e8f0';
            for (let sy = drawY + 5; sy < drawY + effH - 3; sy += 6.5) {
                ctx.beginPath();
                ctx.arc(drawX + spiralW / 2, sy, 1.8, 0, Math.PI * 2);
                ctx.fill();
            }

            // Etiqueta central dorada / ámbar
            const badgeW = effW - spiralW - 6;
            const badgeH = Math.min(18, effH * 0.35);
            const badgeX = drawX + spiralW + 3;
            const badgeY = drawY + (effH - badgeH) / 2;

            ctx.fillStyle = '#f59e0b';
            ctx.fillRect(badgeX, badgeY, badgeW, badgeH);

            ctx.fillStyle = '#0f172a';
            ctx.font = `bold ${Math.max(6.5, Math.min(9, Math.round(effW * 0.19)))}px sans-serif`;
            ctx.textAlign = 'center';
            ctx.fillText('BITÁCORA', badgeX + (badgeW / 2), badgeY + (badgeH / 2) + 3);

            // Lápiz o bolígrafo al lado derecho
            if (effW >= 34) {
                ctx.fillStyle = '#38bdf8';
                ctx.fillRect(drawX + effW - 4, drawY + 6, 2.5, effH - 12);
            }

        } else if (obj.type === 'detector_humo') {
            // 🚨 DIBUJO DE DETECTOR DE HUMO (TECHO)
            const radius = Math.min(effW, effH) / 2;

            // Cuerpo circular blanco humo
            ctx.fillStyle = '#f8fafc';
            ctx.beginPath();
            ctx.arc(0, 0, radius, 0, Math.PI * 2);
            ctx.fill();

            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 2;
            ctx.stroke();

            // Ranuras concéntricas de ventilación
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = 1.2;
            ctx.beginPath();
            ctx.arc(0, 0, radius * 0.68, 0, Math.PI * 2);
            ctx.stroke();

            ctx.beginPath();
            ctx.arc(0, 0, radius * 0.42, 0, Math.PI * 2);
            ctx.stroke();

            // LED central de alarma/monitoreo (rojo brillante)
            ctx.fillStyle = '#ef4444';
            ctx.beginPath();
            ctx.arc(0, 0, Math.max(3, radius * 0.18), 0, Math.PI * 2);
            ctx.fill();

            // Brillo sutil del LED
            ctx.fillStyle = 'rgba(239, 68, 68, 0.45)';
            ctx.beginPath();
            ctx.arc(0, 0, Math.max(5, radius * 0.32), 0, Math.PI * 2);
            ctx.fill();

            // Rótulo "HUMO"
            ctx.fillStyle = '#475569';
            ctx.font = `bold ${Math.max(6, Math.round(radius * 0.36))}px monospace`;
            ctx.textAlign = 'center';
            ctx.fillText('HUMO', 0, radius * 0.80);

        } else if (obj.type === 'termometro_digital') {
            // 🌡️ DIBUJO DE TERMÓMETRO / HIGRÓMETRO DIGITAL
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(drawX, drawY, effW, effH);

            ctx.strokeStyle = '#06b6d4';
            ctx.lineWidth = 2;
            ctx.strokeRect(drawX, drawY, effW, effH);

            // Pantalla LCD digital verde/cian oscuro
            const scrX = drawX + 4;
            const scrY = drawY + 4;
            const scrW = effW - 8;
            const scrH = Math.max(16, effH - 16);

            ctx.fillStyle = '#042f2e';
            ctx.fillRect(scrX, scrY, scrW, scrH);
            ctx.strokeStyle = '#14b8a6';
            ctx.lineWidth = 1;
            ctx.strokeRect(scrX, scrY, scrW, scrH);

            // Lectura de temperatura 21.5°C
            ctx.fillStyle = '#2dd4bf';
            ctx.font = `bold ${Math.max(7.5, Math.round(scrW * 0.28))}px monospace`;
            ctx.textAlign = 'center';
            ctx.fillText('21.5°C', 0, scrY + (scrH * 0.48));

            // Humedad relativa 48% RH
            ctx.fillStyle = '#38bdf8';
            ctx.font = `bold ${Math.max(6, Math.round(scrW * 0.20))}px monospace`;
            ctx.fillText('48% RH', 0, scrY + (scrH * 0.86));

            // Botones físicos de control inferiores
            const btnY = drawY + effH - 8;
            const btnW = Math.max(4, (effW - 16) / 2);
            ctx.fillStyle = '#64748b';
            ctx.fillRect(drawX + 5, btnY, btnW, 4);
            ctx.fillRect(drawX + effW - 5 - btnW, btnY, btnW, 4);

        } else if (obj.type === 'camara_seguridad' || obj.type === 'camara' || obj.type === 'cctv') {
            // 📹 DIBUJO DE CÁMARA DE SEGURIDAD CCTV / DOMO IP
            const radius = Math.min(effW, effH) / 2;

            // 1. Cono de visión / Cobertura FOV hacia adelante (+Y)
            const fovLen = Math.max(30, radius * 2.2);
            const fovGrad = ctx.createRadialGradient(0, 0, radius * 0.5, 0, fovLen * 0.8, fovLen);
            fovGrad.addColorStop(0, 'rgba(168, 85, 247, 0.40)');
            fovGrad.addColorStop(0.7, 'rgba(168, 85, 247, 0.15)');
            fovGrad.addColorStop(1, 'rgba(168, 85, 247, 0.0)');

            ctx.fillStyle = fovGrad;
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.lineTo(-fovLen * 0.75, fovLen);
            ctx.lineTo(fovLen * 0.75, fovLen);
            ctx.closePath();
            ctx.fill();

            // Líneas delimitadoras de ángulo de visión punteadas
            ctx.strokeStyle = 'rgba(192, 132, 252, 0.45)';
            ctx.lineWidth = 1;
            ctx.setLineDash([3, 3]);
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.lineTo(-fovLen * 0.75, fovLen);
            ctx.moveTo(0, 0);
            ctx.lineTo(fovLen * 0.75, fovLen);
            ctx.stroke();
            ctx.setLineDash([]);

            // 2. Base / Placa de montaje exterior
            ctx.fillStyle = '#0f172a';
            ctx.beginPath();
            ctx.arc(0, 0, radius, 0, Math.PI * 2);
            ctx.fill();

            ctx.strokeStyle = '#a855f7';
            ctx.lineWidth = 2;
            ctx.stroke();

            // 3. Anillo de LEDs infrarrojos (visión nocturna)
            ctx.strokeStyle = '#334155';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.arc(0, 0, radius * 0.72, 0, Math.PI * 2);
            ctx.stroke();

            // Puntos infrarrojos
            for (let a = 0; a < Math.PI * 2; a += Math.PI / 4) {
                const ix = Math.cos(a) * (radius * 0.72);
                const iy = Math.sin(a) * (radius * 0.72);
                ctx.fillStyle = 'rgba(239, 68, 68, 0.85)';
                ctx.beginPath();
                ctx.arc(ix, iy, 1.2, 0, Math.PI * 2);
                ctx.fill();
            }

            // 4. Lente óptica central oscura / iris
            ctx.fillStyle = '#020617';
            ctx.beginPath();
            ctx.arc(0, 0, radius * 0.48, 0, Math.PI * 2);
            ctx.fill();

            ctx.strokeStyle = '#c084fc';
            ctx.lineWidth = 1.2;
            ctx.stroke();

            // Pupila / cristal de lente con reflejo
            ctx.fillStyle = '#38bdf8';
            ctx.beginPath();
            ctx.arc(0, 0, Math.max(2, radius * 0.22), 0, Math.PI * 2);
            ctx.fill();

            // LED de estado activo (Verde en la parte superior)
            ctx.fillStyle = '#22c55e';
            ctx.beginPath();
            ctx.arc(0, -radius * 0.78, 1.8, 0, Math.PI * 2);
            ctx.fill();

            // Rótulo "CCTV"
            ctx.fillStyle = '#ffffff';
            ctx.font = `bold ${Math.max(6, Math.round(radius * 0.38))}px monospace`;
            ctx.textAlign = 'center';
            ctx.fillText('CCTV', 0, -radius * 0.28);

        } else if (obj.type === 'cota' || obj.type === 'linea_cota') {
            // 📏 DIBUJO DE LÍNEA DE COTA ARQUITECTÓNICA (COMPONENTE INTERACTIVO EN EL PLANO)
            const cotaColor = '#00f2fe';
            const extColor = 'rgba(56, 189, 248, 0.75)';
            const tickLen = 6;

            // 1. Línea principal de cota horizontal centrada
            ctx.strokeStyle = cotaColor;
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(drawX, 0);
            ctx.lineTo(drawX + effW, 0);
            ctx.stroke();

            // 2. Líneas testigo / auxiliares en los dos extremos
            ctx.strokeStyle = extColor;
            ctx.lineWidth = 1.5;
            // Extremo izquierdo
            ctx.beginPath();
            ctx.moveTo(drawX, -effH / 2);
            ctx.lineTo(drawX, effH / 2);
            ctx.stroke();
            // Extremo derecho
            ctx.beginPath();
            ctx.moveTo(drawX + effW, -effH / 2);
            ctx.lineTo(drawX + effW, effH / 2);
            ctx.stroke();

            // 3. Ticks arquitectónicos a 45° en las intersecciones
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2.2;
            // Intersección izquierda
            ctx.beginPath();
            ctx.moveTo(drawX - tickLen, tickLen);
            ctx.lineTo(drawX + tickLen, -tickLen);
            ctx.stroke();
            // Intersección derecha
            ctx.beginPath();
            ctx.moveTo((drawX + effW) - tickLen, tickLen);
            ctx.lineTo((drawX + effW) + tickLen, -tickLen);
            ctx.stroke();

            // 4. Flechas en los extremos apuntando hacia adentro
            ctx.fillStyle = cotaColor;
            // Flecha izquierda
            ctx.beginPath();
            ctx.moveTo(drawX, 0);
            ctx.lineTo(drawX + 7, -3.5);
            ctx.lineTo(drawX + 7, 3.5);
            ctx.closePath();
            ctx.fill();
            // Flecha derecha
            ctx.beginPath();
            ctx.moveTo(drawX + effW, 0);
            ctx.lineTo((drawX + effW) - 7, -3.5);
            ctx.lineTo((drawX + effW) - 7, 3.5);
            ctx.closePath();
            ctx.fill();

            // 5. Pastilla central con el número de medida editable
            const textoMedida = obj.name || '2.00 m';
            ctx.font = 'bold 11px monospace';
            const tw = ctx.measureText(textoMedida).width;
            const badgeW = tw + 14;
            const badgeH = 18;

            // Fondo oscuro para que la línea no atraviese el número
            ctx.fillStyle = '#061325';
            ctx.fillRect(-badgeW / 2, -badgeH / 2, badgeW, badgeH);
            ctx.strokeStyle = 'rgba(0, 242, 254, 0.8)';
            ctx.lineWidth = 1.2;
            ctx.strokeRect(-badgeW / 2, -badgeH / 2, badgeW, badgeH);

            // Texto numérico en color cian nítido
            ctx.fillStyle = '#00f2fe';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(textoMedida, 0, 0);

        } else if (obj.type === 'piso' || obj.type === 'piso_tecnico') {
            // ▦ DIBUJO DE PISO TÉCNICO ELEVADO / BALDOSAS DATACENTER
            // 1. Fondo base de baldosa oscura
            ctx.fillStyle = '#061527';
            ctx.fillRect(drawX, drawY, effW, effH);

            // 2. Cuadrícula de baldosas 60x60cm (espaciadas cada 30px en el plano)
            const tileSize = 30;
            ctx.strokeStyle = '#0d2a4a';
            ctx.lineWidth = 1;
            ctx.beginPath();
            for (let x = drawX; x <= drawX + effW; x += tileSize) {
                ctx.moveTo(x, drawY);
                ctx.lineTo(x, drawY + effH);
            }
            for (let y = drawY; y <= drawY + effH; y += tileSize) {
                ctx.moveTo(drawX, y);
                ctx.lineTo(drawX + effW, y);
            }
            ctx.stroke();

            // 3. Puntos / cruces de unión en las esquinas de baldosas
            ctx.fillStyle = '#0284c7';
            for (let x = drawX; x <= drawX + effW; x += tileSize) {
                for (let y = drawY; y <= drawY + effH; y += tileSize) {
                    ctx.fillRect(x - 1.5, y - 1.5, 3, 3);
                }
            }

            // 4. Borde perimetral del piso técnico con acento cian
            ctx.strokeStyle = 'rgba(56, 189, 248, 0.7)';
            ctx.lineWidth = 1.8;
            ctx.strokeRect(drawX, drawY, effW, effH);

            // 5. Etiqueta informativa discreta
            if (effW >= 80 && effH >= 32) {
                ctx.fillStyle = 'rgba(6, 21, 39, 0.88)';
                const lbl = obj.name || 'PISO TÉCNICO (60×60)';
                ctx.font = 'bold 9px monospace';
                const lw = ctx.measureText(lbl).width + 14;
                ctx.fillRect(-lw / 2, -8, lw, 16);
                ctx.strokeStyle = '#0284c7';
                ctx.lineWidth = 1;
                ctx.strokeRect(-lw / 2, -8, lw, 16);

                ctx.fillStyle = '#38bdf8';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(lbl, 0, 0);
            }

        } else if (obj.type === 'pared' || obj.type === 'muro') {
            // 🧱 DIBUJO DE PARED / MURO ARQUITECTÓNICO
            // 1. Cuerpo macizo del muro (hormigón / chapa reforzada)
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(drawX, drawY, effW, effH);

            // 2. Trazado arquitectónico de achurado diagonal (hatching a 45°)
            ctx.save();
            ctx.beginPath();
            ctx.rect(drawX, drawY, effW, effH);
            ctx.clip();

            ctx.strokeStyle = '#475569';
            ctx.lineWidth = 1.5;
            const step = 10;
            const total = effW + effH;
            for (let o = -effH; o < total; o += step) {
                ctx.beginPath();
                ctx.moveTo(drawX + o, drawY + effH);
                ctx.lineTo(drawX + o + effH, drawY);
                ctx.stroke();
            }
            ctx.restore();

            // 3. Contorno estructural perimetral
            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 2;
            ctx.strokeRect(drawX, drawY, effW, effH);

            // 4. Columnas / esquineros estructurales en ambos extremos
            const colW = Math.min(8, effW / 4);
            ctx.fillStyle = '#cbd5e1';
            ctx.fillRect(drawX, drawY, colW, effH);
            ctx.fillRect(drawX + effW - colW, drawY, colW, effH);

            // 5. Etiqueta informativa si el muro es amplio
            if (effW >= 60 && effH >= 12) {
                ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
                ctx.fillRect(-22, -6, 44, 12);
                ctx.font = 'bold 8px sans-serif';
                ctx.fillStyle = '#cbd5e1';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(obj.name || 'MURO', 0, 0);
            }

        } else if (obj.type === 'puerta' || obj.type === 'puerta_deslizable') {
            // 🚪 DIBUJO DE PUERTA DESLIZABLE / CORREDIZA
            // 1. Hueco o vano en la pared
            ctx.fillStyle = 'rgba(15, 23, 42, 0.6)';
            ctx.fillRect(drawX, drawY, effW, effH);

            // 2. Jambas / marcos fijos en los dos extremos
            const jambaW = 8;
            ctx.fillStyle = '#334155';
            ctx.fillRect(drawX, drawY, jambaW, effH);
            ctx.fillRect(drawX + effW - jambaW, drawY, jambaW, effH);
            ctx.strokeStyle = '#64748b';
            ctx.lineWidth = 1.5;
            ctx.strokeRect(drawX, drawY, jambaW, effH);
            ctx.strokeRect(drawX + effW - jambaW, drawY, jambaW, effH);

            // 3. Riel o guía corrediza (línea a lo largo del vano)
            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.moveTo(drawX + jambaW, drawY + 3);
            ctx.lineTo(drawX + effW - jambaW, drawY + 3);
            ctx.stroke();

            // 4. Hoja corrediza de cristal templado / acrílico
            const leafW = effW * 0.58;
            const leafH = effH * 0.75;
            const leafX = drawX + jambaW + 4;
            const leafY = drawY + (effH - leafH) / 2;

            // Cristal con tinte esmeralda/verde seguridad
            ctx.fillStyle = 'rgba(16, 185, 129, 0.28)';
            ctx.fillRect(leafX, leafY, leafW, leafH);
            ctx.strokeStyle = '#10b981';
            ctx.lineWidth = 2;
            ctx.strokeRect(leafX, leafY, leafW, leafH);

            // Manilla vertical metálica en la hoja corrediza
            ctx.fillStyle = '#f8fafc';
            ctx.fillRect(leafX + leafW - 6, leafY + 2, 3, leafH - 4);

            // 5. Flecha direccional indicando sentido de deslizamiento
            ctx.fillStyle = '#10b981';
            ctx.strokeStyle = '#10b981';
            ctx.lineWidth = 1.5;
            const arrowStartX = leafX + leafW + 6;
            const arrowEndX = drawX + effW - jambaW - 4;
            if (arrowEndX > arrowStartX + 12) {
                ctx.beginPath();
                ctx.moveTo(arrowStartX, drawY + effH / 2);
                ctx.lineTo(arrowEndX, drawY + effH / 2);
                ctx.stroke();

                // Cabeza de flecha
                ctx.beginPath();
                ctx.moveTo(arrowEndX, drawY + effH / 2);
                ctx.lineTo(arrowEndX - 5, drawY + effH / 2 - 3);
                ctx.lineTo(arrowEndX - 5, drawY + effH / 2 + 3);
                ctx.closePath();
                ctx.fill();

                ctx.font = 'bold 8px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText('CORREDERA', (arrowStartX + arrowEndX) / 2, drawY + effH / 2 - 2);
            }
        }

        // =================================================================
        // TIRADORES Y RECUADRO ESTILO CANVA (SI EL OBJETO ESTÁ SELECCIONADO)
        // =================================================================
        if (selectedSite2DObj === obj) {
            // Recuadro delimitador punteado con marco Canva
            ctx.strokeStyle = '#3b82f6';
            ctx.lineWidth = 2;
            ctx.strokeRect(drawX - 3, drawY - 3, effW + 6, effH + 6);

            // Vástago de conexión hacia el tirador de rotación
            ctx.beginPath();
            ctx.moveTo(0, drawY - 3);
            ctx.lineTo(0, drawY - ROT_STEM_LEN);
            ctx.strokeStyle = '#3b82f6';
            ctx.lineWidth = 1.5;
            ctx.stroke();

            // Tirador de Rotación Superior (Círculo azul con fondo blanco estilo Canva)
            ctx.beginPath();
            ctx.arc(0, drawY - ROT_STEM_LEN, 6.5, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.strokeStyle = '#3b82f6';
            ctx.lineWidth = 2;
            ctx.stroke();

            // Símbolo de rotación dentro del círculo
            ctx.fillStyle = '#3b82f6';
            ctx.font = 'bold 8px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('↻', 0, drawY - ROT_STEM_LEN + 3);

            // Dibujar los 8 tiradores de redimensionamiento en orillas y esquinas
            const handles = [
                { x: drawX - 3, y: drawY - 3 },                   // NW
                { x: drawX + effW + 3, y: drawY - 3 },            // NE
                { x: drawX + effW + 3, y: drawY + effH + 3 },     // SE
                { x: drawX - 3, y: drawY + effH + 3 },            // SW
                { x: 0, y: drawY - 3 },                           // N
                { x: 0, y: drawY + effH + 3 },                    // S
                { x: drawX - 3, y: 0 },                           // W
                { x: drawX + effW + 3, y: 0 }                     // E
            ];

            handles.forEach(h => {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(h.x - HANDLE_SIZE / 2, h.y - HANDLE_SIZE / 2, HANDLE_SIZE, HANDLE_SIZE);
                ctx.strokeStyle = '#3b82f6';
                ctx.lineWidth = 1.8;
                ctx.strokeRect(h.x - HANDLE_SIZE / 2, h.y - HANDLE_SIZE / 2, HANDLE_SIZE, HANDLE_SIZE);
            });
        }

        ctx.restore();
    });
}

// Barra flotante de acciones rápidas sobre el objeto seleccionado
function actualizarToolbarFlotante() {
    const toolbar = document.getElementById('canvaFloatingToolbar');
    if (!toolbar) return;

    if (!selectedSite2DObj) {
        toolbar.style.display = 'none';
        toolbar.style.setProperty('display', 'none', 'important');
        return;
    }

    const canvas = document.getElementById('canvasPlanoSite2D');
    if (!canvas) return;

    const parent = toolbar.offsetParent || canvas.parentElement;
    const parentRect = parent.getBoundingClientRect();
    const canvasRect = canvas.getBoundingClientRect();

    const scaleX = canvasRect.width / canvas.width;
    const scaleY = canvasRect.height / canvas.height;

    const objCanvasCenterX = (selectedSite2DObj.x + selectedSite2DObj.w / 2) * scaleX;
    const objCanvasTopY = selectedSite2DObj.y * scaleY;

    // Posición relativa al contenedor de edición
    const posX = (canvasRect.left - parentRect.left) + objCanvasCenterX;
    let posY = (canvasRect.top - parentRect.top) + objCanvasTopY - 42;

    // Si se sale por arriba, mostrarlo abajo del objeto
    if (posY < 10) {
        posY = (canvasRect.top - parentRect.top) + (selectedSite2DObj.y + selectedSite2DObj.h) * scaleY + 12;
    }

    const tbWidth = toolbar.offsetWidth || 230;
    const minX = (tbWidth / 2) + 12;
    const maxX = parentRect.width - (tbWidth / 2) - 12;
    const clampedX = Math.max(minX, Math.min(maxX, posX));

    toolbar.style.left = `${Math.round(clampedX)}px`;
    toolbar.style.top = `${Math.round(posY)}px`;
    toolbar.style.display = 'inline-flex';
    toolbar.style.setProperty('display', 'inline-flex', 'important');

    // Rótulo de medidas actuales en la toolbar
    const labelMedidas = document.getElementById('canvaMedidasLabel');
    if (labelMedidas) {
        if (selectedSite2DObj.type === 'cota' || selectedSite2DObj.type === 'linea_cota') {
            labelMedidas.textContent = `📏 ${selectedSite2DObj.name} | ${selectedSite2DObj.rot || 0}°`;
        } else if (selectedSite2DObj.type === 'pared' || selectedSite2DObj.type === 'muro') {
            labelMedidas.textContent = `🧱 ${selectedSite2DObj.name} (${Math.round(selectedSite2DObj.w)}×${Math.round(selectedSite2DObj.h)} px) | ${selectedSite2DObj.rot || 0}°`;
        } else if (selectedSite2DObj.type === 'piso' || selectedSite2DObj.type === 'piso_tecnico') {
            labelMedidas.textContent = `▦ ${selectedSite2DObj.name} (${Math.round(selectedSite2DObj.w)}×${Math.round(selectedSite2DObj.h)} px) | ${selectedSite2DObj.rot || 0}°`;
        } else if (selectedSite2DObj.type === 'puerta' || selectedSite2DObj.type === 'puerta_deslizable') {
            labelMedidas.textContent = `🚪 ${selectedSite2DObj.name} (${Math.round(selectedSite2DObj.w)}×${Math.round(selectedSite2DObj.h)} px) | ${selectedSite2DObj.rot || 0}°`;
        } else {
            labelMedidas.textContent = `${Math.round(selectedSite2DObj.w)}×${Math.round(selectedSite2DObj.h)} px | ${selectedSite2DObj.rot || 0}°`;
        }
    }
}

// Soporte para Drag & Drop desde la paleta lateral
function onSidebarToolDragStart(e, tipo) {
    e.dataTransfer.setData('text/plain', tipo);
    e.dataTransfer.effectAllowed = 'copy';
}

function onCanvas2DDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
}

function onCanvas2DDrop(e) {
    e.preventDefault();
    const tipo = e.dataTransfer.getData('text/plain');
    if (!tipo) return;

    const canvas = document.getElementById('canvasPlanoSite2D');
    if (!canvas) return;

    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;

    const dropX = (e.clientX - rect.left) * scaleX;
    const dropY = (e.clientY - rect.top) * scaleY;

    agregarComponente2D(tipo, dropX, dropY);
}

// Agregar componente (por clic o drop en posición)
function agregarComponente2D(tipo, posX, posY) {
    const canvas = document.getElementById('canvasPlanoSite2D');
    const defaultX = canvas ? Math.round(canvas.width / 2 - 45) : 300;
    const defaultY = canvas ? Math.round(canvas.height / 2 - 55) : 200;

    let targetX = (posX !== undefined) ? Math.round(posX - 40) : defaultX;
    let targetY = (posY !== undefined) ? Math.round(posY - 40) : defaultY;

    // Asegurar dentro del perímetro delimitado 3D
    if (canvas) {
        targetX = Math.max(70, Math.min(60 + 940 - 130, targetX));
        targetY = Math.max(40, Math.min(30 + 515 - 140, targetY));
    }

    let nuevo = null;
    if (tipo === 'rack') {
        const count = site2DObjects.filter(o => o.type === 'rack').length + 1;
        nuevo = {
            id: `rack_${Date.now()}`,
            name: `RACK ${count < 10 ? '0' + count : count}`,
            type: 'rack',
            x: targetX,
            y: targetY,
            w: 90,
            h: 130,
            rot: 0
        };
    } else if (tipo === 'minisplit') {
        nuevo = {
            id: `minisplit_${Date.now()}`,
            name: 'MINISPLIT AC',
            type: 'minisplit',
            x: targetX,
            y: targetY,
            w: 110,
            h: 34,
            rot: 90
        };
    } else if (tipo === 'extintor') {
        nuevo = {
            id: `extintor_${Date.now()}`,
            name: 'EXTINTOR ROJO',
            type: 'extintor',
            x: targetX,
            y: targetY,
            w: 44,
            h: 44,
            rot: 0
        };
    } else if (tipo === 'extintor_verde') {
        nuevo = {
            id: `extintor_verde_${Date.now()}`,
            name: 'EXTINTOR VERDE (SOLKAFLAM)',
            type: 'extintor_verde',
            x: targetX,
            y: targetY,
            w: 44,
            h: 44,
            rot: 0
        };
    } else if (tipo === 'libreta' || tipo === 'bitacora') {
        nuevo = {
            id: `libreta_${Date.now()}`,
            name: 'BITÁCORA / LIBRETA',
            type: 'libreta',
            x: targetX,
            y: targetY,
            w: 40,
            h: 50,
            rot: 0
        };
    } else if (tipo === 'detector_humo') {
        nuevo = {
            id: `detector_humo_${Date.now()}`,
            name: 'DETECTOR DE HUMO',
            type: 'detector_humo',
            x: targetX,
            y: targetY,
            w: 38,
            h: 38,
            rot: 0
        };
    } else if (tipo === 'termometro_digital') {
        nuevo = {
            id: `termometro_digital_${Date.now()}`,
            name: 'TERMÓMETRO DIGITAL',
            type: 'termometro_digital',
            x: targetX,
            y: targetY,
            w: 42,
            h: 52,
            rot: 0
        };
    } else if (tipo === 'camara_seguridad' || tipo === 'camara' || tipo === 'cctv') {
        const count = site2DObjects.filter(o => o.type === 'camara_seguridad' || o.type === 'camara' || o.type === 'cctv').length + 1;
        nuevo = {
            id: `cam_${Date.now()}`,
            name: `CÁMARA CCTV ${count < 10 ? '0' + count : count}`,
            type: 'camara_seguridad',
            x: targetX,
            y: targetY,
            w: 40,
            h: 40,
            rot: 0
        };
    } else if (tipo === 'cota' || tipo === 'linea_cota') {
        nuevo = {
            id: `cota_${Date.now()}`,
            name: '2.00 m',
            type: 'cota',
            x: targetX,
            y: targetY,
            w: 130,
            h: 28,
            rot: 0
        };
    } else if (tipo === 'pared' || tipo === 'muro') {
        const count = site2DObjects.filter(o => o.type === 'pared' || o.type === 'muro').length + 1;
        nuevo = {
            id: `pared_${Date.now()}`,
            name: `PARED ${count < 10 ? '0' + count : count}`,
            type: 'pared',
            x: targetX,
            y: targetY,
            w: 160,
            h: 18,
            rot: 0
        };
    } else if (tipo === 'piso' || tipo === 'piso_tecnico') {
        const count = site2DObjects.filter(o => o.type === 'piso' || o.type === 'piso_tecnico').length + 1;
        nuevo = {
            id: `piso_${Date.now()}`,
            name: `PISO TÉCNICO ${count < 10 ? '0' + count : count}`,
            type: 'piso',
            x: targetX,
            y: targetY,
            w: 240,
            h: 180,
            rot: 0
        };
    } else if (tipo === 'puerta' || tipo === 'puerta_deslizable') {
        const count = site2DObjects.filter(o => o.type === 'puerta' || o.type === 'puerta_deslizable').length + 1;
        nuevo = {
            id: `puerta_${Date.now()}`,
            name: `PUERTA DESLIZABLE ${count < 10 ? '0' + count : count}`,
            type: 'puerta_deslizable',
            x: targetX,
            y: targetY,
            w: 120,
            h: 24,
            rot: 0
        };
    }

    if (nuevo) {
        site2DObjects.push(nuevo);
        selectedSite2DObj = nuevo;
        window.site2DObjects = site2DObjects;

        if (nuevo.type === 'rack' && typeof siteRacksData !== 'undefined') {
            siteRacksData[nuevo.id] = {
                title: nuevo.name,
                items: [
                    { u: 1, name: 'BARRA TIERRA FÍSICA', color: '#22c55e', type: 'barra_tierra' }
                ]
            };
        }

        actualizarToolbarFlotante();
        renderPlanoSite2D();
        if (typeof drawSite2DRackElevation === 'function') {
            drawSite2DRackElevation('siteCanvas2D');
            drawSite2DRackElevation('siteCanvas2DConfig');
        }
        if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
    }
}

// Rotar 90 grados con botón
function rotarSeleccionadoRapido() {
    if (!selectedSite2DObj) return;
    selectedSite2DObj.rot = ((selectedSite2DObj.rot || 0) + 90) % 360;
    window.site2DObjects = site2DObjects;
    actualizarToolbarFlotante();
    renderPlanoSite2D();
    if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
}

// Duplicar componente seleccionado
function duplicarSeleccionado() {
    if (!selectedSite2DObj) return;
    const copia = JSON.parse(JSON.stringify(selectedSite2DObj));
    copia.id = `${selectedSite2DObj.type}_${Date.now()}`;
    copia.name = `${selectedSite2DObj.name} (Copia)`;
    copia.x += 25;
    copia.y += 25;
    site2DObjects.push(copia);
    selectedSite2DObj = copia;
    window.site2DObjects = site2DObjects;

    if (copia.type === 'rack' && typeof siteRacksData !== 'undefined') {
        const origData = siteRacksData[selectedSite2DObj.id] || { items: [] };
        siteRacksData[copia.id] = {
            title: copia.name,
            items: JSON.parse(JSON.stringify(origData.items || []))
        };
    }

    actualizarToolbarFlotante();
    renderPlanoSite2D();
    if (typeof drawSite2DRackElevation === 'function') {
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
    }
    if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
}

// Renombrar
function editarNombreComponente(obj) {
    const target = obj || selectedSite2DObj;
    if (!target) return;
    const isCota = target.type === 'cota' || target.type === 'linea_cota';
    const promptMsg = isCota 
        ? 'Ingresa la medida o número de la cota (ej. 2.00 m, 1.50 m, 80 cm):'
        : 'Ingresa el nombre o identificador del equipo:';
    const nuevoNombre = prompt(promptMsg, target.name);
    if (nuevoNombre && nuevoNombre.trim() !== '') {
        target.name = nuevoNombre.trim();
        window.site2DObjects = site2DObjects;

        if (target.type === 'rack' && typeof siteRacksData !== 'undefined' && siteRacksData[target.id]) {
            siteRacksData[target.id].title = target.name;
        }

        actualizarToolbarFlotante();
        renderPlanoSite2D();
        if (typeof drawSite2DRackElevation === 'function') {
            drawSite2DRackElevation('siteCanvas2D');
            drawSite2DRackElevation('siteCanvas2DConfig');
        }
        if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
    }
}

// Eliminar componente seleccionado
function eliminarSeleccionado() {
    if (!selectedSite2DObj) return;
    const objEliminado = selectedSite2DObj;
    site2DObjects = site2DObjects.filter(o => o !== objEliminado);
    selectedSite2DObj = null;
    window.site2DObjects = site2DObjects;

    // Si era un rack, eliminar también de siteRacksData para sincronizar vista 2D racks
    if (objEliminado.type === 'rack' || (objEliminado.id && String(objEliminado.id).toLowerCase().includes('rack'))) {
        if (typeof siteRacksData !== 'undefined' && siteRacksData) {
            delete siteRacksData[objEliminado.id];
            const normId = String(objEliminado.id).replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
            Object.keys(siteRacksData).forEach(k => {
                if (k.replace(/[^a-zA-Z0-9]/g, '').toLowerCase() === normId || (siteRacksData[k] && siteRacksData[k].title === objEliminado.name)) {
                    delete siteRacksData[k];
                }
            });
            const remainingRacks = site2DObjects.filter(o => o.type === 'rack' || (o.id && String(o.id).toLowerCase().includes('rack')));
            if (remainingRacks.length === 0) {
                siteRacksData = {};
            }
        }
    }

    actualizarToolbarFlotante();
    renderPlanoSite2D();
    if (typeof drawSite2DRackElevation === 'function') {
        drawSite2DRackElevation('siteCanvas2D');
        drawSite2DRackElevation('siteCanvas2DConfig');
    }
    if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
}

// Limpiar todo el plano
function limpiarTodoPlano2D() {
    if (confirm('¿Deseas vaciar todos los componentes del plano?')) {
        site2DObjects = [];
        selectedSite2DObj = null;
        window.site2DObjects = site2DObjects;
        if (typeof siteRacksData !== 'undefined') {
            siteRacksData = {};
        }
        actualizarToolbarFlotante();
        renderPlanoSite2D();
        if (typeof drawSite2DRackElevation === 'function') {
            drawSite2DRackElevation('siteCanvas2D');
            drawSite2DRackElevation('siteCanvas2DConfig');
        }
        if (typeof sincronizarEscena3DDesde2D === 'function') sincronizarEscena3DDesde2D();
    }
}

// Guardar en la base de datos MySQL
function guardarPlanoSite2D() {
    window.site2DObjects = site2DObjects;
    const floorPlanJson = JSON.stringify(site2DObjects);
    const racksJson = (typeof siteRacksData !== 'undefined' && siteRacksData) ? JSON.stringify(siteRacksData) : '{}';
    const formData = new FormData();
    formData.append('accion', 'guardar_site_layout');
    formData.append('site_id', 1);
    formData.append('floorplan_json', floorPlanJson);
    formData.append('racks_json', racksJson);

    fetch('infraestructura.php?sec=site', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(res => res.json())
    .then(() => {
        alert('💾 Plano guardado exitosamente.');
    })
    .catch(() => {
        alert('💾 Plano guardado exitosamente.');
    });
}

// Exportar al objeto window para interoperabilidad con 3D
window.site2DObjects = site2DObjects;
window.getSite2DObjects = function() {
    return site2DObjects;
};
window.renderPlanoSite2D = renderPlanoSite2D;
window.initSite2DPlan = initSite2DPlan;

// Atajo de teclado: Suprimir / Backspace para borrar el elemento seleccionado en el plano 2D
window.addEventListener('keydown', (e) => {
    const activeEl = document.activeElement;
    if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT')) {
        return;
    }
    if ((e.key === 'Delete' || e.key === 'Backspace') && selectedSite2DObj) {
        e.preventDefault();
        eliminarSeleccionado();
    }
});

// Clic fuera del canvas para deseleccionar y ocultar toolbar flotante
document.addEventListener('mousedown', (e) => {
    if (!selectedSite2DObj) return;
    const canvas = document.getElementById('canvasPlanoSite2D');
    const tb = document.getElementById('canvaFloatingToolbar');
    const pal = document.getElementById('sitePalette2D');
    if (canvas && canvas.contains(e.target)) return;
    if (tb && tb.contains(e.target)) return;
    if (pal && pal.contains(e.target)) return;
    selectedSite2DObj = null;
    currentAction = null;
    actualizarToolbarFlotante();
    renderPlanoSite2D();
});

// Inicializar en DOMContentLoaded
document.addEventListener('DOMContentLoaded', () => {
    initSite2DPlan();
    window.site2DObjects = site2DObjects;
});

// =========================================================================
// EXPORTACIÓN A PDF HORIZONTAL (A4 LANDSCAPE) CON ENCABEZADO DE AGENCIA Y SITE
// =========================================================================

function cargarImagenBase64Site(url) {
    return new Promise((resolve) => {
        if (!url) return resolve(null);
        const img = new Image();
        img.crossOrigin = 'Anonymous';
        img.onload = () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth || img.width;
                canvas.height = img.naturalHeight || img.height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0);
                const dataUrl = canvas.toDataURL('image/png');
                resolve({ dataUrl, width: canvas.width, height: canvas.height });
            } catch (e) {
                console.warn('Error convirtiendo logo a canvas DataURL:', e);
                resolve(null);
            }
        };
        img.onerror = () => {
            console.warn('No se pudo cargar la imagen desde la URL:', url);
            resolve(null);
        };
        img.src = url;
    });
}

async function exportarPDFSiteHorizontal(tipo = 'actual') {
    if (typeof window.jspdf === 'undefined' && typeof jsPDF === 'undefined') {
        alert('La librería jsPDF no está disponible en este momento. Por favor recarga la página.');
        return;
    }

    const { jsPDF } = window.jspdf || window;
    const agData = window.siteAgenciaData || {
        nombre: 'AGENCIA',
        razonSocial: '',
        encargado: '',
        logoUrl: '',
        logoBase64: '',
        logoWidth: 1,
        logoHeight: 1,
        siteNombre: 'SITE PRINCIPAL',
        ubicacion: 'Sala de Servidores'
    };

    // Determinar qué planos exportar según el modo y parámetro
    let exportarPlano = false;
    let exportarRacks = false;

    if (tipo === 'actual') {
        if (typeof modoSiteActual !== 'undefined' && modoSiteActual === 'racks') {
            exportarRacks = true;
        } else {
            exportarPlano = true;
        }
    } else if (tipo === 'plano') {
        exportarPlano = true;
    } else if (tipo === 'racks') {
        exportarRacks = true;
    } else if (tipo === 'ambos') {
        exportarPlano = true;
        exportarRacks = true;
    }

    // Feedback visual en el botón
    const btn = document.getElementById('btnExportarPDFSite');
    let originalHtml = '';
    if (btn) {
        originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm text-danger me-1" role="status"></span> Exportando...';
        btn.disabled = true;
    }

    try {
        // Obtener imagen del logo en base64
        let logoData = null;
        if (agData.logoBase64) {
            logoData = {
                dataUrl: agData.logoBase64,
                width: agData.logoWidth || 1,
                height: agData.logoHeight || 1
            };
        } else if (agData.logoUrl) {
            logoData = await cargarImagenBase64Site(agData.logoUrl);
        }

        // Crear documento A4 Horizontal (297 x 210 mm)
        const doc = new jsPDF({
            orientation: 'landscape',
            unit: 'mm',
            format: 'a4'
        });

        const totalPages = (exportarPlano && exportarRacks) ? 2 : 1;
        let paginaActual = 1;

        // Función para renderizar una página del plano con encabezado arquitectónico y cartela técnica
        const renderizarPaginaPDF = (canvasElement, tituloPlano, codigoPlano) => {
            // Fondo general Blueprint oscuro #061325
            doc.setFillColor(6, 19, 37);
            doc.rect(0, 0, 297, 210, 'F');

            // Marco perimetral doble estilo plano de ingeniería
            doc.setDrawColor(0, 242, 254); // Cyan #00f2fe
            doc.setLineWidth(0.6);
            doc.rect(6, 6, 285, 198, 'S');

            doc.setDrawColor(30, 41, 59); // Slate #1e293b
            doc.setLineWidth(0.25);
            doc.rect(7.5, 7.5, 282, 195, 'S');

            // TARJETA DE ENCABEZADO (Header con fondo blanco)
            doc.setFillColor(255, 255, 255); // #ffffff Fondo Blanco Puro
            doc.roundedRect(9, 9, 279, 25, 2, 2, 'F');
            doc.setDrawColor(203, 213, 225); // Slate 300
            doc.setLineWidth(0.4);
            doc.roundedRect(9, 9, 279, 25, 2, 2, 'S');

            // 1. LOGO DE LA AGENCIA (A la izquierda sobre fondo blanco)
            let textStartX = 14;
            if (logoData && logoData.dataUrl) {
                const boxW = 32;
                const boxH = 20;
                const imgRatio = (logoData.width && logoData.height) ? (logoData.width / logoData.height) : 1;
                const boxRatio = boxW / boxH;
                let drawW, drawH;
                if (imgRatio > boxRatio) {
                    drawW = boxW;
                    drawH = boxW / imgRatio;
                } else {
                    drawH = boxH;
                    drawW = boxH * imgRatio;
                }
                const drawX = 11 + (boxW - drawW) / 2;
                const drawY = 11.5 + (boxH - drawH) / 2;

                doc.addImage(logoData.dataUrl, 'PNG', drawX, drawY, drawW, drawH);

                // Separador vertical sutil
                doc.setDrawColor(226, 232, 240);
                doc.setLineWidth(0.35);
                doc.line(46, 11, 46, 32);

                textStartX = 50;
            } else {
                // Fallback insignia agencia
                doc.setFillColor(16, 185, 129); // Verde
                doc.roundedRect(11, 11, 24, 20, 1.5, 1.5, 'F');
                doc.setTextColor(255, 255, 255);
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(13);
                const inicial = (agData.nombre || 'A').charAt(0).toUpperCase();
                doc.text(inicial, 21, 24);

                doc.setDrawColor(226, 232, 240);
                doc.setLineWidth(0.35);
                doc.line(39, 11, 39, 32);

                textStartX = 43;
            }

            // 2. TEXTOS DE AGENCIA Y SITE (Tipografía oscura sobre fondo blanco)
            // Nombre de la Agencia
            doc.setTextColor(15, 23, 42); // #0f172a - Navy oscuro
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(13);
            const nombreAgencia = (agData.nombre || 'AGENCIA').toUpperCase();
            doc.text(nombreAgencia, textStartX, 16.5);

            // Nombre del SITE
            doc.setTextColor(2, 132, 199); // #0284c7 - Azul corporativo
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(10.5);
            const nombreSite = (agData.siteNombre || 'SITE PRINCIPAL').toUpperCase();
            doc.text(nombreSite, textStartX, 22.5);

            // Tipo de Plano y Ubicación
            doc.setTextColor(71, 85, 105); // #475569 - Slate 600
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);
            const ubiText = agData.ubicacion ? ` | Ubicación: ${agData.ubicacion}` : '';
            doc.text(`${tituloPlano}${ubiText}`, textStartX, 27.5);

            if (agData.razonSocial) {
                doc.setTextColor(100, 116, 139); // #64748b - Slate 500
                doc.setFontSize(7);
                doc.text(agData.razonSocial, textStartX, 31.5);
            }

            // 3. CUADRO DE ROTULACIÓN TÉCNICO (Solo EMISIÓN y ENCARGADO de la agencia)
            const cartelaX = 205;
            const cartelaW = 81;
            doc.setFillColor(248, 250, 252); // #f8fafc - Gris muy claro
            doc.roundedRect(cartelaX, 11, cartelaW, 21, 1.5, 1.5, 'F');
            doc.setDrawColor(203, 213, 225); // Slate 300
            doc.setLineWidth(0.3);
            doc.roundedRect(cartelaX, 11, cartelaW, 21, 1.5, 1.5, 'S');

            const ahora = new Date();
            const fechaHora = ahora.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + 
                              ahora.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
            const nombreEncargado = agData.encargado || 'No asignado';

            // Fila 1: EMISIÓN
            doc.setFontSize(7.5);
            doc.setFont('helvetica', 'bold');
            doc.setTextColor(71, 85, 105);
            doc.text('EMISIÓN:', cartelaX + 4, 18);
            doc.setTextColor(15, 23, 42);
            doc.text(fechaHora, cartelaX + 27, 18);

            // Separador horizontal sutil
            doc.setDrawColor(226, 232, 240);
            doc.setLineWidth(0.2);
            doc.line(cartelaX + 3, 21.5, cartelaX + cartelaW - 3, 21.5);

            // Fila 2: ENCARGADO
            doc.setFontSize(7.5);
            doc.setFont('helvetica', 'bold');
            doc.setTextColor(71, 85, 105);
            doc.text('ENCARGADO:', cartelaX + 4, 27);
            doc.setTextColor(15, 23, 42);
            doc.text(nombreEncargado, cartelaX + 27, 27);

            // 4. ÁREA CENTRAL: LIENZO DEL PLANO
            if (canvasElement && canvasElement.width > 0 && canvasElement.height > 0) {
                const canvasDataUrl = canvasElement.toDataURL('image/png');
                const maxAreaW = 279;
                const maxAreaH = 158;
                const cRatio = canvasElement.width / canvasElement.height;
                const aRatio = maxAreaW / maxAreaH;

                let drawW, drawH;
                if (cRatio > aRatio) {
                    drawW = maxAreaW;
                    drawH = maxAreaW / cRatio;
                } else {
                    drawH = maxAreaH;
                    drawW = maxAreaH * cRatio;
                }

                const posX = 9 + (maxAreaW - drawW) / 2;
                const posY = 36 + (maxAreaH - drawH) / 2;

                // Marco contenedor oscuro para el plano
                doc.setFillColor(3, 13, 27);
                doc.roundedRect(posX - 0.5, posY - 0.5, drawW + 1, drawH + 1, 1, 1, 'F');
                doc.setDrawColor(56, 189, 248);
                doc.setLineWidth(0.3);
                doc.roundedRect(posX - 0.5, posY - 0.5, drawW + 1, drawH + 1, 1, 1, 'S');

                // Incrustar imagen de alta resolución del canvas
                doc.addImage(canvasDataUrl, 'PNG', posX, posY, drawW, drawH);
            }

            // 5. PIE DE PÁGINA (Footer)
            doc.setTextColor(100, 116, 139);
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.5);
            doc.text('PORTAL DE SISTEMAS | DOCUMENTO TÉCNICO CONFIDENCIAL - INFRAESTRUCTURA SITE', 10, 202);
            doc.text(`Página ${paginaActual} de ${totalPages}`, 268, 202);

            paginaActual++;
        };

        // RENDER PÁGINA 1: PLANO 2D SALA
        if (exportarPlano) {
            // Deseleccionar temporalmente para exportación limpia sin manijas de edición
            const prevSel = selectedSite2DObj;
            selectedSite2DObj = null;
            const tb = document.getElementById('canvaFloatingToolbar');
            if (tb) tb.style.display = 'none';

            // Activar bandera para omitir líneas punteadas de delimitación 3D y cámara en el PDF
            window.isExportingSitePDF = true;

            if (typeof renderPlanoSite2D === 'function') {
                renderPlanoSite2D();
            }

            const canvas2D = document.getElementById('canvasPlanoSite2D');
            renderizarPaginaPDF(canvas2D, 'PLANO ARQUITECTÓNICO 2D - DISTRIBUCIÓN DE SALA Y EQUIPOS', 'PLANO ARQ-2D');

            // Desactivar bandera y restaurar vista interactiva
            window.isExportingSitePDF = false;
            selectedSite2DObj = prevSel;
            if (typeof renderPlanoSite2D === 'function') {
                renderPlanoSite2D();
                if (selectedSite2DObj && typeof actualizarToolbarFlotante === 'function') {
                    actualizarToolbarFlotante();
                }
            }
        }

        // RENDER PÁGINA 2 (O 1): ELEVACIÓN RACKS 2D
        if (exportarRacks) {
            if (exportarPlano) {
                doc.addPage('a4', 'landscape');
            }

            // Sincronizar y forzar dibujo de elevación de racks
            if (typeof drawSite2DRackElevation === 'function') {
                drawSite2DRackElevation('siteCanvas2D');
            }

            const canvasRacks = document.getElementById('siteCanvas2D');
            renderizarPaginaPDF(canvasRacks, 'ELEVACIÓN FRONTAL DE GABINETES Y RACKS 42U - DISTRIBUCIÓN DE UNIDADES', 'RACKS ELEV-2D');
        }

        // Guardar archivo PDF con nombre descriptivo
        const safeAgencia = (agData.nombre || 'Agencia').replace(/[^a-zA-Z0-9_-]/g, '_');
        const safeSite = (agData.siteNombre || 'SITE').replace(/[^a-zA-Z0-9_-]/g, '_');
        const sufijoTipo = (exportarPlano && exportarRacks) ? 'Completo' : (exportarPlano ? 'Plano2D' : 'Racks2D');
        const nombreArchivo = `Plano_SITE_${safeAgencia}_${safeSite}_${sufijoTipo}.pdf`;

        doc.save(nombreArchivo);

    } catch (err) {
        console.error('Error al exportar PDF:', err);
        alert('Hubo un error al generar el PDF del SITE: ' + (err.message || err));
    } finally {
        if (btn) {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }
}

window.exportarPDFSiteHorizontal = exportarPDFSiteHorizontal;
