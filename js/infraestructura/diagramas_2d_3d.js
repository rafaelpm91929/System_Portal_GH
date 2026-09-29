if (typeof window.equiposInventarioMap === 'undefined') window.equiposInventarioMap = {};
let planoActualData = window.planoActualData || { id: 1, nombre_plano: 'Plano Principal', elementos_json: '[]' };
if (!planoActualData) {
    planoActualData = { id: 1, nombre_plano: 'Plano Principal', elementos_json: '[]' };
}
let elementosCanvas = [];
let elementoSeleccionado = null;
let elementosSeleccionados = []; // Multiselección de componentes
let draggingElement = null;
let resizingShape = null;
let draggingVertex = null;
let dragOffsetX = 0;
let dragOffsetY = 0;

let portapapelesElemento = null;
let isPanningCanvas = false;
let panHasMoved = false;
let isRightClickSelecting = false;
let rightSelectStartX = 0;
let rightSelectStartY = 0;

function isElementoSeleccionado(id) {
    if (elementoSeleccionado && elementoSeleccionado.id === id) return true;
    return elementosSeleccionados.some(el => el.id === id);
}

let esModoLectura = (typeof window.esModoLectura !== 'undefined') ? window.esModoLectura : true;
let modoPlanoActual = '2d';

    let categoriasVisibles = {
        nodo: true,
        pc: true,
        impresora: true,
        telefono: true,
        pantalla: true,
        ap: true,
        camara: true,
        site: true,
        arquitectura: true
    };

    function obtenerCategoriaDeElemento(el) {
        if (!el || !el.tipo) return 'arquitectura';
        if (['nodo', 'pc', 'impresora', 'telefono', 'pantalla', 'ap', 'camara', 'site'].includes(el.tipo)) {
            return el.tipo;
        }
        if (el.tipo === 'equipo_inv') return 'pc';
        if (el.tipo === 'cuadro_imagen') return 'pantalla';
        return 'arquitectura';
    }

    function alternarVisibilidadCategoria(cat, visible) {
        categoriasVisibles[cat] = visible;
        renderizarNodosCanvas();
        if (scene3D) renderizarEscena3D();
    }
    let scene3D = null;
    let camera3D = null;
    let renderer3D = null;
    let controls3D = null;
    let animFrameId3D = null;
    let is3DInitialized = false;
    let fondo3DOscuro = false;
    let floorMesh = null;

    function alternarFondo3D() {
        fondo3DOscuro = !fondo3DOscuro;
        aplicarFondo3D();
    }

    function aplicarFondo3D() {
        const btnIcon = document.getElementById('iconFondo3D');
        const btnLabel = document.getElementById('labelFondo3D');
        const btnFondo = document.getElementById('btnFondo3D');

        if (fondo3DOscuro) {
            if (scene3D) {
                scene3D.background = new THREE.Color(0x0f172a); // Slate-900 / Fondo Oscuro
                scene3D.fog = new THREE.FogExp2(0x0f172a, 0.0003);
                if (floorMesh && floorMesh.material) {
                    floorMesh.material.color.setHex(0x1e293b); // Suelo Slate-800
                }
            }
            if (btnIcon) btnIcon.className = 'bi bi-sun-fill text-warning me-1';
            if (btnLabel) btnLabel.textContent = 'Fondo Claro';
            if (btnFondo) btnFondo.title = 'Cambiar a Fondo Claro en 3D';
        } else {
            if (scene3D) {
                scene3D.background = new THREE.Color(0xf8fafc); // Slate-50 / Fondo Claro
                scene3D.fog = new THREE.FogExp2(0xf8fafc, 0.0003);
                if (floorMesh && floorMesh.material) {
                    floorMesh.material.color.setHex(0xffffff); // Suelo Blanco
                }
            }
            if (btnIcon) btnIcon.className = 'bi bi-moon-stars-fill text-info me-1';
            if (btnLabel) btnLabel.textContent = 'Fondo Oscuro';
            if (btnFondo) btnFondo.title = 'Cambiar a Fondo Oscuro en 3D';
        }
    }

    function obtenerCentroVista2D() {
        const outer = document.getElementById('canvasOuter');
        if (!outer) return { x: 1200, y: 900 };
        const scrollLeft = outer.scrollLeft;
        const scrollTop = outer.scrollTop;
        const width = outer.clientWidth || 1000;
        const height = outer.clientHeight || 650;

        const currentZoom = (typeof zoomLevel !== 'undefined' && zoomLevel > 0) ? zoomLevel : 1.0;
        const centerX = Math.round((scrollLeft + width / 2) / currentZoom);
        const centerY = Math.round((scrollTop + height / 2) / currentZoom);

        return {
            x: Math.max(40, Math.min(2360, centerX)),
            y: Math.max(40, Math.min(1760, centerY))
        };
    }

    function centrarVista2DEn(x, y) {
        const outer = document.getElementById('canvasOuter');
        if (!outer) return;
        const width = outer.clientWidth || 1000;
        const height = outer.clientHeight || 650;
        const currentZoom = (typeof zoomLevel !== 'undefined' && zoomLevel > 0) ? zoomLevel : 1.0;

        const targetLeft = (x * currentZoom) - (width / 2);
        const targetTop = (y * currentZoom) - (height / 2);

        outer.scrollLeft = Math.max(0, targetLeft);
        outer.scrollTop = Math.max(0, targetTop);
        if (typeof renderizarMinimapa === 'function') {
            renderizarMinimapa();
        }
    }

    function obtenerPosicionInsercionActual() {
        if (modoPlanoActual === '3d' && controls3D && controls3D.target) {
            return {
                x: Math.round(controls3D.target.x),
                y: Math.round(controls3D.target.z)
            };
        } else {
            const centro2D = obtenerCentroVista2D();
            return {
                x: centro2D.x + (Math.floor(Math.random() * 30) - 15),
                y: centro2D.y + (Math.floor(Math.random() * 30) - 15)
            };
        }
    }

    function alternarPantallaCompleta() {
        let container = null;
        if (modoPlanoActual === '3d') {
            container = document.getElementById('canvas3DContainer');
        } else {
            container = document.getElementById('colMainCanvas') || document.getElementById('seccionWorkspacePlano');
        }
        if (!container) container = document.documentElement;

        if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
            if (container.requestFullscreen) {
                container.requestFullscreen();
            } else if (container.webkitRequestFullscreen) {
                container.webkitRequestFullscreen();
            } else if (container.msRequestFullscreen) {
                container.msRequestFullscreen();
            }
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        }
    }

    function handleFullscreenChange() {
        const isFS = !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
        const btnIcon = document.getElementById('iconFullscreen');
        const btnLabel = document.getElementById('labelFullscreen');
        const iconOverlayFS = document.getElementById('iconOverlayFS');

        if (isFS) {
            if (btnIcon) btnIcon.className = 'bi bi-fullscreen-exit text-warning me-1';
            if (btnLabel) btnLabel.textContent = 'Salir Vista Completa';
            if (iconOverlayFS) iconOverlayFS.className = 'bi bi-fullscreen-exit text-warning';
        } else {
            if (btnIcon) btnIcon.className = 'bi bi-arrows-fullscreen me-1';
            if (btnLabel) btnLabel.textContent = '🖥️ Vista Completa';
            if (iconOverlayFS) iconOverlayFS.className = 'bi bi-arrows-fullscreen text-info';
        }

        setTimeout(() => {
            if (typeof onWindowResize3D === 'function') onWindowResize3D();
            renderizarMinimapa();
        }, 60);
    }

    document.addEventListener('fullscreenchange', handleFullscreenChange);
    document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
    document.addEventListener('msfullscreenchange', handleFullscreenChange);

    const bootstrapIconUnicodes = {
        'bi-ethernet': '\uF387',
        'bi-laptop': '\uF456',
        'bi-display': '\uF301',
        'bi-pc-display-horizontal': '\uF4D8',
        'bi-printer-fill': '\uF4FA',
        'bi-telephone-fill': '\uF5B0',
        'bi-wifi': '\uF61C',
        'bi-camera-fill': '\uF20E',
        'bi-hdd-rack-fill': '\uF40C',
        'bi-tv-fill': '\uF5DF',
        'bi-bounding-box-circles': '\uF1AD',
        'bi-diagram-3-fill': '\uF2F2',
        'bi-image-fill': '\uF426',
        'bi-display-fill': '\uF301'
    };

    async function exportarPlano2DPDF() {
        mostrarNotificacionToast('📄 Generando PDF con plano e información de nodos...');

        const nombrePlano = (planoActualData ? planoActualData.nombre_plano : 'Plano_Agencia_2D').replace(/[^a-zA-Z0-9_-]/g, '_');
        const fechaActual = new Date().toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });

        try {
            if (window.html2canvas && window.jspdf) {
                const viewportEl = document.getElementById('canvasViewport');
                if (viewportEl) {
                    const origTransform = viewportEl.style.transform;
                    viewportEl.style.transform = 'scale(1)';

                    const canvas = await html2canvas(viewportEl, {
                        scale: 2,
                        useCORS: true,
                        allowTaint: true,
                        backgroundColor: '#ffffff',
                        logging: false
                    });

                    viewportEl.style.transform = origTransform;

                    const imgData = canvas.toDataURL('image/jpeg', 0.95);
                    const { jsPDF } = window.jspdf;
                    const pdf = new jsPDF({
                        orientation: 'landscape',
                        unit: 'mm',
                        format: 'a4'
                    });

                    const pdfW = pdf.internal.pageSize.getWidth(); // 297 mm
                    const pdfH = pdf.internal.pageSize.getHeight(); // 210 mm

                    // === PÁGINA 1: ENCABEZADO Y DIAGRAMA VISUAL 2D ===
                    pdf.setFillColor(13, 30, 54);
                    pdf.rect(0, 0, pdfW, 16, 'F');

                    pdf.setTextColor(255, 255, 255);
                    pdf.setFontSize(12);
                    pdf.setFont('helvetica', 'bold');
                    pdf.text(`📐 Diagrama 2D: ${planoActualData ? planoActualData.nombre_plano : 'Plano Agencia'}`, 10, 11);

                    pdf.setFontSize(9);
                    pdf.setFont('helvetica', 'normal');
                    pdf.text(`Generado: ${fechaActual} | Total Componentes: ${elementosCanvas.length}`, pdfW - 10, 11, { align: 'right' });

                    // Ajustar imagen del plano al formato A4
                    const margin = 8;
                    const availableH = pdfH - 24;
                    const imgAspect = canvas.width / canvas.height;
                    let drawW = pdfW - (margin * 2);
                    let drawH = drawW / imgAspect;

                    if (drawH > availableH) {
                        drawH = availableH;
                        drawW = drawH * imgAspect;
                    }

                    const posX = (pdfW - drawW) / 2;
                    pdf.addImage(imgData, 'JPEG', posX, 18, drawW, drawH);

                    // === PÁGINA 2: TABLA DETALLADA DE INFORMACIÓN Y FICHAS TÉCNICAS DE NODOS ===
                    pdf.addPage('landscape', 'a4');

                    pdf.setFillColor(13, 30, 54);
                    pdf.rect(0, 0, pdfW, 16, 'F');

                    pdf.setTextColor(255, 255, 255);
                    pdf.setFontSize(12);
                    pdf.setFont('helvetica', 'bold');
                    pdf.text(`📋 Inventario y Fichas Técnicas de Nodos / Equipos en el Plano`, 10, 11);

                    pdf.setFontSize(9);
                    pdf.setFont('helvetica', 'normal');
                    pdf.text(`Plano: ${planoActualData ? planoActualData.nombre_plano : 'Agencia'}`, pdfW - 10, 11, { align: 'right' });

                    // Encabezados de Tabla
                    let startY = 25;
                    pdf.setFillColor(241, 245, 249);
                    pdf.rect(10, startY, pdfW - 20, 9, 'F');
                    pdf.setDrawColor(203, 213, 225);
                    pdf.rect(10, startY, pdfW - 20, 9, 'S');

                    pdf.setTextColor(30, 41, 59);
                    pdf.setFontSize(8.5);
                    pdf.setFont('helvetica', 'bold');

                    pdf.text('#', 13, startY + 6);
                    pdf.text('Nombre / Número de Componente', 22, startY + 6);
                    pdf.text('Tipo / Categoría', 95, startY + 6);
                    pdf.text('Dirección IP', 145, startY + 6);
                    pdf.text('Departamento', 190, startY + 6);
                    pdf.text('Ubicación (X, Y)', 230, startY + 6);
                    pdf.text('Información / Cobertura', 262, startY + 6);

                    startY += 9;
                    pdf.setFont('helvetica', 'normal');
                    pdf.setFontSize(8);

                    elementosCanvas.forEach((el, index) => {
                        if (startY > pdfH - 16) {
                            pdf.addPage('landscape', 'a4');
                            pdf.setFillColor(13, 30, 54);
                            pdf.rect(0, 0, pdfW, 16, 'F');
                            pdf.setTextColor(255, 255, 255);
                            pdf.setFontSize(11);
                            pdf.setFont('helvetica', 'bold');
                            pdf.text(`📋 Detalle de Nodos (Continuación)`, 10, 11);

                            startY = 25;
                            pdf.setFillColor(241, 245, 249);
                            pdf.rect(10, startY, pdfW - 20, 9, 'F');
                            pdf.setDrawColor(203, 213, 225);
                            pdf.rect(10, startY, pdfW - 20, 9, 'S');

                            pdf.setTextColor(30, 41, 59);
                            pdf.setFontSize(8.5);
                            pdf.setFont('helvetica', 'bold');
                            pdf.text('#', 13, startY + 6);
                            pdf.text('Nombre / Número de Componente', 22, startY + 6);
                            pdf.text('Tipo / Categoría', 95, startY + 6);
                            pdf.text('Dirección IP', 145, startY + 6);
                            pdf.text('Departamento', 190, startY + 6);
                            pdf.text('Ubicación (X, Y)', 230, startY + 6);
                            pdf.text('Información / Cobertura', 262, startY + 6);

                            startY += 9;
                            pdf.setFont('helvetica', 'normal');
                            pdf.setFontSize(8);
                        }

                        // Filas alternadas
                        if (index % 2 === 0) {
                            pdf.setFillColor(248, 250, 252);
                            pdf.rect(10, startY, pdfW - 20, 7.5, 'F');
                        }
                        pdf.setDrawColor(226, 232, 240);
                        pdf.rect(10, startY, pdfW - 20, 7.5, 'S');

                        pdf.setTextColor(15, 23, 42);
                        const nombreComp = el.label || (el.tipo ? el.tipo.toUpperCase() : `Componente #${index + 1}`);
                        const tipoComp = (el.tipo || 'Elemento').toUpperCase();
                        const ipComp = el.ip || 'No asignada';
                        const deptComp = el.dept || 'General';
                        const coordsComp = `(${Math.round(el.x || 0)}, ${Math.round(el.y || 0)})`;

                        let infoComp = 'Estándar';
                        if (el.tipo === 'ap') infoComp = `Radio Wi-Fi: ${el.radio || 120}px`;
                        else if (el.tipo === 'camara') infoComp = `Ángulo: ${el.angulo || 0}°`;
                        else if (el.vertices) infoComp = `Habitación (${el.vertices.length} lados)`;
                        else if (el.subtipo) infoComp = `Modelo: ${el.subtipo}`;

                        pdf.text(`${index + 1}`, 13, startY + 5);
                        pdf.text(String(nombreComp).substring(0, 38), 22, startY + 5);
                        pdf.text(String(tipoComp).substring(0, 24), 95, startY + 5);
                        pdf.text(String(ipComp), 145, startY + 5);
                        pdf.text(String(deptComp).substring(0, 20), 190, startY + 5);
                        pdf.text(coordsComp, 230, startY + 5);
                        pdf.text(String(infoComp), 262, startY + 5);

                        startY += 7.5;
                    });

                    pdf.save(`${nombrePlano}_2D.pdf`);
                    mostrarNotificacionToast('✅ ¡PDF generado con plano y tabla de información de nodos!');
                    return;
                }
            }
        } catch (err) {
            console.warn('Usando generador alternativo de PDF por canvas:', err);
        }

        exportarPDFCanvasFallback();
    }

    function exportarPDFCanvasFallback() {
        const totalW = 2400;
        const totalH = 1800;

        const printCanvas = document.createElement('canvas');
        printCanvas.width = totalW;
        printCanvas.height = totalH;
        const ctx = printCanvas.getContext('2d');

        // Fondo blanco limpio
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, totalW, totalH);

        // Cuadrícula suave
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.05)';
        ctx.lineWidth = 1;
        for (let x = 0; x < totalW; x += 50) {
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, totalH); ctx.stroke();
        }
        for (let y = 0; y < totalH; y += 50) {
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(totalW, y); ctx.stroke();
        }

        const bgImg = document.getElementById('imgFondoPlano');
        if (bgImg && bgImg.src && bgImg.style.display !== 'none' && bgImg.complete && bgImg.naturalWidth > 0) {
            try {
                ctx.drawImage(bgImg, 0, 0, totalW, totalH);
            } catch (e) {}
        }

        // Dibujar Habitaciones y Polígonos
        elementosCanvas.forEach(el => {
            const cat = obtenerCategoriaDeElemento(el);
            if (categoriasVisibles[cat] === false) return;

            if (el.vertices && el.vertices.length > 0) {
                const pts = el.vertices;
                ctx.save();
                ctx.translate(el.x, el.y);
                ctx.rotate((el.angulo || 0) * Math.PI / 180);

                ctx.beginPath();
                pts.forEach((pt, idx) => {
                    if (idx === 0) ctx.moveTo(pt.x, pt.y);
                    else ctx.lineTo(pt.x, pt.y);
                });
                ctx.closePath();
                ctx.fillStyle = el.color || 'rgba(37, 99, 235, 0.18)';
                ctx.fill();

                pts.forEach((pt, idx) => {
                    const nextPt = pts[(idx + 1) % pts.length];
                    const segColor = (el.coloresParedes && el.coloresParedes[idx]) ? el.coloresParedes[idx] : (el.borderColor || '#3b82f6');
                    ctx.beginPath();
                    ctx.moveTo(pt.x, pt.y);
                    ctx.lineTo(nextPt.x, nextPt.y);
                    ctx.strokeStyle = segColor;
                    ctx.lineWidth = el.borderWidth || 3;
                    ctx.stroke();
                });

                if (el.label) {
                    ctx.fillStyle = '#0f172a';
                    ctx.font = 'bold 16px sans-serif';
                    ctx.textAlign = 'center';
                    let minX = Math.min(...pts.map(p => p.x));
                    let maxX = Math.max(...pts.map(p => p.x));
                    let minY = Math.min(...pts.map(p => p.y));
                    let maxY = Math.max(...pts.map(p => p.y));
                    ctx.fillText(el.label, minX + (maxX - minX) / 2, minY + (maxY - minY) / 2);
                }
                ctx.restore();
            }
        });

        // Dibujar Dispositivos con Iconografía Bootstrap Font integrada
        elementosCanvas.forEach(el => {
            const cat = obtenerCategoriaDeElemento(el);
            if (categoriasVisibles[cat] === false) return;
            if (el.vertices && el.vertices.length > 0) return;

            const w = el.width || 65;
            const h = el.height || 65;

            ctx.save();
            ctx.translate(el.x, el.y);
            ctx.rotate((el.angulo || 0) * Math.PI / 180);

            // Círculo Radio Cobertura AP Wi-Fi
            if (el.tipo === 'ap' && el.radio) {
                ctx.beginPath();
                ctx.arc(0, 0, el.radio, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(14, 165, 233, 0.08)';
                ctx.fill();
                ctx.strokeStyle = '#0ea5e9';
                ctx.setLineDash([6, 6]);
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.setLineDash([]);
            }

            // Pin / Fondo
            const pinRadius = Math.min(w, h) / 2;
            ctx.beginPath();
            ctx.arc(0, 0, pinRadius, 0, Math.PI * 2);
            ctx.fillStyle = el.color || '#3b82f6';
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 3;
            ctx.stroke();

            // Dibujar Glifo de Iconografía dentro del Pin
            const iconClass = el.icon || 'bi-ethernet';
            const glyph = bootstrapIconUnicodes[iconClass] || '\uF387';
            ctx.fillStyle = '#ffffff';
            ctx.font = `${Math.round(pinRadius * 0.9)}px "bootstrap-icons", bootstrap-icons, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(glyph, 0, 1);

            // Etiqueta identificadora + IP
            ctx.fillStyle = '#0f172a';
            ctx.font = 'bold 13px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'alphabetic';
            const labelText = `${el.label || el.tipo.toUpperCase()}${el.ip ? ' (' + el.ip + ')' : ''}`;
            ctx.fillText(labelText, 0, pinRadius + 16);

            ctx.restore();
        });

        // Descarga de archivo en PNG / PDF
        const link = document.createElement('a');
        const nombrePlano = (planoActualData ? planoActualData.nombre_plano : 'Plano_Agencia_2D').replace(/[^a-zA-Z0-9_-]/g, '_');
        link.download = `${nombrePlano}_2D.png`;
        link.href = printCanvas.toDataURL('image/png');
        link.click();
        mostrarNotificacionToast('✅ ¡Diagrama exportado exitosamente!');
    }

    const keysPressed3D = {};
    let modoRecorridoPrimeraPersona = false;

    document.addEventListener('keydown', (e) => {
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        if (['input', 'textarea', 'select'].includes(activeTag)) {
            return;
        }

        if (e.key === 'F11') {
            e.preventDefault();
            alternarPantallaCompleta();
            return;
        }

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
            e.preventDefault();
            imprimirPlano2DPDF();
            return;
        }

        if (modoPlanoActual === '3d') {
            const k = e.key.toLowerCase();
            if (['arrowup', 'arrowdown', 'arrowleft', 'arrowright', 'w', 'a', 's', 'd', 'q', 'e', ' ', 'pageup', 'pagedown', 'shift'].includes(k)) {
                keysPressed3D[k] = true;
                if (['arrowup', 'arrowdown', 'arrowleft', 'arrowright', 'pageup', 'pagedown', ' '].includes(k)) {
                    e.preventDefault();
                }
            }
        }
    });

    document.addEventListener('keyup', (e) => {
        if (modoPlanoActual === '3d') {
            const k = e.key.toLowerCase();
            keysPressed3D[k] = false;
        }
    });

    function alternarModoRecorrido3D() {
        if (!camera3D) return;
        modoRecorridoPrimeraPersona = !modoRecorridoPrimeraPersona;
        const btnLabel = document.getElementById('labelCaminar3D');
        const btnIcon = document.getElementById('iconCaminar3D');
        const iconOverlayWalk = document.getElementById('iconOverlayWalk');

        if (modoRecorridoPrimeraPersona) {
            // Modo Primera Persona / Giro de Cabeza con Ratón
            const centro = controls3D ? controls3D.target.clone() : new THREE.Vector3(1200, 0, 900);
            camera3D.position.set(centro.x, 35, centro.z + 20);
            if (controls3D) {
                controls3D.target.set(centro.x, 35, centro.z - 80);
                controls3D.enablePan = false; // Desactivar traslado de posición por ratón (El ratón SOLO gira la cabeza)
                controls3D.enableZoom = true;
                controls3D.minDistance = 1;
                controls3D.maxDistance = 1500;
                controls3D.minPolarAngle = 0.05;
                controls3D.maxPolarAngle = Math.PI - 0.05;
                controls3D.rotateSpeed = 0.8;
                controls3D.update();
            }
            if (btnLabel) btnLabel.textContent = 'Vista Aérea 🛰️';
            if (btnIcon) btnIcon.className = 'bi bi-geo-alt-fill text-info me-1';
            if (iconOverlayWalk) iconOverlayWalk.className = 'bi bi-geo-alt-fill text-info';

            mostrarNotificacionToast('👀 Modo Cabeza / Primera Persona: Arrastra el ratón para mirar a donde ver y Usa Flechas/WASD para caminar');
        } else {
            // Modo Vista Aérea Órbita
            const centro = controls3D ? controls3D.target.clone() : new THREE.Vector3(1200, 0, 900);
            camera3D.position.set(centro.x, 1100, centro.z + 700);
            if (controls3D) {
                controls3D.target.set(centro.x, 0, centro.z);
                controls3D.enablePan = true;
                controls3D.enableZoom = true;
                controls3D.minDistance = 30;
                controls3D.maxDistance = 5000;
                controls3D.minPolarAngle = 0;
                controls3D.maxPolarAngle = Math.PI / 2 - 0.01;
                controls3D.rotateSpeed = 1.0;
                controls3D.update();
            }
            if (btnLabel) btnLabel.textContent = 'Caminar Adentro 🚶‍♂️';
            if (btnIcon) btnIcon.className = 'bi bi-person-walking text-warning me-1';
            if (iconOverlayWalk) iconOverlayWalk.className = 'bi bi-person-walking text-warning';
        }
    }

    function toggleSidebarNavegacion() {
        const sidebar = document.getElementById('mainSidebarWrapper');
        const content = document.getElementById('mainContentWrapper');
        const btnTab = document.getElementById('btnShowSidebarTab');
        const iconBtn = document.getElementById('iconToggleSidebar');

        if (!sidebar || !content) return;

        const isHidden = (sidebar.style.display === 'none');

        if (isHidden) {
            sidebar.style.display = 'block';
            content.classList.remove('col-12');
            content.classList.add('col-md-9', 'col-lg-10');
            if (btnTab) btnTab.style.display = 'none';
            if (iconBtn) iconBtn.className = 'bi bi-chevron-left fs-6 text-white';
            localStorage.setItem('sidebar_collapsed', '0');
        } else {
            sidebar.style.display = 'none';
            content.classList.remove('col-md-9', 'col-lg-10');
            content.classList.add('col-12');
            if (btnTab) btnTab.style.display = 'block';
            if (iconBtn) iconBtn.className = 'bi bi-chevron-right fs-6 text-white';
            localStorage.setItem('sidebar_collapsed', '1');
        }

        if (typeof resizeCanvas === 'function') {
            setTimeout(resizeCanvas, 100);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (localStorage.getItem('sidebar_collapsed') === '1') {
            toggleSidebarNavegacion();
        }
    });

    function alternarSubVistaConfig(subModo) {
        if (modoNavegacionActual === 'configurar') {
            alternarModoPlano(subModo);
        } else {
            activarModoNavegacion(subModo);
        }
    }

    function abrirDiagramaModo(planoId, modo) {
        if (typeof planoActualData !== 'undefined' && planoActualData && planoActualData.id == planoId) {
            activarModoNavegacion(modo);
        } else {
            location.href = 'infraestructura.php?sec=diagramas&plano_id=' + planoId + '&modo=' + modo;
        }
    }

    function activarModoNavegacion(modo) {
        modoNavegacionActual = modo;
        esModoLectura = (modo !== 'configurar');
        const pantallaInicio = document.getElementById('pantallaInicioDiagramas');
        const seccionWorkspace = document.getElementById('seccionWorkspacePlano');
        const colSidebar = document.getElementById('colSidebarPalette');
        const colMain = document.getElementById('colMainCanvas');
        const btn2D = document.getElementById('btnVista2D');
        const btn3D = document.getElementById('btnVista3D');
        const btnConfig = document.getElementById('btnModoConfigurar');
        const grupoSwitch = document.getElementById('grupoSwitchVista');
        const btnPrintPDF = document.getElementById('btnImprimirPDF2D');

        const toolbarEditar = document.getElementById('toolbarEditarPlano');
        const btnNuevoPlano = document.getElementById('btnNuevoPlanoModal');
        const barraEditing = document.getElementById('barraEditingHeader');

        if (modo === 'inicio') {
            esModoLectura = true;
            if (pantallaInicio) pantallaInicio.style.display = 'block';
            if (seccionWorkspace) seccionWorkspace.style.display = 'none';
            return;
        }

        if (pantallaInicio) pantallaInicio.style.display = 'none';
        if (seccionWorkspace) seccionWorkspace.style.display = 'block';

        if (modo === 'configurar') {
            esModoLectura = false;
            if (grupoSwitch) grupoSwitch.style.setProperty('display', 'inline-flex', 'important');
            if (btnPrintPDF) btnPrintPDF.style.setProperty('display', 'none', 'important');
            if (toolbarEditar) toolbarEditar.style.setProperty('display', 'flex', 'important');
            if (btnNuevoPlano) btnNuevoPlano.style.setProperty('display', 'inline-block', 'important');
            if (barraEditing) barraEditing.style.setProperty('display', 'flex', 'important');

            if (colSidebar) {
                colSidebar.style.display = 'block';
                colSidebar.classList.remove('d-none');
            }
            if (colMain) {
                colMain.className = 'col-lg-9';
            }
            if (btnConfig) {
                btnConfig.className = 'btn btn-warning active fw-bold text-dark rounded-end-3';
            }
            if (btn2D) {
                btn2D.className = 'btn btn-primary active text-white fw-bold rounded-start-3';
            }
            if (btn3D) {
                btn3D.className = 'btn btn-outline-info text-white fw-bold';
            }
            alternarModoPlano('2d');
        } else if (modo === '3d') {
            esModoLectura = true;
            if (grupoSwitch) grupoSwitch.style.setProperty('display', 'none', 'important');
            if (btnPrintPDF) btnPrintPDF.style.setProperty('display', 'none', 'important');
            if (toolbarEditar) toolbarEditar.style.setProperty('display', 'none', 'important');
            if (btnNuevoPlano) btnNuevoPlano.style.setProperty('display', 'none', 'important');
            if (barraEditing) barraEditing.style.setProperty('display', 'none', 'important');

            if (colSidebar) {
                colSidebar.style.display = 'none';
            }
            if (colMain) {
                colMain.className = 'col-lg-12';
            }
            if (btnConfig) {
                btnConfig.className = 'btn btn-outline-warning text-white fw-bold rounded-end-3';
            }
            if (btn2D) {
                btn2D.className = 'btn btn-outline-secondary text-white fw-bold rounded-start-3';
            }
            if (btn3D) {
                btn3D.className = 'btn btn-info active fw-bold text-dark';
            }
            alternarModoPlano('3d');
        } else { // 2d viewer
            esModoLectura = true;
            if (grupoSwitch) grupoSwitch.style.setProperty('display', 'none', 'important');
            if (btnPrintPDF) btnPrintPDF.style.setProperty('display', 'inline-block', 'important');
            if (toolbarEditar) toolbarEditar.style.setProperty('display', 'none', 'important');
            if (btnNuevoPlano) btnNuevoPlano.style.setProperty('display', 'none', 'important');
            if (barraEditing) barraEditing.style.setProperty('display', 'none', 'important');

            if (colSidebar) {
                colSidebar.style.display = 'none';
            }
            if (colMain) {
                colMain.className = 'col-lg-12';
            }
            alternarModoPlano('2d');
        }

        if (esModoLectura) {
            const panelProp = document.getElementById('panelPropiedades');
            if (panelProp) panelProp.classList.add('d-none');
            elementoSeleccionado = null;
        }
        renderizarNodosCanvas();
    }

    function alternarModoPlano(modo) {
        const btn2D = document.getElementById('btnVista2D');
        const btn3D = document.getElementById('btnVista3D');
        const btnFondo = document.getElementById('btnFondo3D');
        const btnFS = document.getElementById('btnFullscreen');
        const btnPrintPDF = document.getElementById('btnImprimirPDF2D');
        const btnCaminar = document.getElementById('btnCaminar3D');
        const viewport2D = document.getElementById('canvasViewport');
        const container3D = document.getElementById('canvas3DContainer');
        const minimap = document.getElementById('minimapWrapper');

        if (modo === '3d') {
            const centro2D = obtenerCentroVista2D();
            modoPlanoActual = '3d';

            if (btn2D) { btn2D.classList.remove('btn-primary', 'active'); btn2D.classList.add('btn-outline-secondary'); }
            if (btn3D) { btn3D.classList.remove('btn-outline-info'); btn3D.classList.add('btn-info', 'active'); }
            if (btnFondo) btnFondo.style.display = 'inline-block';
            if (btnFS) btnFS.style.display = 'inline-block';
            if (btnCaminar) btnCaminar.style.display = 'inline-block';
            if (btnPrintPDF) btnPrintPDF.style.display = 'none';
            
            if (viewport2D) viewport2D.style.display = 'none';
            if (minimap) minimap.style.display = 'none';
            if (container3D) container3D.style.display = 'block';

            if (!is3DInitialized) {
                inicializarEscena3D();
            } else {
                renderizarEscena3D();
            }

            if (controls3D && camera3D) {
                const oldTarget = controls3D.target.clone();
                controls3D.target.set(centro2D.x, 0, centro2D.y);
                camera3D.position.add(controls3D.target.clone().sub(oldTarget));
                controls3D.update();
            }
        } else {
            let targetX = 1200, targetZ = 900;
            if (controls3D && controls3D.target) {
                targetX = controls3D.target.x;
                targetZ = controls3D.target.z;
            }

            modoPlanoActual = '2d';
            if (btn3D) { btn3D.classList.remove('btn-info', 'active'); btn3D.classList.add('btn-outline-info'); }
            if (btn2D) { btn2D.classList.remove('btn-outline-secondary'); btn2D.classList.add('btn-primary', 'active'); }
            if (btnFondo) btnFondo.style.display = 'none';
            if (btnFS) btnFS.style.display = 'inline-block';
            if (btnCaminar) btnCaminar.style.display = 'none';
            if (btnPrintPDF) btnPrintPDF.style.display = (modoNavegacionActual === 'configurar') ? 'none' : 'inline-block';
            
            if (container3D) container3D.style.display = 'none';
            if (viewport2D) viewport2D.style.display = 'block';
            if (minimap) minimap.style.display = 'block';

            setTimeout(() => {
                centrarVista2DEn(targetX, targetZ);
            }, 30);
        }
    }

    function inicializarEscena3D() {
        const container = document.getElementById('canvas3DContainer');
        if (!container) return;

        const width = container.clientWidth || 1000;
        const height = container.clientHeight || 650;

        // 1. Escena & Fondo (Claro u Oscuro según preferencia)
        scene3D = new THREE.Scene();
        aplicarFondo3D();

        const centroInicial = obtenerCentroVista2D();

        // 2. Cámara Perspectiva
        camera3D = new THREE.PerspectiveCamera(45, width / height, 1, 10000);
        camera3D.position.set(centroInicial.x, 1100, centroInicial.y + 700);

        // 3. Renderer WebGL
        renderer3D = new THREE.WebGLRenderer({ antialias: true });
        renderer3D.setSize(width, height);
        renderer3D.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer3D.shadowMap.enabled = true;
        renderer3D.shadowMap.type = THREE.PCFSoftShadowMap;

        const existingCanvases = container.querySelectorAll('canvas');
        existingCanvases.forEach(c => c.remove());
        container.insertBefore(renderer3D.domElement, container.firstChild);

        // 4. Orbit Controls (Rotación 360°, Zoom, Inclinación, Paneo Libre en 3D)
        if (typeof THREE.OrbitControls !== 'undefined') {
            controls3D = new THREE.OrbitControls(camera3D, renderer3D.domElement);
        } else if (typeof OrbitControls !== 'undefined') {
            controls3D = new OrbitControls(camera3D, renderer3D.domElement);
        }
        if (controls3D) {
            controls3D.target.set(centroInicial.x, 0, centroInicial.y);
            controls3D.enableDamping = true;
            controls3D.dampingFactor = 0.05;
            controls3D.screenSpacePanning = false; // Paneo paralelo al plano X-Z del suelo
            controls3D.enablePan = true;
            controls3D.panSpeed = 1.2;
            controls3D.enableZoom = true;
            controls3D.zoomSpeed = 1.2;
            controls3D.minDistance = 30;
            controls3D.maxDistance = 5000;
            controls3D.maxPolarAngle = Math.PI / 2 - 0.01;

            if (THREE.MOUSE) {
                controls3D.mouseButtons = {
                    LEFT: THREE.MOUSE.ROTATE,
                    MIDDLE: THREE.MOUSE.DOLLY,
                    RIGHT: THREE.MOUSE.PAN
                };
            }
            controls3D.update();
        }

        // Selección 3D directa con Raycaster en el canvas WebGL (Clic izquierdo = seleccionar, Clic derecho = abrir Ficha Técnica)
        let pointerDownPos3D = { x: 0, y: 0 };
        renderer3D.domElement.addEventListener('pointerdown', (e) => {
            pointerDownPos3D = { x: e.clientX, y: e.clientY };
        });

        function obtenerElementoDesdeRaycast(intersects) {
            if (!intersects || !intersects.length) return null;
            const TIPOS_EQUIPO = ['camara', 'ap', 'pc', 'nodo', 'site', 'impresora', 'telefono', 'pantalla'];

            // Priority 1: Equipments (Cameras, APs, PCs, SITE, Printers, etc.)
            for (let hit of intersects) {
                let curr = hit.object;
                while (curr) {
                    if (curr.userData && curr.userData.isDynamicElement && curr.userData.elementId) {
                        const el = elementosCanvas.find(item => item.id === curr.userData.elementId);
                        if (el && TIPOS_EQUIPO.includes(el.tipo)) {
                            return el;
                        }
                    }
                    curr = curr.parent;
                }
            }

            // Priority 2: Other structural elements (Rooms, Walls, Polygons)
            for (let hit of intersects) {
                let curr = hit.object;
                while (curr) {
                    if (curr.userData && curr.userData.isDynamicElement && curr.userData.elementId) {
                        const el = elementosCanvas.find(item => item.id === curr.userData.elementId);
                        if (el) {
                            return el;
                        }
                    }
                    curr = curr.parent;
                }
            }

            return null;
        }

        renderer3D.domElement.addEventListener('pointerup', (e) => {
            const dist = Math.hypot(e.clientX - pointerDownPos3D.x, e.clientY - pointerDownPos3D.y);
            if (dist > 15) return; // Permite micro-movimientos durante el clic

            const rect = renderer3D.domElement.getBoundingClientRect();
            const mouse = new THREE.Vector2(
                ((e.clientX - rect.left) / rect.width) * 2 - 1,
                -((e.clientY - rect.top) / rect.height) * 2 + 1
            );

            const raycaster = new THREE.Raycaster();
            raycaster.setFromCamera(mouse, camera3D);

            const intersects = raycaster.intersectObjects(scene3D.children, true);
            const el = obtenerElementoDesdeRaycast(intersects);

            if (el) {
                seleccionarElemento(el);
                if (e.button === 2) {
                    mostrarFichaTecnica3DFlotante(el, e);
                } else if (e.button === 0) {
                    cerrarFichaTecnica3D();
                }
            } else if (e.button === 0) {
                cerrarFichaTecnica3D();
            }
        });

        // Evento Clic Derecho para abrir Ficha Técnica del elemento en vista 3D
        renderer3D.domElement.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            e.stopPropagation();

            const dist = Math.hypot(e.clientX - pointerDownPos3D.x, e.clientY - pointerDownPos3D.y);
            if (dist > 15) return; // Permite micro-movimientos de hasta 15px durante el clic derecho

            const rect = renderer3D.domElement.getBoundingClientRect();
            const mouse = new THREE.Vector2(
                ((e.clientX - rect.left) / rect.width) * 2 - 1,
                -((e.clientY - rect.top) / rect.height) * 2 + 1
            );

            const raycaster = new THREE.Raycaster();
            raycaster.setFromCamera(mouse, camera3D);

            const intersects = raycaster.intersectObjects(scene3D.children, true);
            const el = obtenerElementoDesdeRaycast(intersects);

            if (el) {
                seleccionarElemento(el);
                mostrarFichaTecnica3DFlotante(el, e);
            } else {
                cerrarFichaTecnica3D();
            }
        });

        // 5. Iluminación (Luz Ambiental + Luz Sol Direccional con Sombras)
        const ambientLight = new THREE.AmbientLight(0xffffff, 0.75);
        scene3D.add(ambientLight);

        const dirLight = new THREE.DirectionalLight(0xffffff, 0.65);
        dirLight.position.set(1500, 2000, 1000);
        dirLight.castShadow = true;
        dirLight.shadow.mapSize.width = 2048;
        dirLight.shadow.mapSize.height = 2048;
        dirLight.shadow.camera.near = 100;
        dirLight.shadow.camera.far = 5000;
        const d = 1800;
        dirLight.shadow.camera.left = -d;
        dirLight.shadow.camera.right = d;
        dirLight.shadow.camera.top = d;
        dirLight.shadow.camera.bottom = -d;
        scene3D.add(dirLight);

        const hemiLight = new THREE.HemisphereLight(0xffffff, 0xcbdfbd, 0.35);
        scene3D.add(hemiLight);

        // 6. Suelo Principal Arquitectónico (2400 x 1800 px)
        const floorGeo = new THREE.PlaneGeometry(2400, 1800);
        const floorMat = new THREE.MeshStandardMaterial({ 
            color: 0xffffff, 
            roughness: 0.9, 
            metalness: 0.05,
            side: THREE.DoubleSide
        });
        floorMesh = new THREE.Mesh(floorGeo, floorMat);
        floorMesh.rotation.x = -Math.PI / 2;
        floorMesh.position.set(1200, 0, 900);
        floorMesh.receiveShadow = true;
        scene3D.add(floorMesh);
        aplicarFondo3D();
        scene3D.add(floorMesh);

        // Cuadrícula Blueprint sutil sobre el suelo
        const gridHelper = new THREE.GridHelper(2400, 48, 0x94a3b8, 0xe2e8f0);
        gridHelper.position.set(1200, 0.5, 900);
        scene3D.add(gridHelper);

        // Reajustar tamaño al cambiar tamaño de la ventana
        window.addEventListener('resize', onWindowResize3D);

        is3DInitialized = true;
        renderizarEscena3D();
        animarEscena3D();
    }

    function onWindowResize3D() {
        if (!renderer3D || !camera3D) return;
        const container = document.getElementById('canvas3DContainer');
        if (!container || container.style.display === 'none') return;
        const w = container.clientWidth || 1000;
        const h = container.clientHeight || 650;
        camera3D.aspect = w / h;
        camera3D.updateProjectionMatrix();
        renderer3D.setSize(w, h);
    }

    function animarEscena3D() {
        if (animFrameId3D) {
            cancelAnimationFrame(animFrameId3D);
            animFrameId3D = null;
        }
        animFrameId3D = requestAnimationFrame(animarEscena3D);

        if (modoPlanoActual === '3d' && camera3D) {
            const speed = (keysPressed3D['shift'] || keysPressed3D['shiftleft'] || keysPressed3D['shiftright']) ? 12 : 5;
            const dir = new THREE.Vector3();
            camera3D.getWorldDirection(dir);
            dir.y = 0;
            dir.normalize();

            const sideDir = new THREE.Vector3();
            sideDir.crossVectors(camera3D.up, dir).normalize();

            let moveX = 0, moveZ = 0, moveY = 0;

            if (keysPressed3D['arrowup'] || keysPressed3D['w']) {
                moveX += dir.x * speed;
                moveZ += dir.z * speed;
            }
            if (keysPressed3D['arrowdown'] || keysPressed3D['s']) {
                moveX -= dir.x * speed;
                moveZ -= dir.z * speed;
            }
            if (keysPressed3D['arrowleft'] || keysPressed3D['a']) {
                moveX += sideDir.x * speed;
                moveZ += sideDir.z * speed;
            }
            if (keysPressed3D['arrowright'] || keysPressed3D['d']) {
                moveX -= sideDir.x * speed;
                moveZ -= sideDir.z * speed;
            }
            if (keysPressed3D['pageup'] || keysPressed3D['e'] || keysPressed3D[' ']) {
                moveY += speed * 0.8;
            }
            if (keysPressed3D['pagedown'] || keysPressed3D['q']) {
                moveY -= speed * 0.8;
            }

            if (moveX !== 0 || moveZ !== 0 || moveY !== 0) {
                camera3D.position.x += moveX;
                camera3D.position.z += moveZ;
                camera3D.position.y = Math.max(5, camera3D.position.y + moveY);
                if (controls3D) {
                    controls3D.target.x += moveX;
                    controls3D.target.z += moveZ;
                    controls3D.target.y += moveY;
                }
            }
        }

        if (controls3D) controls3D.update();
        if (renderer3D && scene3D && camera3D) {
            renderer3D.render(scene3D, camera3D);
        }
    }

    function construirMurosConHuecos(p1, p2, wallHeight, wallThickness, wallColor, parentGroup, puertasYVentanas) {
        const dx = p2.x - p1.x;
        const dz = p2.z - p1.z;
        const L = Math.hypot(dx, dz);
        if (L < 1) return;

        const ux = dx / L;
        const uz = dz / L;
        const angle = Math.atan2(dz, dx);

        const cutouts = [];

        puertasYVentanas.forEach(dw => {
            const dwX = dw.x;
            const dwZ = dw.y;
            const dwWidth = dw.width || (dw.tipo === 'puerta' ? 80 : 100);

            // Proyección del centro del dw sobre el segmento p1-p2
            const projT = (dwX - p1.x) * ux + (dwZ - p1.z) * uz;
            const projX = p1.x + projT * ux;
            const projZ = p1.z + projT * uz;

            const distPerp = Math.hypot(dwX - projX, dwZ - projZ);

            if (distPerp <= Math.max(wallThickness * 2.5, 45) && projT + dwWidth / 2 > 0 && projT - dwWidth / 2 < L) {
                let tStart = Math.max(0, projT - dwWidth / 2);
                let tEnd = Math.min(L, projT + dwWidth / 2);

                let yBottom = 0;
                let yTop = 50;

                if (dw.tipo === 'puerta') {
                    yBottom = 0;
                    yTop = dw.height3d || 52;
                } else if (dw.tipo === 'ventana') {
                    yBottom = dw.elevation3d || 15;
                    yTop = (dw.elevation3d || 15) + (dw.height3d || 25);
                }

                cutouts.push({ tStart, tEnd, yBottom, yTop, tipo: dw.tipo });
            }
        });

        cutouts.sort((a, b) => a.tStart - b.tStart);

        const cleanCutouts = [];
        cutouts.forEach(c => {
            if (cleanCutouts.length === 0) {
                cleanCutouts.push(c);
            } else {
                const prev = cleanCutouts[cleanCutouts.length - 1];
                if (c.tStart < prev.tEnd) {
                    prev.tEnd = Math.max(prev.tEnd, c.tEnd);
                    prev.yBottom = Math.min(prev.yBottom, c.yBottom);
                    prev.yTop = Math.max(prev.yTop, c.yTop);
                } else {
                    cleanCutouts.push(c);
                }
            }
        });

        const matWall = new THREE.MeshStandardMaterial({
            color: wallColor,
            roughness: 0.45,
            metalness: 0.1,
            side: THREE.DoubleSide
        });
        const lineMat = new THREE.LineBasicMaterial({ color: 0x1e293b, linewidth: 2 });

        function addWallBlock(subLen, subH, midT, centerY) {
            if (subLen <= 0.5 || subH <= 0.5) return;
            const geom = new THREE.BoxGeometry(subLen, subH, wallThickness);
            const mesh = new THREE.Mesh(geom, matWall);
            mesh.castShadow = true;
            mesh.receiveShadow = true;

            const posX = p1.x + ux * midT;
            const posZ = p1.z + uz * midT;

            mesh.position.set(posX, centerY, posZ);
            mesh.rotation.y = -angle;

            const edges = new THREE.EdgesGeometry(geom);
            const wireframe = new THREE.LineSegments(edges, lineMat);
            wireframe.position.copy(mesh.position);
            wireframe.rotation.copy(mesh.rotation);

            parentGroup.add(mesh);
            parentGroup.add(wireframe);
        }

        let currentT = 0;

        cleanCutouts.forEach(c => {
            if (c.tStart > currentT) {
                const len = c.tStart - currentT;
                const midT = currentT + len / 2;
                addWallBlock(len, wallHeight, midT, wallHeight / 2);
            }

            const holeLen = c.tEnd - c.tStart;
            const holeMidT = c.tStart + holeLen / 2;

            if (c.tipo === 'puerta') {
                if (wallHeight > c.yTop) {
                    const lintelH = wallHeight - c.yTop;
                    const lintelY = c.yTop + lintelH / 2;
                    addWallBlock(holeLen, lintelH, holeMidT, lintelY);
                }
            } else if (c.tipo === 'ventana') {
                if (c.yBottom > 0) {
                    addWallBlock(holeLen, c.yBottom, holeMidT, c.yBottom / 2);
                }
                if (wallHeight > c.yTop) {
                    const lintelH = wallHeight - c.yTop;
                    const lintelY = c.yTop + lintelH / 2;
                    addWallBlock(holeLen, lintelH, holeMidT, lintelY);
                }
            }

            currentT = c.tEnd;
        });

        if (currentT < L) {
            const len = L - currentT;
            const midT = currentT + len / 2;
            addWallBlock(len, wallHeight, midT, wallHeight / 2);
        }
    }

    function aplicarOpacidadGrupo3D(group, opacidad) {
        if (opacidad !== undefined) {
            group.traverse(child => {
                if (child.isMesh && child.material) {
                    child.material.transparent = true;
                    child.material.opacity = opacidad;
                }
            });
        }
    }

    function renderizarEscena3D() {
        if (!scene3D) return;

        // Limpiar objetos anteriores dinámicos (manteniendo luces y suelo)
        const objectsToRemove = [];
        scene3D.children.forEach(child => {
            if (child.userData && child.userData.isDynamicElement) {
                objectsToRemove.push(child);
            }
        });
        objectsToRemove.forEach(obj => scene3D.remove(obj));

        const puertasYVentanas = elementosCanvas.filter(item => item.tipo === 'puerta' || item.tipo === 'ventana');

        elementosCanvas.forEach(el => {
            const group = new THREE.Group();
            group.userData = { isDynamicElement: true, elementId: el.id };
            group.visible = !el.oculto;

            const posX = el.x;
            const posZ = el.y; // Mapeo 2D Y -> 3D Z
            const posY = el.elevation3d || 0; // Elevación sobre el suelo 3D

            group.position.set(posX, posY, posZ);

            // Rotación 3D completa en los 3 ejes (X, Y, Z) para TODOS los componentes
            const rotX = (el.anguloX || 0) * Math.PI / 180;
            const rotY = -(el.angulo || 0) * Math.PI / 180;
            const rotZ = (el.anguloZ || 0) * Math.PI / 180;
            group.rotation.set(rotX, rotY, rotZ);

            // 1. HABITACIONES, PAREDES Y POLÍGONOS DIBUJADOS (CON VÉRTICES)
            if (el.vertices && el.vertices.length > 0) {
                group.position.set(0, posY, 0);

                const wallHeight = el.height3d || 45; // Altura Z 3D dinámica
                const wallThick = el.borderWidth ? Math.max(el.borderWidth * 3, 10) : 10;

                let wallColor = 0x3b82f6;
                if (el.borderColor && el.borderColor.startsWith('#')) {
                    wallColor = parseInt(el.borderColor.replace('#', '0x'));
                } else if (el.color && el.color.startsWith('#')) {
                    wallColor = parseInt(el.color.replace('#', '0x'));
                }

                // Paredes perimetrales huecas con corte de puertas y ventanas
                for (let i = 0; i < el.vertices.length; i++) {
                    const v1 = el.vertices[i];
                    const v2 = el.vertices[(i + 1) % el.vertices.length];
                    const p1 = { x: el.x + v1.x, z: el.y + v1.y };
                    const p2 = { x: el.x + v2.x, z: el.y + v2.y };

                    let segColor = wallColor;
                    if (el.coloresParedes && el.coloresParedes[i]) {
                        segColor = parseInt(el.coloresParedes[i].replace('#', '0x'));
                    }

                    construirMurosConHuecos(p1, p2, wallHeight, wallThick, segColor, group, puertasYVentanas);
                }

                // Suelo tenue de la habitación
                const floorShape = new THREE.Shape();
                el.vertices.forEach((v, idx) => {
                    if (idx === 0) floorShape.moveTo(v.x, v.y);
                    else floorShape.lineTo(v.x, v.y);
                });
                floorShape.closePath();

                const floorGeom = new THREE.ShapeGeometry(floorShape);
                floorGeom.rotateX(-Math.PI / 2);
                const floorMat = new THREE.MeshStandardMaterial({
                    color: wallColor,
                    roughness: 0.8,
                    transparent: true,
                    opacity: (el.opacidad !== undefined ? el.opacidad * 0.35 : 0.25),
                    side: THREE.DoubleSide
                });
                const floorMesh = new THREE.Mesh(floorGeom, floorMat);
                floorMesh.position.set(el.x, 0.2, el.y);
                group.add(floorMesh);

                aplicarOpacidadGrupo3D(group, el.opacidad);
                scene3D.add(group);
                return;
            }

            // 2. PAREDES RECTAS LINEALES Y ZONAS CIRCULARES (CUADRADOS, CÍRCULOS, LÍNEAS)
            if (['circle', 'line'].includes(el.tipo)) {
                const w = el.width || 120;
                const h = el.height || 80;
                const h3d = el.height3d || (el.tipo === 'line' ? 50 : 10);

                if (el.tipo === 'circle') {
                    const radius = w / 2;
                    const geom = new THREE.CylinderGeometry(radius, radius, h3d, 32);
                    const mat = new THREE.MeshStandardMaterial({
                        color: 0x22c55e,
                        transparent: true,
                        opacity: 0.35,
                        roughness: 0.6
                    });
                    const mesh = new THREE.Mesh(geom, mat);
                    mesh.position.set(0, h3d / 2, 0);
                    group.add(mesh);
                } else if (el.tipo === 'line') {
                    group.position.set(0, posY, 0);
                    const wallThick = el.borderWidth ? Math.max(el.borderWidth * 2.5, 10) : 10;
                    let wallColor = 0x38bdf8;
                    if (el.borderColor && el.borderColor.startsWith('#')) {
                        wallColor = parseInt(el.borderColor.replace('#', '0x'));
                    } else if (el.color && el.color.startsWith('#')) {
                        wallColor = parseInt(el.color.replace('#', '0x'));
                    }

                    const rad = (el.angulo || 0) * Math.PI / 180;
                    const dx = (w / 2) * Math.cos(rad);
                    const dz = (w / 2) * Math.sin(rad);

                    const p1 = { x: el.x - dx, z: el.y - dz };
                    const p2 = { x: el.x + dx, z: el.y + dz };

                    construirMurosConHuecos(p1, p2, h3d, wallThick, wallColor, group, puertasYVentanas);
                }
                aplicarOpacidadGrupo3D(group, el.opacidad);
                scene3D.add(group);
                return;
            }

            // 3. COMPONENTES ARQUITECTÓNICOS Y MOBILIARIO CAD
            if (['escalera_recta', 'escalera_l', 'escalera_espiral', 'puerta', 'escritorio', 'carro', 'sofa', 'comedor', 'cama', 'bano', 'planta', 'ventana', 'pantalla', 'cuadro_imagen'].includes(el.tipo)) {
                const baseDims = {
                    'escalera_recta': { w: 140, h: 70, h3d: 40 },
                    'escalera_l': { w: 130, h: 130, h3d: 40 },
                    'escalera_espiral': { w: 110, h: 110, h3d: 70 },
                    'puerta': { w: 80, h: 80, h3d: 55 },
                    'escritorio': { w: 110, h: 70, h3d: 30 },
                    'carro': { w: 120, h: 220, h3d: 30 },
                    'sofa': { w: 130, h: 75, h3d: 30 },
                    'comedor': { w: 120, h: 100, h3d: 30 },
                    'cama': { w: 110, h: 130, h3d: 30 },
                    'bano': { w: 60, h: 80, h3d: 30 },
                    'planta': { w: 60, h: 60, h3d: 40 },
                    'ventana': { w: 100, h: 25, h3d: 40 },
                    'pantalla': { w: 120, h: 15, h3d: 70 },
                    'cuadro_imagen': { w: 100, h: 10, h3d: 80 }
                };
                const base = baseDims[el.tipo] || { w: 100, h: 80, h3d: 40 };
                const w = base.w;
                const h = base.h;
                const archGroup = new THREE.Group();

                const scaleX = (el.width || base.w) / base.w;
                const scaleY = (el.height3d || base.h3d) / base.h3d;
                const scaleZ = (el.height || base.h) / base.h;
                archGroup.scale.set(scaleX, scaleY, scaleZ);

                if (el.tipo === 'escalera_recta') {
                    const numSteps = 8;
                    const stepH = 5;
                    const stepD = h / numSteps;
                    const matStep = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.4 });

                    for (let i = 0; i < numSteps; i++) {
                        const stepGeom = new THREE.BoxGeometry(w, stepH * (i + 1), stepD);
                        const stepMesh = new THREE.Mesh(stepGeom, matStep);
                        stepMesh.position.set(0, (stepH * (i + 1)) / 2, -h / 2 + stepD * i + stepD / 2);
                        stepMesh.castShadow = true;
                        archGroup.add(stepMesh);
                    }
                } else if (el.tipo === 'escalera_l') {
                    const matStep = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.4 });
                    const numSteps = 6;
                    const stepH = 6;
                    for (let i = 0; i < numSteps; i++) {
                        const stepGeom = new THREE.BoxGeometry(w * 0.5, stepH * (i + 1), h * 0.25);
                        const stepMesh = new THREE.Mesh(stepGeom, matStep);
                        stepMesh.position.set(-w * 0.25, (stepH * (i + 1)) / 2, -h * 0.35 + i * (h * 0.12));
                        stepMesh.castShadow = true;
                        archGroup.add(stepMesh);
                    }
                } else if (el.tipo === 'escalera_espiral') {
                    const poleGeom = new THREE.CylinderGeometry(4, 4, 70, 16);
                    const poleMat = new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.8 });
                    const pole = new THREE.Mesh(poleGeom, poleMat);
                    pole.position.set(0, 35, 0);
                    archGroup.add(pole);

                    const stepMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8 });
                    const numSteps = 10;
                    for (let i = 0; i < numSteps; i++) {
                        const angle = (i * 36) * Math.PI / 180;
                        const stepGeom = new THREE.BoxGeometry(w * 0.45, 3, 12);
                        const stepMesh = new THREE.Mesh(stepGeom, stepMat);
                        stepMesh.position.set(Math.cos(angle) * (w * 0.22), i * 6.5 + 4, Math.sin(angle) * (w * 0.22));
                        stepMesh.rotation.y = -angle;
                        stepMesh.castShadow = true;
                        archGroup.add(stepMesh);
                    }
                } else if (el.tipo === 'puerta') {
                    const frameMat = new THREE.MeshStandardMaterial({ color: 0x64748b });
                    const frameLeft = new THREE.Mesh(new THREE.BoxGeometry(4, 55, 6), frameMat);
                    frameLeft.position.set(-w / 2, 27.5, 0);
                    const frameRight = new THREE.Mesh(new THREE.BoxGeometry(4, 55, 6), frameMat);
                    frameRight.position.set(w / 2, 27.5, 0);
                    const frameTop = new THREE.Mesh(new THREE.BoxGeometry(w, 4, 6), frameMat);
                    frameTop.position.set(0, 53, 0);
                    archGroup.add(frameLeft); archGroup.add(frameRight); archGroup.add(frameTop);

                    const panelMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.3 });
                    const panel = new THREE.Mesh(new THREE.BoxGeometry(w * 0.9, 50, 3), panelMat);
                    panel.position.set(w * 0.45, 25, 0);
                    const pivotGroup = new THREE.Group();
                    pivotGroup.position.set(-w / 2 + 4, 0, 0);
                    pivotGroup.add(panel);
                    pivotGroup.rotation.y = Math.PI / 4;
                    archGroup.add(pivotGroup);
                } else if (el.tipo === 'escritorio') {
                    const topMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.5 });
                    const legMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.5 });

                    const top = new THREE.Mesh(new THREE.BoxGeometry(w, 4, h), topMat);
                    top.position.set(0, 28, 0);
                    top.castShadow = true;
                    archGroup.add(top);

                    const legGeom = new THREE.CylinderGeometry(2, 2, 26, 8);
                    const offsetW = w / 2 - 4;
                    const offsetH = h / 2 - 4;
                    [[offsetW, offsetH], [-offsetW, offsetH], [offsetW, -offsetH], [-offsetW, -offsetH]].forEach(pos => {
                        const leg = new THREE.Mesh(legGeom, legMat);
                        leg.position.set(pos[0], 13, pos[1]);
                        archGroup.add(leg);
                    });
                } else if (el.tipo === 'carro') {
                    const bodyMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.4, metalness: 0.2 });
                    const cabinMat = new THREE.MeshStandardMaterial({ color: 0xcbd5e1, roughness: 0.2 });
                    const wheelMat = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.9 });

                    const body = new THREE.Mesh(new THREE.BoxGeometry(w * 0.8, 12, h * 0.85), bodyMat);
                    body.position.set(0, 10, 0);
                    body.castShadow = true;
                    archGroup.add(body);

                    const cabin = new THREE.Mesh(new THREE.BoxGeometry(w * 0.7, 10, h * 0.45), cabinMat);
                    cabin.position.set(0, 21, -h * 0.05);
                    cabin.castShadow = true;
                    archGroup.add(cabin);

                    const wheelGeom = new THREE.CylinderGeometry(5, 5, 4, 16);
                    wheelGeom.rotateZ(Math.PI / 2);
                    const wx = w * 0.4, wz = h * 0.28;
                    [[wx, wz], [-wx, wz], [wx, -wz], [-wx, -wz]].forEach(pos => {
                        const wheel = new THREE.Mesh(wheelGeom, wheelMat);
                        wheel.position.set(pos[0], 5, pos[1]);
                        archGroup.add(wheel);
                    });
                } else if (el.tipo === 'sofa') {
                    const mat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.8 });
                    const matDark = new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.8 });

                    const base = new THREE.Mesh(new THREE.BoxGeometry(w, 10, h), mat);
                    base.position.set(0, 8, 0);
                    base.castShadow = true;
                    archGroup.add(base);

                    const back = new THREE.Mesh(new THREE.BoxGeometry(w, 18, h * 0.25), matDark);
                    back.position.set(0, 19, -h * 0.37);
                    back.castShadow = true;
                    archGroup.add(back);

                    const armL = new THREE.Mesh(new THREE.BoxGeometry(w * 0.18, 14, h * 0.7), matDark);
                    armL.position.set(-w * 0.41, 14, h * 0.1);
                    const armR = new THREE.Mesh(new THREE.BoxGeometry(w * 0.18, 14, h * 0.7), matDark);
                    armR.position.set(w * 0.41, 14, h * 0.1);
                    archGroup.add(armL); archGroup.add(armR);
                } else if (el.tipo === 'comedor') {
                    const matTable = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.6 });
                    const matChair = new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.6 });

                    const top = new THREE.Mesh(new THREE.BoxGeometry(w * 0.6, 4, h * 0.6), matTable);
                    top.position.set(0, 25, 0);
                    top.castShadow = true;
                    archGroup.add(top);

                    const legGeom = new THREE.CylinderGeometry(1.5, 1.5, 23, 8);
                    const lx = w * 0.25, lz = h * 0.25;
                    [[lx, lz], [-lx, lz], [lx, -lz], [-lx, -lz]].forEach(pos => {
                        const leg = new THREE.Mesh(legGeom, matTable);
                        leg.position.set(pos[0], 11.5, pos[1]);
                        archGroup.add(leg);
                    });

                    const chairGeom = new THREE.BoxGeometry(w * 0.14, 2, h * 0.14);
                    const cOffsets = [
                        { x: -w * 0.18, z: -h * 0.38 }, { x: w * 0.18, z: -h * 0.38 },
                        { x: -w * 0.18, z: h * 0.38 }, { x: w * 0.18, z: h * 0.38 }
                    ];
                    cOffsets.forEach(pos => {
                        const chair = new THREE.Mesh(chairGeom, matChair);
                        chair.position.set(pos.x, 14, pos.z);
                        archGroup.add(chair);
                    });
                } else if (el.tipo === 'cama') {
                    const frameMat = new THREE.MeshStandardMaterial({ color: 0x64748b });
                    const matMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.9 });
                    const pillowMat = new THREE.MeshStandardMaterial({ color: 0xcbd5e1, roughness: 0.9 });

                    const frame = new THREE.Mesh(new THREE.BoxGeometry(w, 8, h), frameMat);
                    frame.position.set(0, 6, 0);
                    archGroup.add(frame);

                    const head = new THREE.Mesh(new THREE.BoxGeometry(w, 28, h * 0.08), frameMat);
                    head.position.set(0, 16, -h * 0.46);
                    archGroup.add(head);

                    const mattress = new THREE.Mesh(new THREE.BoxGeometry(w * 0.92, 8, h * 0.88), matMat);
                    mattress.position.set(0, 14, h * 0.04);
                    archGroup.add(mattress);

                    const p1 = new THREE.Mesh(new THREE.BoxGeometry(w * 0.38, 3, h * 0.2), pillowMat);
                    p1.position.set(-w * 0.22, 19, -h * 0.28);
                    const p2 = new THREE.Mesh(new THREE.BoxGeometry(w * 0.38, 3, h * 0.2), pillowMat);
                    p2.position.set(w * 0.22, 19, -h * 0.28);
                    archGroup.add(p1); archGroup.add(p2);
                } else if (el.tipo === 'bano') {
                    const mat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.2 });
                    const matDark = new THREE.MeshStandardMaterial({ color: 0xcbd5e1 });

                    const tank = new THREE.Mesh(new THREE.BoxGeometry(w * 0.8, 26, h * 0.25), matDark);
                    tank.position.set(0, 16, -h * 0.35);
                    archGroup.add(tank);

                    const bowl = new THREE.Mesh(new THREE.CylinderGeometry(w * 0.35, w * 0.25, 16, 16), mat);
                    bowl.position.set(0, 10, h * 0.15);
                    archGroup.add(bowl);
                } else if (el.tipo === 'planta') {
                    const potMat = new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.7 });
                    const leafMat = new THREE.MeshStandardMaterial({ color: 0x22c55e, roughness: 0.5 });

                    const pot = new THREE.Mesh(new THREE.CylinderGeometry(w * 0.25, w * 0.18, 18, 12), potMat);
                    pot.position.set(0, 9, 0);
                    archGroup.add(pot);

                    const foliage = new THREE.Mesh(new THREE.SphereGeometry(w * 0.32, 12, 12), leafMat);
                    foliage.position.set(0, 26, 0);
                    archGroup.add(foliage);
                } else if (el.tipo === 'ventana') {
                    const capMat = new THREE.MeshStandardMaterial({ color: 0x64748b });
                    const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.4 });

                    const capL = new THREE.Mesh(new THREE.BoxGeometry(w * 0.08, 40, 8), capMat);
                    capL.position.set(-w * 0.46, 20, 0);
                    const capR = new THREE.Mesh(new THREE.BoxGeometry(w * 0.08, 40, 8), capMat);
                    capR.position.set(w * 0.46, 20, 0);
                    const glass = new THREE.Mesh(new THREE.BoxGeometry(w * 0.84, 36, 2), glassMat);
                    glass.position.set(0, 20, 0);
                    archGroup.add(capL); archGroup.add(capR); archGroup.add(glass);
                } else if (el.tipo === 'pantalla') {
                    const bezelMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.2 });
                    const standMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.9 });
                    let screenMat;

                    if (el.imagenUrl) {
                        const texture = new THREE.TextureLoader().load(el.imagenUrl, () => {
                            if (renderer3D && scene3D) renderer3D.render(scene3D, camera3D);
                        });
                        texture.colorSpace = THREE.SRGBColorSpace;
                        screenMat = new THREE.MeshStandardMaterial({
                            map: texture,
                            emissiveMap: texture,
                            emissive: 0xffffff,
                            emissiveIntensity: 0.35,
                            roughness: 0.1
                        });
                    } else {
                        screenMat = new THREE.MeshStandardMaterial({ 
                            color: 0x0284c7, 
                            emissive: 0x0369a1, 
                            emissiveIntensity: 0.4, 
                            roughness: 0.1 
                        });
                    }

                    const frame = new THREE.Mesh(new THREE.BoxGeometry(w, 40, 6), bezelMat);
                    frame.position.set(0, 20, 0);
                    archGroup.add(frame);

                    const screen = new THREE.Mesh(new THREE.BoxGeometry(w * 0.92, 35, 2), screenMat);
                    screen.position.set(0, 20, 3);
                    archGroup.add(screen);

                    if ((el.elevation3d || 0) < 10) {
                        const base = new THREE.Mesh(new THREE.BoxGeometry(w * 0.4, 4, 25), standMat);
                        base.position.set(0, 2, 0);
                        const pole = new THREE.Mesh(new THREE.CylinderGeometry(3, 3, 15, 12), standMat);
                        pole.position.set(0, 9, 0);
                        archGroup.add(base); archGroup.add(pole);
                    }
                } else if (el.tipo === 'cuadro_imagen') {
                    let frameColor = 0x7c2d12;
                    if (el.borderColor && el.borderColor.startsWith('#')) {
                        frameColor = parseInt(el.borderColor.replace('#', '0x'));
                    } else if (el.color && el.color.startsWith('#')) {
                        frameColor = parseInt(el.color.replace('#', '0x'));
                    }
                    const frameMat = new THREE.MeshStandardMaterial({ color: frameColor, roughness: 0.4 });
                    let canvasMat;

                    if (el.imagenUrl) {
                        const texture = new THREE.TextureLoader().load(el.imagenUrl, () => {
                            if (renderer3D && scene3D) renderer3D.render(scene3D, camera3D);
                        });
                        texture.colorSpace = THREE.SRGBColorSpace;
                        canvasMat = new THREE.MeshStandardMaterial({
                            map: texture,
                            roughness: 0.3
                        });
                    } else {
                        canvasMat = new THREE.MeshStandardMaterial({ color: 0xfce7f3, roughness: 0.9 });
                    }

                    const outerFrame = new THREE.Mesh(new THREE.BoxGeometry(w, 40, 6), frameMat);
                    outerFrame.position.set(0, 20, 0);
                    archGroup.add(outerFrame);

                    const canvasMesh = new THREE.Mesh(new THREE.BoxGeometry(w * 0.88, 35, 3), canvasMat);
                    canvasMesh.position.set(0, 20, 2);
                    archGroup.add(canvasMesh);
                }

                group.add(archGroup);
                aplicarOpacidadGrupo3D(group, el.opacidad);
                scene3D.add(group);
                return;
            }
            // 4. NODOS Y EQUIPOS DE INFRAESTRUCTURA DE RED (AP WI-FI, CÁMARA CCTV, SITE RACK, PC, NODO)
            const eqGroup = new THREE.Group();
            const baseNetW = (el.tipo === 'site') ? 80 : 70;
            const baseNetH = (el.tipo === 'site') ? 80 : 70;
            const baseNetH3D = (el.tipo === 'site') ? 80 : 50;

            const netScaleX = (el.width || baseNetW) / baseNetW;
            const netScaleY = (el.height3d || baseNetH3D) / baseNetH3D;
            const netScaleZ = (el.height || baseNetH) / baseNetH;
            eqGroup.scale.set(netScaleX, netScaleY, netScaleZ);

            let pinColor = 0x38bdf8;
            if (el.color && el.color.startsWith('#')) {
                pinColor = parseInt(el.color.replace('#', '0x'));
            }

            if (el.tipo === 'ap') {
                const apGeom = new THREE.CylinderGeometry(18, 22, 8, 32);
                const apMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.2 });
                const apMesh = new THREE.Mesh(apGeom, apMat);
                apMesh.position.set(0, 50, 0);
                apMesh.castShadow = true;
                eqGroup.add(apMesh);

                const ledGeom = new THREE.SphereGeometry(3, 16, 16);
                const ledMat = new THREE.MeshBasicMaterial({ color: 0x38bdf8 });
                const led = new THREE.Mesh(ledGeom, ledMat);
                led.position.set(0, 46, 0);
                eqGroup.add(led);

                const radio3D = (el.radio || 120);
                const sphereGeom = new THREE.SphereGeometry(radio3D, 32, 16);
                const sphereMat = new THREE.MeshStandardMaterial({
                    color: 0x38bdf8,
                    transparent: true,
                    opacity: 0.04,
                    depthWrite: false,
                    roughness: 0.9,
                    wireframe: false,
                    side: THREE.DoubleSide
                });
                const coverageSphere = new THREE.Mesh(sphereGeom, sphereMat);
                coverageSphere.position.set(0, 50, 0);
                eqGroup.add(coverageSphere);

            } else if (el.tipo === 'camara') {
                const baseGeom = new THREE.CylinderGeometry(3, 3, 20, 8);
                const baseMat = new THREE.MeshStandardMaterial({ color: 0x475569 });
                const base = new THREE.Mesh(baseGeom, baseMat);
                base.position.set(0, 30, 0);
                eqGroup.add(base);

                const bodyGeom = new THREE.BoxGeometry(12, 12, 24);
                const bodyMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.3 });
                const body = new THREE.Mesh(bodyGeom, bodyMat);
                body.position.set(0, 38, 8);
                body.rotation.x = Math.PI / 8;
                body.castShadow = true;
                eqGroup.add(body);

                const lensGeom = new THREE.CylinderGeometry(4, 4, 6, 16);
                const lensMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.9 });
                const lens = new THREE.Mesh(lensGeom, lensMat);
                lens.position.set(0, 34, 20);
                lens.rotation.x = Math.PI / 2;
                eqGroup.add(lens);

                // Hitbox invisible de 50px de diámetro para clic/clic derecho fácil
                const camHitGeom = new THREE.SphereGeometry(25, 12, 12);
                const camHitMat = new THREE.MeshBasicMaterial({ visible: false });
                const camHitMesh = new THREE.Mesh(camHitGeom, camHitMat);
                camHitMesh.position.set(0, 35, 8);
                eqGroup.add(camHitMesh);

            } else if (el.tipo === 'site') {
                const rackGeom = new THREE.BoxGeometry(36, 75, 36);
                const rackMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.6, roughness: 0.3 });
                const rack = new THREE.Mesh(rackGeom, rackMat);
                rack.position.set(0, 37.5, 0);
                rack.castShadow = true;
                eqGroup.add(rack);

                const slotGeom = new THREE.BoxGeometry(32, 8, 34);
                const slotMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, metalness: 0.8 });
                for (let y = 15; y <= 60; y += 12) {
                    const slot = new THREE.Mesh(slotGeom, slotMat);
                    slot.position.set(0, y, 1);
                    eqGroup.add(slot);

                    const ledGeom = new THREE.SphereGeometry(1.5, 8, 8);
                    const ledMat = new THREE.MeshBasicMaterial({ color: (y % 24 === 0) ? 0x22c55e : 0x38bdf8 });
                    const led = new THREE.Mesh(ledGeom, ledMat);
                    led.position.set(-12, y, 18.5);
                    eqGroup.add(led);
                }

            } else if (el.tipo === 'impresora') {
                const bodyMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9, roughness: 0.3 });
                const darkMat = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.5 });
                const accentMat = new THREE.MeshStandardMaterial({ color: 0xa855f7, metalness: 0.6 });

                const base = new THREE.Mesh(new THREE.BoxGeometry(32, 18, 30), bodyMat);
                base.position.set(0, 9, 0);
                base.castShadow = true;
                eqGroup.add(base);

                const topScan = new THREE.Mesh(new THREE.BoxGeometry(32, 4, 30), darkMat);
                topScan.position.set(0, 20, 0);
                eqGroup.add(topScan);

                const panel = new THREE.Mesh(new THREE.BoxGeometry(10, 8, 3), accentMat);
                panel.position.set(10, 16, 15);
                panel.rotation.x = -Math.PI / 6;
                eqGroup.add(panel);

            } else if (el.tipo === 'telefono') {
                const phoneBodyMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.4 });
                const displayMat = new THREE.MeshStandardMaterial({ color: 0x06b6d4, roughness: 0.1 });
                const handsetMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.3 });

                const base = new THREE.Mesh(new THREE.BoxGeometry(22, 10, 24), phoneBodyMat);
                base.position.set(0, 5, 0);
                base.rotation.x = Math.PI / 12;
                base.castShadow = true;
                eqGroup.add(base);

                const disp = new THREE.Mesh(new THREE.PlaneGeometry(14, 9), displayMat);
                disp.position.set(2, 9, 2);
                disp.rotation.x = -Math.PI / 3;
                eqGroup.add(disp);

                const handset = new THREE.Mesh(new THREE.BoxGeometry(6, 6, 26), handsetMat);
                handset.position.set(-8, 9, 0);
                handset.rotation.x = Math.PI / 12;
                eqGroup.add(handset);

            } else if (el.tipo === 'pc' || el.tipo === 'equipo_inv') {
                const subtipo = el.subtipo || 'desktop';
                const monitorMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.2 });
                const screenMat = new THREE.MeshStandardMaterial({ color: 0x2563eb, roughness: 0.1 });
                const baseMat = new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.5 });

                if (subtipo === 'laptop') {
                    const lapBase = new THREE.Mesh(new THREE.BoxGeometry(28, 2, 20), baseMat);
                    lapBase.position.set(0, 1, 0);
                    lapBase.castShadow = true;
                    eqGroup.add(lapBase);

                    const lapScreen = new THREE.Mesh(new THREE.BoxGeometry(28, 18, 1.5), monitorMat);
                    lapScreen.position.set(0, 10, -9);
                    lapScreen.rotation.x = -Math.PI / 8;
                    lapScreen.castShadow = true;
                    eqGroup.add(lapScreen);

                    const disp = new THREE.Mesh(new THREE.PlaneGeometry(25, 15), screenMat);
                    disp.position.set(0, 10, -8.1);
                    disp.rotation.x = -Math.PI / 8;
                    eqGroup.add(disp);

                } else if (subtipo === 'allinone') {
                    const aioMonitor = new THREE.Mesh(new THREE.BoxGeometry(34, 22, 3), monitorMat);
                    aioMonitor.position.set(0, 18, 0);
                    aioMonitor.castShadow = true;
                    eqGroup.add(aioMonitor);

                    const screen = new THREE.Mesh(new THREE.PlaneGeometry(31, 19), screenMat);
                    screen.position.set(0, 18, 1.6);
                    eqGroup.add(screen);

                    const stand = new THREE.Mesh(new THREE.BoxGeometry(10, 12, 10), baseMat);
                    stand.position.set(0, 6, -2);
                    eqGroup.add(stand);

                } else {
                    const monitor = new THREE.Mesh(new THREE.BoxGeometry(28, 18, 3), monitorMat);
                    monitor.position.set(0, 22, 0);
                    monitor.castShadow = true;
                    eqGroup.add(monitor);

                    const screen = new THREE.Mesh(new THREE.PlaneGeometry(25, 15), screenMat);
                    screen.position.set(0, 22, 1.6);
                    eqGroup.add(screen);

                    const stand = new THREE.Mesh(new THREE.CylinderGeometry(2, 4, 10, 8), baseMat);
                    stand.position.set(0, 8, 0);
                    eqGroup.add(stand);

                    const tower = new THREE.Mesh(new THREE.BoxGeometry(10, 22, 22), monitorMat);
                    tower.position.set(20, 11, 0);
                    tower.castShadow = true;
                    eqGroup.add(tower);
                }

            } else {
                const pinGeom = new THREE.CylinderGeometry(14, 14, 16, 16);
                const pinMat = new THREE.MeshStandardMaterial({ color: pinColor, metalness: 0.3, roughness: 0.4 });
                const pinMesh = new THREE.Mesh(pinGeom, pinMat);
                pinMesh.position.set(0, 8, 0);
                pinMesh.castShadow = true;
                eqGroup.add(pinMesh);

                const icoGeom = new THREE.SphereGeometry(6, 16, 16);
                const icoMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
                const ico = new THREE.Mesh(icoGeom, icoMat);
                ico.position.set(0, 16, 0);
                eqGroup.add(ico);
            }

            group.add(eqGroup);
            const esDispositivoRed = ['nodo', 'pc', 'ap', 'camara', 'site', 'impresora', 'telefono', 'pantalla', 'equipo_inv'].includes(el.tipo);
            if (!esDispositivoRed) {
                aplicarOpacidadGrupo3D(group, el.opacidad);
            }
            scene3D.add(group);
        });
    }

    let panStartX = 0;
    let panStartY = 0;
    let panScrollLeft = 0;
    let panScrollTop = 0;

    // HISTORIAL DE DESHACER (CTRL+Z) Y REHACER (CTRL+Y)
    let historialUndo = [];
    let historialRedo = [];
    const MAX_HISTORIAL = 40;

    function guardarEstadoHistorial() {
        const snapshot = JSON.stringify(elementosCanvas);
        if (historialUndo.length > 0 && historialUndo[historialUndo.length - 1] === snapshot) {
            return;
        }
        historialUndo.push(snapshot);
        if (historialUndo.length > MAX_HISTORIAL) {
            historialUndo.shift();
        }
        historialRedo = [];
        actualizarBotonesHistorial();
    }

    function deshacerAccion() {
        if (historialUndo.length === 0) return;
        historialRedo.push(JSON.stringify(elementosCanvas));
        const previousState = historialUndo.pop();
        elementosCanvas = JSON.parse(previousState) || [];
        elementoSeleccionado = null;
        const panel = document.getElementById('panelPropiedades');
        if (panel) panel.classList.add('d-none');
        renderizarNodosCanvas();
        actualizarBotonesHistorial();
    }

    function rehacerAccion() {
        if (historialRedo.length === 0) return;
        historialUndo.push(JSON.stringify(elementosCanvas));
        const nextState = historialRedo.pop();
        elementosCanvas = JSON.parse(nextState) || [];
        elementoSeleccionado = null;
        const panel = document.getElementById('panelPropiedades');
        if (panel) panel.classList.add('d-none');
        renderizarNodosCanvas();
        actualizarBotonesHistorial();
    }

    function actualizarBotonesHistorial() {
        const btnUndo = document.getElementById('btnUndoCanvas');
        const btnRedo = document.getElementById('btnRedoCanvas');
        if (btnUndo) btnUndo.disabled = (historialUndo.length === 0);
        if (btnRedo) btnRedo.disabled = (historialRedo.length === 0);
    }

    // CÁLCULO DE DISTANCIA E INSERCIÓN DE PUNTOS DE DOBLAJE EN EL SEGMENTO MÁS CERCANO
    function distanciaPuntoASegmento(px, py, x1, y1, x2, y2) {
        const l2 = (x2 - x1) * (x2 - x1) + (y2 - y1) * (y2 - y1);
        if (l2 === 0) return Math.hypot(px - x1, py - y1);
        let t = ((px - x1) * (x2 - x1) + (py - y1) * (y2 - y1)) / l2;
        t = Math.max(0, Math.min(1, t));
        return Math.hypot(px - (x1 + t * (x2 - x1)), py - (y1 + t * (y2 - y1)));
    }

    function insertarVerticeEnSegmentoMasCercano(pts, px, py) {
        if (!pts || pts.length === 0) return;
        if (pts.length < 2) {
            pts.push({ x: px, y: py });
            return;
        }
        let bestIndex = 1;
        let minDistance = Infinity;

        for (let i = 0; i < pts.length; i++) {
            const p1 = pts[i];
            const p2 = pts[(i + 1) % pts.length];
            const dist = distanciaPuntoASegmento(px, py, p1.x, p1.y, p2.x, p2.y);
            if (dist < minDistance) {
                minDistance = dist;
                bestIndex = i + 1;
            }
        }

        pts.splice(bestIndex, 0, { x: px, y: py });
    }

    if (planoActualData && planoActualData.elementos_json) {
        try {
            elementosCanvas = JSON.parse(planoActualData.elementos_json) || [];
        } catch (e) {
            elementosCanvas = [];
        }
    }

    // COPIAR, PEGAR Y DUPLICAR COMPONENTES (SIMPLE O MULTISELECCIÓN)
    function copiarElementoSeleccionado() {
        if (elementosSeleccionados.length > 0) {
            portapapelesElemento = JSON.parse(JSON.stringify(elementosSeleccionados));
        } else if (elementoSeleccionado) {
            portapapelesElemento = JSON.parse(JSON.stringify([elementoSeleccionado]));
        }
    }

    function pegarElementoPortapapeles(customX = null, customY = null) {
        if (!portapapelesElemento) return;
        guardarEstadoHistorial();

        const listaAPegar = Array.isArray(portapapelesElemento) ? portapapelesElemento : [portapapelesElemento];
        if (listaAPegar.length === 0) return;

        let minX = Math.min(...listaAPegar.map(e => e.x));
        let minY = Math.min(...listaAPegar.map(e => e.y));

        const nuevosPapeados = [];

        listaAPegar.forEach(item => {
            const nuevo = JSON.parse(JSON.stringify(item));
            const isVert = (nuevo.vertices && nuevo.vertices.length > 0);
            nuevo.id = (isVert ? 'fig_' : 'elem_') + Date.now() + '_' + Math.floor(Math.random() * 10000);

            if (customX !== null && customY !== null) {
                const offsetX = item.x - minX;
                const offsetY = item.y - minY;
                nuevo.x = customX + offsetX;
                nuevo.y = customY + offsetY;
            } else {
                nuevo.x += 35;
                nuevo.y += 35;
            }

            if (nuevo.label && !nuevo.label.includes('(Copia)')) {
                nuevo.label += ' (Copia)';
            }

            elementosCanvas.push(nuevo);
            nuevosPapeados.push(nuevo);
        });

        elementosSeleccionados = nuevosPapeados;
        elementoSeleccionado = nuevosPapeados[nuevosPapeados.length - 1];
        renderizarNodosCanvas();
    }

    function duplicarElementoSeleccionado() {
        if (elementosSeleccionados.length === 0 && !elementoSeleccionado) return;
        copiarElementoSeleccionado();
        pegarElementoPortapapeles();
    }

    function seleccionarTodoElPlano() {
        if (elementosCanvas.length === 0) return;
        elementosSeleccionados = [...elementosCanvas];
        elementoSeleccionado = elementosSeleccionados[elementosSeleccionados.length - 1];
        renderizarNodosCanvas();

        const panel = document.getElementById('panelPropiedades');
        if (panel && elementoSeleccionado) {
            panel.classList.remove('d-none');
        }
    }

    function deseleccionarElemento() {
        elementosSeleccionados = [];
        elementoSeleccionado = null;
        const panel = document.getElementById('panelPropiedades');
        if (panel) {
            panel.classList.add('d-none');
        }
        actualizarEstadoBotonesInventario();
        renderizarNodosCanvas();
    }

    // TECLAS DE ACCESO RÁPIDO (SUPR = ELIMINAR, CTRL+A = SELECCIONAR TODO, CTRL+C = COPIAR, CTRL+V = PEGAR, CTRL+D = DUPLICAR, CTRL+Z = DESHACER, CTRL+Y = REHACER, ESC = DESELECCIONAR)
    document.addEventListener('keydown', (e) => {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
        
        if (e.key === 'Escape') {
            deseleccionarElemento();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
            e.preventDefault();
            seleccionarTodoElPlano();
        } else if ((e.key === 'Delete' || e.key === 'Backspace') && (elementosSeleccionados.length > 0 || elementoSeleccionado)) {
            e.preventDefault();
            eliminarElementoSeleccionado();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
            e.preventDefault();
            if (e.shiftKey) {
                rehacerAccion();
            } else {
                deshacerAccion();
            }
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
            e.preventDefault();
            rehacerAccion();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
            if (elementosSeleccionados.length > 0 || elementoSeleccionado) {
                e.preventDefault();
                copiarElementoSeleccionado();
            }
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v') {
            e.preventDefault();
            pegarElementoPortapapeles();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
            if (elementosSeleccionados.length > 0 || elementoSeleccionado) {
                e.preventDefault();
                duplicarElementoSeleccionado();
            }
        }
    });

    function crearNuevoPlanoModal() {
        const modal = new bootstrap.Modal(document.getElementById('modalCrearPlano'));
        modal.show();
    }

    function abrirModalNuevo() {
        const secActiva = window.seccionActiva || 'site';
        document.getElementById('modalFormTitle').innerHTML = '<i class="bi bi-plus-circle text-success me-2"></i> Registrar en ' + (secActiva === 'site' ? 'SITE Principal' : 'RED / IDFs');
        document.getElementById('form_accion').value = (secActiva === 'site' ? 'guardar_site' : 'guardar_red');
        document.getElementById('form_id').value = '0';
        const modal = new bootstrap.Modal(document.getElementById('modalInfraForm'));
        modal.show();
    }

    function abrirModalEditar(reg) {
        abrirModalNuevo();
        document.getElementById('form_id').value = reg.id;
        document.getElementById('modalFormTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i> Editar Registro #' + reg.id;
        for (const col in reg) {
            const el = document.getElementById('field_' + col);
            if (el) el.value = reg[col] || '';
        }
    }

    // ARRASTRAR Y SOLTAR (DRAG AND DROP) DESDE PALETA E INVENTARIO AL PLANO 2D/3D
    function iniciarDragPalette(e, categoria, tipo) {
        if (e && e.dataTransfer) {
            e.dataTransfer.setData('text/plain', JSON.stringify({ categoria: categoria, tipo: tipo }));
            e.dataTransfer.effectAllowed = 'copy';
        }
    }

    function iniciarDragInventario(e) {
        const sel = document.getElementById('selectEquipoInventario');
        if (!sel || !sel.value) return;
        try {
            const eqData = JSON.parse(sel.value);
            if (e && e.dataTransfer) {
                e.dataTransfer.setData('text/plain', JSON.stringify({ categoria: 'elemento', tipo: 'equipo_inv', customData: eqData }));
                e.dataTransfer.effectAllowed = 'copy';
            }
        } catch(err) {}
    }

    // AGREGAR FIGURAS GEOMÉTRICAS Y POLÍGONOS (CUADRADO, TRIÁNGULO, CÍRCULO, POLÍGONO L/T, LINEA)
    function agregarFiguraCanvas(tipoFigura, customX = null, customY = null) {
        if (!planoActualData) planoActualData = { id: 1, nombre_plano: 'Plano Principal', elementos_json: '[]' };
        guardarEstadoHistorial();
        const id = 'fig_' + Date.now() + '_' + Math.floor(Math.random() * 1000);

        const pos = (customX !== null && customY !== null) ? { x: customX, y: customY } : obtenerPosicionInsercionActual();

        let item = {
            id: id,
            tipo: tipoFigura,
            x: pos.x,
            y: pos.y,
            width: (tipoFigura === 'line' ? 180 : (tipoFigura === 'triangle' ? 140 : 160)),
            height: (tipoFigura === 'line' ? 6 : (tipoFigura === 'triangle' ? 120 : 110)),
            label: (tipoFigura === 'rect' ? 'Oficina / Sala' : (tipoFigura === 'polygon' ? 'Habitación L' : (tipoFigura === 'circle' ? 'Zona Cobertura' : (tipoFigura === 'line' ? 'Pared' : 'Área Angular')))),
            color: (tipoFigura === 'rect' ? 'rgba(37, 99, 235, 0.25)' : (tipoFigura === 'polygon' ? 'rgba(168, 85, 247, 0.25)' : (tipoFigura === 'circle' ? 'rgba(34, 197, 94, 0.25)' : (tipoFigura === 'line' ? '#38bdf8' : 'rgba(249, 115, 22, 0.3)')))),
            borderColor: (tipoFigura === 'rect' ? '#3b82f6' : (tipoFigura === 'polygon' ? '#c084fc' : (tipoFigura === 'circle' ? '#22c55e' : (tipoFigura === 'line' ? '#38bdf8' : '#fb923c')))),
            borderWidth: (tipoFigura === 'line' ? 4 : 2),
            angulo: 0
        };

        if (tipoFigura === 'rect') {
            item.vertices = [
                {x: 0, y: 0},
                {x: 160, y: 0},
                {x: 160, y: 110},
                {x: 0, y: 110}
            ];
        } else if (tipoFigura === 'polygon') {
            item.vertices = [
                {x: 0, y: 0},
                {x: 160, y: 0},
                {x: 160, y: 60},
                {x: 90, y: 60},
                {x: 90, y: 130},
                {x: 0, y: 130}
            ];
        } else if (tipoFigura === 'triangle') {
            item.vertices = [
                {x: 70, y: 0},
                {x: 140, y: 120},
                {x: 0, y: 120}
            ];
        }

        elementosCanvas.push(item);
        renderizarNodosCanvas();
        if (scene3D) renderizarEscena3D();
        seleccionarElemento(item);
    }

    // INTERACTIVIDAD CANVAS 2D DE MAPEO DE PLANO
    function agregarElementoCanvas(tipo, customData = null, customX = null, customY = null) {
        if (!planoActualData) planoActualData = { id: 1, nombre_plano: 'Plano Principal', elementos_json: '[]' };
        guardarEstadoHistorial();

        const id = 'elem_' + Date.now() + '_' + Math.floor(Math.random() * 1000);

        const pos = (customX !== null && customY !== null) ? { x: customX, y: customY } : obtenerPosicionInsercionActual();

        let item = {
            id: id,
            tipo: tipo,
            x: pos.x,
            y: pos.y,
            label: '',
            icon: 'bi-ethernet',
            color: '#38bdf8',
            radio: 100,
            angulo: 0,
            width: 60,
            height: 60
        };

        if (tipo === 'nodo') {
            item.label = 'Nodo #' + (elementosCanvas.length + 1);
            item.icon = 'bi-ethernet';
            item.color = '#38bdf8';
            item.width = 60; item.height = 60;
        } else if (tipo === 'pc') {
            item.label = 'PC #' + (elementosCanvas.length + 1);
            item.subtipo = (customData && customData.subtipo) ? customData.subtipo : 'desktop';
            item.icon = (item.subtipo === 'laptop' ? 'bi-laptop' : (item.subtipo === 'allinone' ? 'bi-display' : 'bi-pc-display-horizontal'));
            item.color = '#3b82f6';
            item.width = 70; item.height = 70;
        } else if (tipo === 'impresora') {
            item.label = 'Impresora #' + (elementosCanvas.length + 1);
            item.icon = 'bi-printer-fill';
            item.color = '#a855f7';
            item.width = 70; item.height = 70;
        } else if (tipo === 'telefono') {
            item.label = 'Teléfono IP #' + (elementosCanvas.length + 1);
            item.icon = 'bi-telephone-fill';
            item.color = '#06b6d4';
            item.width = 65; item.height = 65;
        } else if (tipo === 'ap') {
            item.label = 'AP Wi-Fi #' + (elementosCanvas.length + 1);
            item.icon = 'bi-wifi';
            item.color = '#38bdf8';
            item.radio = 120;
            item.width = 75; item.height = 75;
        } else if (tipo === 'camara') {
            item.label = 'Cámara CCTV #' + (elementosCanvas.length + 1);
            item.icon = 'bi-camera-fill';
            item.color = '#fb923c';
            item.angulo = 0;
            item.width = 70; item.height = 70;
        } else if (tipo === 'site') {
            item.label = 'SITE / IDF';
            item.icon = 'bi-hdd-rack-fill';
            item.color = '#22c55e';
            item.width = 80; item.height = 80;
        } else if (tipo === 'escalera_recta') {
            item.label = 'Escalera Recta';
            item.width = 140; item.height = 70;
        } else if (tipo === 'escalera_l') {
            item.label = 'Escalera en L';
            item.width = 130; item.height = 130;
        } else if (tipo === 'escalera_espiral') {
            item.label = 'Escalera Espiral';
            item.width = 110; item.height = 110;
        } else if (tipo === 'puerta') {
            item.label = 'Puerta';
            item.width = 80; item.height = 80;
        } else if (tipo === 'escritorio') {
            item.label = 'Escritorio';
            item.width = 110; item.height = 70;
        } else if (tipo === 'carro') {
            item.label = 'Auto / Vehículo';
            item.width = 120; item.height = 220;
        } else if (tipo === 'sofa') {
            item.label = 'Sofá / Sillón';
            item.width = 130; item.height = 75;
        } else if (tipo === 'comedor') {
            item.label = 'Mesa Comedor';
            item.width = 120; item.height = 100;
        } else if (tipo === 'cama') {
            item.label = 'Cama';
            item.width = 110; item.height = 130;
        } else if (tipo === 'bano') {
            item.label = 'Inodoro / Baño';
            item.width = 60; item.height = 80;
        } else if (tipo === 'planta') {
            item.label = 'Planta Decorativa';
            item.width = 60; item.height = 60;
        } else if (tipo === 'ventana') {
            item.label = 'Ventana';
            item.width = 100; item.height = 25;
        } else if (tipo === 'pantalla') {
            item.label = 'Pantalla TV #' + (elementosCanvas.length + 1);
            item.icon = 'bi-tv-fill';
            item.color = '#38bdf8';
            item.width = 120; item.height = 15; item.height3d = 70; item.elevation3d = 40;
        } else if (tipo === 'cuadro_imagen') {
            item.label = 'Cuadro Pared #' + (elementosCanvas.length + 1);
            item.icon = 'bi-image-fill';
            item.color = '#ec4899';
            item.width = 100; item.height = 10; item.height3d = 80; item.elevation3d = 45;
        } else if (customData) {
            item.label = customData.nombre;
            item.icon = customData.icon || 'bi-display-fill';
            item.color = customData.color || '#3b82f6';
            item.inventario_key = customData.key;
            item.ip = (customData.ip && customData.ip !== 'N/A') ? customData.ip : '';
            item.dept = customData.dept || 'General';
            item.inventario_data = customData;
            item.width = 70; item.height = 70;
        }

        elementosCanvas.push(item);
        renderizarNodosCanvas();
        if (scene3D) renderizarEscena3D();
        seleccionarElemento(item);
    }

    function actualizarEstadoBotonesInventario() {
        const sel = document.getElementById('selectEquipoInventario');
        const btnVincularSel = document.getElementById('btnVincularElementoSeleccionado');
        const btnAgregar = document.getElementById('btnAgregarNuevoEquipoInventario');
        const hasSelection = (elementoSeleccionado !== null);
        const hasInventoryChoice = (sel && sel.value !== '');

        if (btnVincularSel) {
            if (hasSelection && hasInventoryChoice) {
                btnVincularSel.style.display = 'block';
                const targetName = elementoSeleccionado.label || ('Componente #' + elementoSeleccionado.id);
                btnVincularSel.innerHTML = `<i class="bi bi-link-45deg me-1"></i> Vincular a "<b>${escapeHtml(targetName)}</b>" y Ver Ficha`;
            } else {
                btnVincularSel.style.display = 'none';
            }
        }
        if (btnAgregar) {
            btnAgregar.innerHTML = (hasSelection && hasInventoryChoice)
                ? `<i class="bi bi-plus-circle me-1"></i> O Agregar como Nuevo Componente al Plano`
                : `<i class="bi bi-plus-circle me-1"></i> Agregar como Nuevo Equipo al Plano`;
        }
    }

    function vincularEquipoInventarioAElementoSeleccionado() {
        if (!elementoSeleccionado) {
            alert('Por favor selecciona primero un componente o máquina en el plano.');
            return;
        }
        const sel = document.getElementById('selectEquipoInventario');
        if (!sel || !sel.value) {
            alert('Por favor selecciona un equipo del inventario de la lista.');
            return;
        }
        try {
            const eqData = JSON.parse(sel.value);
            guardarEstadoHistorial();

            elementoSeleccionado.label = eqData.nombre || elementoSeleccionado.label;
            if (eqData.ip && eqData.ip !== 'N/A') {
                elementoSeleccionado.ip = eqData.ip;
            }
            if (eqData.dept) {
                elementoSeleccionado.dept = eqData.dept;
            }
            if (eqData.icon) {
                elementoSeleccionado.icon = eqData.icon;
            }
            if (eqData.color) {
                elementoSeleccionado.color = eqData.color;
            }
            elementoSeleccionado.inventario_key = eqData.key;
            elementoSeleccionado.inventario_data = eqData;

            renderizarNodosCanvas();
            if (typeof renderizarEscena3D === 'function' && scene3D) {
                renderizarEscena3D();
            }

            actualizarEstadoBotonesInventario();
            mostrarNotificacionToast(`🔗 ¡Equipo "${eqData.nombre}" vinculado exitosamente a "${elementoSeleccionado.label}"!`);

            // Abrir Ficha Técnica inmediatamente para ver todos los datos del inventario
            mostrarFichaTecnicaModal(elementoSeleccionado);
        } catch (e) {
            console.error('Error al vincular equipo del inventario:', e);
        }
    }

    function agregarEquipoInventarioAlCanvas() {
        const sel = document.getElementById('selectEquipoInventario');
        if (!sel || !sel.value) {
            alert('Por favor selecciona un equipo del inventario de la lista.');
            return;
        }
        try {
            const eqData = JSON.parse(sel.value);
            if (elementoSeleccionado && confirm(`¿Deseas vincular "${eqData.nombre}" al componente seleccionado ("${elementoSeleccionado.label || 'Componente'}")?\n\nHaz clic en Aceptar para vincularlo y abrir su Ficha Técnica, o Cancelar para crearlo como un nuevo objeto.`)) {
                vincularEquipoInventarioAElementoSeleccionado();
                return;
            }
            agregarElementoCanvas('equipo_inv', eqData);
        } catch (e) {}
    }

    // MANEJO DE ZOOM Y PANEO DE EDICIÓN DEL CANVA 2D
    let zoomLevel = 1.0;

    function aplicarZoomViewport() {
        const vp = document.getElementById('canvasViewport');
        const badge = document.getElementById('badgeZoomLevel');
        if (vp) {
            vp.style.transform = `scale(${zoomLevel})`;
        }
        if (badge) {
            badge.textContent = Math.round(zoomLevel * 100) + '%';
        }
        renderizarMinimapa();
    }

    function cambiarZoom(delta) {
        zoomLevel = Math.round(Math.max(0.3, Math.min(3.0, zoomLevel + delta)) * 100) / 100;
        aplicarZoomViewport();
    }

    function resetearZoom() {
        zoomLevel = 1.0;
        aplicarZoomViewport();
    }

    function renderizarMinimapa() {
        const minimap = document.getElementById('minimapCanvas');
        if (!minimap) return;
        const ctx = minimap.getContext('2d');
        const miniW = minimap.width;
        const miniH = minimap.height;
        
        ctx.clearRect(0, 0, miniW, miniH);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, miniW, miniH);
        
        const totalW = 2400;
        const totalH = 1800;
        const scaleX = miniW / totalW;
        const scaleY = miniH / totalH;

        // Trazado de Cuadrícula en Minimapa
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.08)';
        ctx.lineWidth = 1;
        for (let x = 0; x < miniW; x += 25) {
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, miniH); ctx.stroke();
        }
        for (let y = 0; y < miniH; y += 25) {
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(miniW, y); ctx.stroke();
        }

        // Trazado de todos los elementos en Minimapa
        elementosCanvas.forEach(el => {
            const isSel = (elementoSeleccionado && elementoSeleccionado.id === el.id);
            const mx = el.x * scaleX;
            const my = el.y * scaleY;
            const mw = Math.max(3, (el.width || 50) * scaleX);
            const mh = Math.max(3, (el.height || 50) * scaleY);

            if (isSel) {
                ctx.fillStyle = '#f59e0b';
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.arc(mx, my, 5, 0, Math.PI * 2);
                ctx.fill(); ctx.stroke();
            } else if (el.vertices && el.vertices.length > 0) {
                ctx.fillStyle = el.borderColor || '#3b82f6';
                ctx.fillRect(mx, my, mw, mh);
            } else {
                ctx.fillStyle = el.color || '#38bdf8';
                ctx.beginPath();
                ctx.arc(mx, my, 3, 0, Math.PI * 2);
                ctx.fill();
            }
        });

        // Trazado de la Ventana Visible (Viewport Box)
        const outer = document.getElementById('canvasOuter');
        if (outer) {
            const viewX = (outer.scrollLeft / zoomLevel) * scaleX;
            const viewY = (outer.scrollTop / zoomLevel) * scaleY;
            const viewW = (outer.clientWidth / zoomLevel) * scaleX;
            const viewH = (outer.clientHeight / zoomLevel) * scaleY;

            ctx.strokeStyle = '#38bdf8';
            ctx.lineWidth = 1.8;
            ctx.fillStyle = 'rgba(56, 189, 248, 0.15)';
            ctx.fillRect(viewX, viewY, viewW, viewH);
            ctx.strokeRect(viewX, viewY, viewW, viewH);

            const coordsSpan = document.getElementById('minimapCoords');
            if (coordsSpan) {
                const curX = elementoSeleccionado ? elementoSeleccionado.x : Math.round(outer.scrollLeft / zoomLevel);
                const curY = elementoSeleccionado ? elementoSeleccionado.y : Math.round(outer.scrollTop / zoomLevel);
                coordsSpan.textContent = `(${curX}, ${curY})`;
            }
        }
    }

    function centrarVistaEnElemento(el) {
        if (!el) return;
        const outer = document.getElementById('canvasOuter');
        if (outer) {
            const targetScrollLeft = (el.x * zoomLevel) - (outer.clientWidth / 2);
            const targetScrollTop = (el.y * zoomLevel) - (outer.clientHeight / 2);

            outer.scrollTo({
                left: Math.max(0, targetScrollLeft),
                top: Math.max(0, targetScrollTop),
                behavior: 'smooth'
            });
            
            renderizarMinimapa();
        }

        // Centrar Cámara 3D en el componente
        if (controls3D) {
            controls3D.target.set(el.x, el.elevation3d || 0, el.y);
            controls3D.update();
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (planoActualData) {
            renderizarNodosCanvas();
            const initialModo = window.modoUrl || 'inicio';
            activarModoNavegacion(initialModo);
        }

        const canvasOuter = document.getElementById('canvasOuter');
        const minimap = document.getElementById('minimapCanvas');

        if (canvasOuter) {
            // ARRASTRAR Y SOLTAR (DRAG & DROP) SOBRE EL LIENZO 2D/3D
            canvasOuter.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
            });

            canvasOuter.addEventListener('drop', (e) => {
                e.preventDefault();
                const dataStr = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
                if (!dataStr) return;
                try {
                    const payload = JSON.parse(dataStr);
                    let dropX, dropY;

                    if (modoPlanoActual === '3d' && camera3D) {
                        const rect = canvasOuter.getBoundingClientRect();
                        const mouseX = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                        const mouseY = -((e.clientY - rect.top) / rect.height) * 2 + 1;
                        const raycaster = new THREE.Raycaster();
                        raycaster.setFromCamera(new THREE.Vector2(mouseX, mouseY), camera3D);
                        const plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
                        const intersectPoint = new THREE.Vector3();
                        if (raycaster.ray.intersectPlane(plane, intersectPoint)) {
                            dropX = Math.round(intersectPoint.x);
                            dropY = Math.round(intersectPoint.z);
                        } else {
                            const pos = obtenerPosicionInsercionActual();
                            dropX = pos.x;
                            dropY = pos.y;
                        }
                    } else {
                        const rect = canvasOuter.getBoundingClientRect();
                        dropX = Math.round(((e.clientX - rect.left) + canvasOuter.scrollLeft) / zoomLevel);
                        dropY = Math.round(((e.clientY - rect.top) + canvasOuter.scrollTop) / zoomLevel);
                    }

                    if (payload.categoria === 'figura') {
                        agregarFiguraCanvas(payload.tipo, dropX, dropY);
                    } else if (payload.categoria === 'elemento') {
                        agregarElementoCanvas(payload.tipo, payload.customData || null, dropX, dropY);
                    }
                } catch(err) {
                    console.error('Error al procesar drop en canvas:', err);
                }
            });

            canvasOuter.addEventListener('wheel', (e) => {
                e.preventDefault();
                const delta = e.deltaY < 0 ? 0.12 : -0.12;
                cambiarZoom(delta);
            }, { passive: false });

            // PAN / ARRASTRAR O SELECCIONAR CON CLIC DERECHO
            canvasOuter.addEventListener('mousedown', (e) => {
                if (e.button === 2) { // Clic derecho para Selección por Arrastre (Marquee)
                    isRightClickSelecting = true;
                    rightSelectStartX = e.clientX;
                    rightSelectStartY = e.clientY;

                    const container = document.getElementById('contenedorNodosCanvas');
                    if (container) {
                        const rect = container.getBoundingClientRect();
                        const startLocalX = Math.round((e.clientX - rect.left) / zoomLevel);
                        const startLocalY = Math.round((e.clientY - rect.top) / zoomLevel);

                        if (!e.shiftKey && !e.ctrlKey) {
                            elementosSeleccionados = [];
                            elementoSeleccionado = null;
                        }

                        let selectBox = document.getElementById('rightSelectBox');
                        if (!selectBox) {
                            selectBox = document.createElement('div');
                            selectBox.id = 'rightSelectBox';
                            selectBox.style.position = 'absolute';
                            selectBox.style.border = '2px dashed #f59e0b';
                            selectBox.style.background = 'rgba(245, 158, 11, 0.2)';
                            selectBox.style.borderRadius = '4px';
                            selectBox.style.pointerEvents = 'none';
                            selectBox.style.zIndex = '9999';
                            selectBox.style.boxShadow = '0 0 12px rgba(245, 158, 11, 0.4)';
                            container.appendChild(selectBox);
                        }
                        selectBox.style.display = 'none';
                        selectBox.style.left = startLocalX + 'px';
                        selectBox.style.top = startLocalY + 'px';
                        selectBox.style.width = '0px';
                        selectBox.style.height = '0px';
                    }
                    return;
                }

                if (e.button === 0) { // Clic Izquierdo para Paneo / Arrastre
                    panHasMoved = false;
                    isPanningCanvas = true;
                    panStartX = e.clientX;
                    panStartY = e.clientY;
                    panScrollLeft = canvasOuter.scrollLeft;
                    panScrollTop = canvasOuter.scrollTop;
                    canvasOuter.style.cursor = 'grabbing';

                    if (!esModoLectura) {
                        const validTargets = ['canvasOuter', 'canvasViewport', 'imgFondoPlano', 'contenedorNodosCanvas'];
                        if (validTargets.includes(e.target.id)) {
                            deseleccionarElemento();
                        }
                    }
                }
            });

            // CLIC DERECHO EN ÁREA LIBRE DEL PLANO / CANVA
            canvasOuter.addEventListener('contextmenu', (e) => {
                const validTargets = ['canvasOuter', 'canvasViewport', 'imgFondoPlano', 'contenedorNodosCanvas'];
                if (validTargets.includes(e.target.id)) {
                    e.preventDefault();
                    clickCtxEvent = e;
                    elementoCtxActivo = null;

                    const ctxMenu = document.getElementById('customCanvasContextMenu');
                    const header = document.getElementById('ctxMenuHeader');
                    if (header) {
                        header.innerHTML = `<i class="bi bi-aspect-ratio text-info me-1"></i> Opciones de Lienzo`;
                    }

                    const optSel = document.getElementById('ctxOptSeleccionar');
                    const optSelTodo = document.getElementById('ctxOptSeleccionarTodo');
                    const optCop = document.getElementById('ctxOptCopiar');
                    const optPeg = document.getElementById('ctxOptPegar');
                    const optDup = document.getElementById('ctxOptDuplicar');
                    const optFic = document.getElementById('ctxOptFicha');
                    const optCen = document.getElementById('ctxOptCentrar');
                    const optVer = document.getElementById('ctxOptVertice');
                    const optMod = document.getElementById('ctxOptModificar');
                    const optDel = document.getElementById('ctxOptEliminar');

                    if (optSel) optSel.style.display = 'none';
                    if (optSelTodo) optSelTodo.style.display = 'block';
                    if (optCop) optCop.style.display = 'none';
                    if (optPeg) optPeg.style.display = portapapelesElemento ? 'block' : 'none';
                    if (optDup) optDup.style.display = 'none';
                    if (optFic) optFic.style.display = 'none';
                    if (optCen) optCen.style.display = 'none';
                    if (optVer) optVer.style.display = 'none';
                    if (optMod) optMod.style.display = 'none';
                    if (optDel) optDel.style.display = 'none';

                    if (ctxMenu) {
                        ctxMenu.style.left = e.clientX + 'px';
                        ctxMenu.style.top = e.clientY + 'px';
                        ctxMenu.style.display = 'block';
                    }
                }
            });

            canvasOuter.addEventListener('scroll', () => {
                renderizarMinimapa();
            });
        }

        if (minimap) {
            minimap.addEventListener('click', (e) => {
                const rect = minimap.getBoundingClientRect();
                const clickX = e.clientX - rect.left;
                const clickY = e.clientY - rect.top;
                
                const scaleX = 2400 / minimap.width;
                const scaleY = 1800 / minimap.height;
                
                const targetCanvasX = clickX * scaleX;
                const targetCanvasY = clickY * scaleY;
                
                if (canvasOuter) {
                    canvasOuter.scrollTo({
                        left: Math.max(0, (targetCanvasX * zoomLevel) - canvasOuter.clientWidth / 2),
                        top: Math.max(0, (targetCanvasY * zoomLevel) - canvasOuter.clientHeight / 2),
                        behavior: 'smooth'
                    });
                }
            });
        }
    });

    function renderizarNodosCanvas() {
        const container = document.getElementById('contenedorNodosCanvas');
        if (!container) return;
        container.innerHTML = '';

        elementosCanvas.forEach(el => {
            const cat = obtenerCategoriaDeElemento(el);
            if (categoriasVisibles[cat] === false) return;

            // SI ES FIGURA VECTORIAL CON VÉRTICES (CUADRADO, RECTÁNGULO, POLÍGONO L/T, TRIÁNGULO)
            if (el.vertices && el.vertices.length > 0) {
                const isSel = !esModoLectura && isElementoSeleccionado(el.id);
                const pts = el.vertices;
                
                // Calcular caja envolvente (bounding box)
                let minX = Math.min(...pts.map(p => p.x));
                let minY = Math.min(...pts.map(p => p.y));
                let maxX = Math.max(...pts.map(p => p.x));
                let maxY = Math.max(...pts.map(p => p.y));
                
                const w = Math.max(20, maxX - minX);
                const h = Math.max(20, maxY - minY);

                const polyWrap = document.createElement('div');
                polyWrap.className = 'canvas-shape-item ' + (isSel ? 'selected-shape' : '');
                polyWrap.style.left = (el.x + minX) + 'px';
                polyWrap.style.top = (el.y + minY) + 'px';
                polyWrap.style.width = w + 'px';
                polyWrap.style.height = h + 'px';
                polyWrap.style.transform = `rotate(${el.angulo || 0}deg)`;
                if (el.opacidad !== undefined) polyWrap.style.opacity = el.opacidad;
                if (el.oculto) polyWrap.style.display = 'none';
                polyWrap.style.cursor = el.bloqueado ? 'not-allowed' : (esModoLectura ? 'pointer' : 'move');

                // Render SVG interno para la Figura Vectorial con soporte de colores de pared por segmento
                const pointsStr = pts.map(p => `${p.x - minX},${p.y - minY}`).join(' ');
                let linesSvg = '';
                pts.forEach((pt, idx) => {
                    const nextPt = pts[(idx + 1) % pts.length];
                    const segColor = (el.coloresParedes && el.coloresParedes[idx]) ? el.coloresParedes[idx] : (el.borderColor || '#3b82f6');
                    linesSvg += `<line x1="${pt.x - minX}" y1="${pt.y - minY}" x2="${nextPt.x - minX}" y2="${nextPt.y - minY}" stroke="${segColor}" stroke-width="${el.borderWidth || 2}" />`;
                });

                polyWrap.innerHTML = `
                    <svg width="${w}" height="${h}" style="overflow:visible; position:absolute; top:0; left:0; pointer-events:all;">
                        <polygon points="${pointsStr}" fill="${el.color || 'rgba(37, 99, 235, 0.25)'}" stroke="transparent" style="cursor:${esModoLectura ? 'pointer' : 'move'};" />
                        ${linesSvg}
                    </svg>
                `;

                // Clic para Abrir Ficha Técnica en Modo Lectura
                polyWrap.addEventListener('click', (e) => {
                    if (esModoLectura) {
                        e.stopPropagation();
                        if (!panHasMoved) {
                            mostrarFichaTecnicaModal(el);
                        }
                    }
                });

                // Doble Clic para Abrir Ficha Técnica
                polyWrap.addEventListener('dblclick', (e) => {
                    e.stopPropagation();
                    mostrarFichaTecnicaModal(el);
                });

                if (isSel) {
                    // 1. PUNTOS VÉRTICE DE DOBLAJE (Puntos para doblar/estirar esquinas)
                    pts.forEach((pt, idx) => {
                        const vDot = document.createElement('div');
                        vDot.className = 'vertex-point';
                        vDot.style.left = (pt.x - minX) + 'px';
                        vDot.style.top = (pt.y - minY) + 'px';
                        vDot.title = `Punto de Doblez #${idx + 1} (Arrastra para deformar esquina | Doble clic para eliminar)`;

                        vDot.addEventListener('mousedown', (e) => {
                            if (el.bloqueado) return;
                            e.stopPropagation();
                            seleccionarElemento(el);
                            draggingVertex = { shape: el, vertex: pt, idx: idx, baseMinX: minX, baseMinY: minY };
                        });

                        vDot.addEventListener('dblclick', (e) => {
                            e.stopPropagation();
                            if (pts.length > 3) {
                                pts.splice(idx, 1);
                                renderizarNodosCanvas();
                            } else {
                                alert('Un área debe mantener al menos 3 puntos de doblez.');
                            }
                        });

                        polyWrap.appendChild(vDot);
                    });

                    // 2. CONTORNO Y HANDLES PERIMETRALES (Puntos en el borde para hacer más grande/pequeño)
                    ['nw', 'ne', 'se', 'sw', 'n', 'e', 's', 'w'].forEach(pos => {
                        const hDot = document.createElement('div');
                        hDot.className = `resize-handle handle-${pos}`;
                        hDot.title = "Arrastra desde el contorno para hacer más grande o más pequeño";
                        hDot.addEventListener('mousedown', (e) => {
                            if (el.bloqueado) return;
                            e.stopPropagation();
                            seleccionarElemento(el);
                            resizingShape = {
                                shape: el,
                                handle: pos,
                                startX: e.clientX,
                                startY: e.clientY,
                                startW: w,
                                startH: h,
                                initialVertices: pts.map(p => ({ x: p.x, y: p.y }))
                            };
                        });
                        polyWrap.appendChild(hDot);
                    });

                    // Clic sobre la figura para añadir nuevo punto de doblez
                    const svgPoly = polyWrap.querySelector('polygon');
                    if (svgPoly) {
                        svgPoly.addEventListener('click', (e) => {
                            if (e.target.classList.contains('vertex-point') || e.target.classList.contains('resize-handle')) return;
                            const container = document.getElementById('contenedorNodosCanvas');
                            if (!container) return;
                            const rect = container.getBoundingClientRect();
                            const clickLocalX = Math.round((e.clientX - rect.left) / zoomLevel - el.x);
                            const clickLocalY = Math.round((e.clientY - rect.top) / zoomLevel - el.y);
                            
                            // Insertar nuevo punto de doblez
                            pts.push({ x: clickLocalX, y: clickLocalY });
                            renderizarNodosCanvas();
                        });
                    }
                }

                polyWrap.addEventListener('mousedown', (e) => {
                    if (esModoLectura) return; // Permite paneo al arrastrar en modo lectura
                    if (e.button === 2) return;
                    e.stopPropagation();
                    seleccionarElemento(el);
                    if (el.bloqueado) return;
                    draggingElement = el;
                    const container = document.getElementById('contenedorNodosCanvas');
                    const rect = container.getBoundingClientRect();
                    dragOffsetX = (e.clientX - rect.left) / zoomLevel - el.x;
                    dragOffsetY = (e.clientY - rect.top) / zoomLevel - el.y;
                });

                // CLIC DERECHO: MENÚ CONTEXTUAL (VER FICHA TÉCNICA, ELIMINAR, DUPLICAR, DOBLEZ)
                polyWrap.addEventListener('contextmenu', (e) => {
                    abrirCustomContextMenu(e, el);
                });

                container.appendChild(polyWrap);
                return;
            }

            // SI ES FIGURA GEOMÉTRICA SIMPLE (CÍRCULO, PARED LINEAL)
            if (['circle', 'line'].includes(el.tipo)) {
                const isSel = !esModoLectura && isElementoSeleccionado(el.id);
                const shapeWrap = document.createElement('div');
                shapeWrap.className = 'canvas-shape-item ' + (isSel ? 'selected-shape' : '');
                shapeWrap.style.left = el.x + 'px';
                shapeWrap.style.top = el.y + 'px';
                shapeWrap.style.width = (el.width || 120) + 'px';
                shapeWrap.style.height = (el.height || 80) + 'px';
                shapeWrap.style.transform = `translate(-50%, -50%) rotate(${el.angulo || 0}deg)`;
                if (el.opacidad !== undefined) shapeWrap.style.opacity = el.opacidad;
                if (el.oculto) shapeWrap.style.display = 'none';
                shapeWrap.title = `${el.label || 'Zona'} (Clic derecho para opciones)`;
                shapeWrap.style.cursor = el.bloqueado ? 'not-allowed' : (esModoLectura ? 'pointer' : 'move');

                if (el.tipo === 'circle') {
                    shapeWrap.style.background = el.color || 'rgba(34, 197, 94, 0.25)';
                    shapeWrap.style.border = `${el.borderWidth || 2}px solid ${el.borderColor || '#22c55e'}`;
                    shapeWrap.style.borderRadius = '50%';
                } else if (el.tipo === 'line') {
                    shapeWrap.style.height = (el.borderWidth || 4) + 'px';
                    shapeWrap.style.background = el.borderColor || '#38bdf8';
                    shapeWrap.style.border = 'none';
                }

                // Clic para abrir Ficha Técnica en Modo Lectura
                shapeWrap.addEventListener('click', (e) => {
                    if (esModoLectura) {
                        e.stopPropagation();
                        if (!panHasMoved) {
                            mostrarFichaTecnicaModal(el);
                        }
                    }
                });

                // Doble clic para abrir Ficha Técnica
                shapeWrap.addEventListener('dblclick', (e) => {
                    e.stopPropagation();
                    mostrarFichaTecnicaModal(el);
                });

                // Clic derecho para Menú Contextual
                shapeWrap.addEventListener('contextmenu', (e) => {
                    abrirCustomContextMenu(e, el);
                });

                // SI ESTÁ SELECCIONADO, AGREGAR PUNTOS CONTORNO DE RESIZE (RESIZE HANDLES)
                if (isSel) {
                    ['nw', 'ne', 'se', 'sw', 'n', 'e', 's', 'w'].forEach(pos => {
                        const h = document.createElement('div');
                        h.className = `resize-handle handle-${pos}`;
                        h.title = "Arrastra desde el contorno para hacer más grande o pequeño";
                        h.addEventListener('mousedown', (e) => {
                            if (esModoLectura || el.bloqueado) return;
                            e.stopPropagation();
                            seleccionarElemento(el);
                            resizingShape = { shape: el, handle: pos, startX: e.clientX, startY: e.clientY, startW: (el.width || 120), startH: (el.height || 80) };
                        });
                        shapeWrap.appendChild(h);
                    });
                }

                shapeWrap.addEventListener('mousedown', (e) => {
                    if (esModoLectura) return;
                    e.stopPropagation();
                    seleccionarElemento(el);
                    if (el.bloqueado) return;
                    draggingElement = el;
                    const container = document.getElementById('contenedorNodosCanvas');
                    const rect = container.getBoundingClientRect();
                    dragOffsetX = (e.clientX - rect.left) / zoomLevel - el.x;
                    dragOffsetY = (e.clientY - rect.top) / zoomLevel - el.y;
                });

                container.appendChild(shapeWrap);
                return;
            }

            // SI ES COMPONENTE ARQUITECTÓNICO / MOBILIARIO CAD
            if (['escalera_recta', 'escalera_l', 'escalera_espiral', 'puerta', 'escritorio', 'carro', 'sofa', 'comedor', 'cama', 'bano', 'planta', 'ventana'].includes(el.tipo)) {
                const isSel = !esModoLectura && isElementoSeleccionado(el.id);
                const w = el.width || 100;
                const h = el.height || 80;

                const archWrap = document.createElement('div');
                archWrap.className = 'canvas-shape-item ' + (isSel ? 'selected-shape' : '');
                archWrap.style.left = el.x + 'px';
                archWrap.style.top = el.y + 'px';
                archWrap.style.width = w + 'px';
                archWrap.style.height = h + 'px';
                archWrap.style.transform = `translate(-50%, -50%) rotate(${el.angulo || 0}deg)`;
                if (el.opacidad !== undefined) archWrap.style.opacity = el.opacidad;
                if (el.oculto) archWrap.style.display = 'none';
                archWrap.title = `${el.label || 'Mobiliario CAD'} (Doble clic para Ficha Técnica)`;
                archWrap.style.cursor = el.bloqueado ? 'not-allowed' : (esModoLectura ? 'pointer' : 'move');

                let innerSvgHtml = '';
                if (el.tipo === 'escalera_recta') {
                    let stepLines = '';
                    for (let i = 1; i < 7; i++) {
                        stepLines += `<line x1="0" y1="${h * i / 7}" x2="${w}" y2="${h * i / 7}" stroke="#e2e8f0" stroke-width="1.5" />`;
                    }
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect width="${w}" height="${h}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" rx="4" />
                            ${stepLines}
                            <path d="M ${w/2} ${h*0.85} L ${w/2} ${h*0.15} M ${w/2-6} ${h*0.3} L ${w/2} ${h*0.15} L ${w/2+6} ${h*0.3}" stroke="#64748b" stroke-width="2" fill="none" />
                        </svg>
                    `;
                } else if (el.tipo === 'escalera_l') {
                    let stepLines = '';
                    for (let i = 1; i < 5; i++) {
                        stepLines += `<line x1="0" y1="${(h*0.5) * i / 5}" x2="${w}" y2="${(h*0.5) * i / 5}" stroke="#e2e8f0" stroke-width="1.5" />`;
                        stepLines += `<line x1="${(w*0.5) * i / 5}" y1="${h*0.5}" x2="${(w*0.5) * i / 5}" y2="${h}" stroke="#e2e8f0" stroke-width="1.5" />`;
                    }
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <polygon points="0,0 ${w},0 ${w},${h*0.5} ${w*0.5},${h*0.5} ${w*0.5},${h} 0,${h}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" />
                            ${stepLines}
                            <path d="M ${w*0.25} ${h*0.4} L ${w*0.25} ${h*0.75}" stroke="#64748b" stroke-width="2" fill="none" />
                        </svg>
                    `;
                } else if (el.tipo === 'escalera_espiral') {
                    let spokes = '';
                    const cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 2;
                    for (let a = 0; a < 360; a += 30) {
                        const rad = a * Math.PI / 180;
                        const x2 = cx + r * Math.cos(rad);
                        const y2 = cy + r * Math.sin(rad);
                        spokes += `<line x1="${cx}" y1="${cy}" x2="${x2}" y2="${y2}" stroke="#cbd5e1" stroke-width="1.5" />`;
                    }
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <circle cx="${cx}" cy="${cy}" r="${r}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" />
                            ${spokes}
                            <circle cx="${cx}" cy="${cy}" r="6" fill="#64748b" />
                        </svg>
                    `;
                } else if (el.tipo === 'puerta') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <line x1="0" y1="0" x2="0" y2="${h}" stroke="#94a3b8" stroke-width="4" />
                            <line x1="0" y1="${h}" x2="${w}" y2="${h}" stroke="#94a3b8" stroke-width="2" />
                            <path d="M 0,0 A ${w},${h} 0 0,1 ${w},${h}" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-dasharray="4,4" />
                        </svg>
                    `;
                } else if (el.tipo === 'escritorio') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect width="${w}" height="${h*0.7}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" rx="5" />
                            <rect x="${w*0.3}" y="${h*0.1}" width="${w*0.4}" height="${h*0.2}" fill="#cbd5e1" rx="2" />
                            <circle cx="${w/2}" cy="${h*0.82}" r="${Math.min(w,h)*0.16}" fill="rgba(148, 163, 184, 0.2)" stroke="#94a3b8" stroke-width="1.5" />
                        </svg>
                    `;
                } else if (el.tipo === 'carro') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect x="${w*0.08}" y="${h*0.04}" width="${w*0.84}" height="${h*0.92}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" rx="${w*0.2}" />
                            <path d="M ${w*0.2} ${h*0.25} Q ${w*0.5} ${h*0.2} ${w*0.8} ${h*0.25} L ${w*0.75} ${h*0.38} Q ${w*0.5} ${h*0.35} ${w*0.25} ${h*0.38} Z" fill="none" stroke="#94a3b8" stroke-width="1.5" />
                            <path d="M ${w*0.22} ${h*0.75} Q ${w*0.5} ${h*0.72} ${w*0.78} ${h*0.75} L ${w*0.73} ${h*0.85} Q ${w*0.5} ${h*0.83} ${w*0.27} ${h*0.85} Z" fill="none" stroke="#94a3b8" stroke-width="1.5" />
                            <line x1="${w*0.25}" y1="${h*0.38}" x2="${w*0.27}" y2="${h*0.75}" stroke="#94a3b8" stroke-width="1.5" />
                            <line x1="${w*0.75}" y1="${h*0.38}" x2="${w*0.73}" y2="${h*0.75}" stroke="#94a3b8" stroke-width="1.5" />
                            <rect x="${w*0.02}" y="${h*0.12}" width="${w*0.08}" height="${h*0.18}" fill="#94a3b8" rx="2" />
                            <rect x="${w*0.9}" y="${h*0.12}" width="${w*0.08}" height="${h*0.18}" fill="#94a3b8" rx="2" />
                            <rect x="${w*0.02}" y="${h*0.7}" width="${w*0.08}" height="${h*0.18}" fill="#94a3b8" rx="2" />
                            <rect x="${w*0.9}" y="${h*0.7}" width="${w*0.08}" height="${h*0.18}" fill="#94a3b8" rx="2" />
                        </svg>
                    `;
                } else if (el.tipo === 'sofa') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect width="${w}" height="${h}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" rx="8" />
                            <rect x="${w*0.05}" y="${h*0.05}" width="${w*0.9}" height="${h*0.25}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="4" />
                            <rect x="${w*0.05}" y="${h*0.3}" width="${w*0.18}" height="${h*0.65}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="3" />
                            <rect x="${w*0.77}" y="${h*0.3}" width="${w*0.18}" height="${h*0.65}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="3" />
                            <rect x="${w*0.26}" y="${h*0.33}" width="${w*0.23}" height="${h*0.6}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="4" />
                            <rect x="${w*0.51}" y="${h*0.33}" width="${w*0.23}" height="${h*0.6}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="4" />
                        </svg>
                    `;
                } else if (el.tipo === 'comedor') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect x="${w*0.2}" y="${h*0.2}" width="${w*0.6}" height="${h*0.6}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" rx="6" />
                            <rect x="${w*0.28}" y="${h*0.04}" width="${w*0.18}" height="${h*0.13}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="2" />
                            <rect x="${w*0.54}" y="${h*0.04}" width="${w*0.18}" height="${h*0.13}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="2" />
                            <rect x="${w*0.28}" y="${h*0.83}" width="${w*0.18}" height="${h*0.13}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="2" />
                            <rect x="${w*0.54}" y="${h*0.83}" width="${w*0.18}" height="${h*0.13}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="2" />
                            <rect x="${w*0.04}" y="${h*0.38}" width="${w*0.13}" height="${h*0.24}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="2" />
                            <rect x="${w*0.83}" y="${h*0.38}" width="${w*0.13}" height="${h*0.24}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="2" />
                        </svg>
                    `;
                } else if (el.tipo === 'cama') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect width="${w}" height="${h}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" rx="6" />
                            <rect x="${w*0.02}" y="${h*0.02}" width="${w*0.96}" height="${h*0.08}" fill="#94a3b8" rx="2" />
                            <rect x="${w*0.08}" y="${h*0.13}" width="${w*0.38}" height="${h*0.22}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="4" />
                            <rect x="${w*0.54}" y="${h*0.13}" width="${w*0.38}" height="${h*0.22}" fill="none" stroke="#94a3b8" stroke-width="1.5" rx="4" />
                            <line x1="${w*0.05}" y1="${h*0.42}" x2="${w*0.95}" y2="${h*0.42}" stroke="#94a3b8" stroke-width="1.5" />
                        </svg>
                    `;
                } else if (el.tipo === 'bano') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect x="${w*0.1}" y="${h*0.05}" width="${w*0.8}" height="${h*0.25}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" rx="3" />
                            <ellipse cx="${w*0.5}" cy="${h*0.62}" rx="${w*0.38}" ry="${h*0.32}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" />
                            <ellipse cx="${w*0.5}" cy="${h*0.62}" rx="${w*0.25}" ry="${h*0.2}" fill="none" stroke="#94a3b8" stroke-width="1.5" />
                        </svg>
                    `;
                } else if (el.tipo === 'planta') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <circle cx="${w/2}" cy="${h/2}" r="${Math.min(w,h)*0.25}" fill="rgba(148, 163, 184, 0.12)" stroke="#94a3b8" stroke-width="1.5" />
                            <path d="M ${w/2} ${h/2} Q ${w*0.1} ${h*0.2} ${w*0.05} ${h*0.4} M ${w/2} ${h/2} Q ${w*0.9} ${h*0.2} ${w*0.95} ${h*0.4} M ${w/2} ${h/2} Q ${w*0.1} ${h*0.8} ${w*0.05} ${h*0.6} M ${w/2} ${h/2} Q ${w*0.9} ${h*0.8} ${w*0.95} ${h*0.6} M ${w/2} ${h/2} Q ${w*0.5} ${h*0.05} ${w*0.5} ${h*0.02} M ${w/2} ${h/2} Q ${w*0.5} ${h*0.95} ${w*0.5} ${h*0.98}" stroke="#94a3b8" stroke-width="1.5" fill="none" />
                        </svg>
                    `;
                } else if (el.tipo === 'ventana') {
                    innerSvgHtml = `
                        <svg width="${w}" height="${h}" style="position:absolute; top:0; left:0; overflow:visible;">
                            <rect x="0" y="0" width="${w*0.08}" height="${h}" fill="#94a3b8" />
                            <rect x="${w*0.92}" y="0" width="${w*0.08}" height="${h}" fill="#94a3b8" />
                            <line x1="${w*0.08}" y1="${h*0.35}" x2="${w*0.92}" y2="${h*0.35}" stroke="#94a3b8" stroke-width="1.5" />
                            <line x1="${w*0.08}" y1="${h*0.65}" x2="${w*0.92}" y2="${h*0.65}" stroke="#94a3b8" stroke-width="1.5" />
                        </svg>
                    `;
                }

                archWrap.innerHTML = innerSvgHtml;

                // Clic para Ficha Técnica
                archWrap.addEventListener('click', (e) => {
                    if (esModoLectura) {
                        e.stopPropagation();
                        if (!panHasMoved) {
                            mostrarFichaTecnicaModal(el);
                        }
                    }
                });

                // Doble clic para Ficha Técnica
                archWrap.addEventListener('dblclick', (e) => {
                    e.stopPropagation();
                    mostrarFichaTecnicaModal(el);
                });

                // Clic derecho para Menú Contextual
                archWrap.addEventListener('contextmenu', (e) => {
                    abrirCustomContextMenu(e, el);
                });

                if (isSel) {
                    ['nw', 'ne', 'se', 'sw', 'n', 'e', 's', 'w'].forEach(pos => {
                        const hDot = document.createElement('div');
                        hDot.className = `resize-handle handle-${pos}`;
                        hDot.title = "Arrastra desde el contorno para escalar";
                        hDot.addEventListener('mousedown', (e) => {
                            if (esModoLectura || el.bloqueado) return;
                            e.stopPropagation();
                            seleccionarElemento(el);
                            resizingShape = { shape: el, handle: pos, startX: e.clientX, startY: e.clientY, startW: w, startH: h };
                        });
                        archWrap.appendChild(hDot);
                    });
                }

                archWrap.addEventListener('mousedown', (e) => {
                    if (esModoLectura) return;
                    if (e.button === 2) return;
                    e.stopPropagation();
                    seleccionarElemento(el);
                    if (el.bloqueado) return;
                    draggingElement = el;
                    const container = document.getElementById('contenedorNodosCanvas');
                    const rect = container.getBoundingClientRect();
                    dragOffsetX = (e.clientX - rect.left) / zoomLevel - el.x;
                    dragOffsetY = (e.clientY - rect.top) / zoomLevel - el.y;
                });

                container.appendChild(archWrap);
                return;
            }

            // SI ES PIN / ELEMENTO DE RED O INVENTARIO (NODO, PC, AP, CÁMARA, SITE)
            const isSel = !esModoLectura && isElementoSeleccionado(el.id);
            const w = el.width || 65;
            const h = el.height || 65;

            const nodeWrap = document.createElement('div');
            nodeWrap.className = 'canvas-node-item ' + (isSel ? 'selected-shape' : '');
            nodeWrap.style.left = el.x + 'px';
            nodeWrap.style.top = el.y + 'px';
            nodeWrap.style.width = w + 'px';
            nodeWrap.style.height = h + 'px';
            const esDispositivoRed = ['nodo', 'pc', 'ap', 'camara', 'site', 'impresora', 'telefono', 'pantalla', 'equipo_inv'].includes(el.tipo);
            
            // En la vista 2D, los íconos de equipos y sus nombres siempre permanecen derechos (horizontal 0°) para máxima legibilidad
            const rot2D = esDispositivoRed ? 0 : (el.angulo || 0);
            nodeWrap.style.transform = `translate(-50%, -50%) rotate(${rot2D}deg)`;
            
            if (esDispositivoRed) {
                nodeWrap.style.opacity = 1.0;
            } else if (el.opacidad !== undefined) {
                nodeWrap.style.opacity = el.opacidad;
            }
            nodeWrap.setAttribute('data-id', el.id);
            if (el.oculto) nodeWrap.style.display = 'none';
            nodeWrap.title = `${el.label || 'Componente'} ${el.ip ? '(' + el.ip + ')' : ''} (Clic derecho para opciones)`;
            nodeWrap.style.cursor = el.bloqueado ? 'not-allowed' : (esModoLectura ? 'pointer' : 'move');

            // Flecha indicadora de dirección de ángulo para cámaras CCTV en 2D
            if (el.tipo === 'camara' && el.angulo) {
                const dirArrow = document.createElement('div');
                dirArrow.style.position = 'absolute';
                dirArrow.style.top = '50%';
                dirArrow.style.left = '50%';
                dirArrow.style.width = '0';
                dirArrow.style.height = '0';
                dirArrow.style.transform = `translate(-50%, -50%) rotate(${el.angulo || 0}deg)`;
                dirArrow.style.pointerEvents = 'none';
                dirArrow.style.zIndex = '1';
                dirArrow.innerHTML = `<i class="bi bi-caret-up-fill text-warning" style="font-size: 1.1rem; display: block; transform: translateY(-24px);"></i>`;
                nodeWrap.appendChild(dirArrow);
            }

            // Círculo de cobertura para AP Wi-Fi
            if (el.tipo === 'ap') {
                const circle = document.createElement('div');
                circle.className = 'wifi-radius-circle';
                circle.style.width = (el.radio * 2) + 'px';
                circle.style.height = (el.radio * 2) + 'px';
                nodeWrap.appendChild(circle);
            }

            // Icono Pin Principal (Escalable según w/h, siempre vertical)
            const pinSize = Math.min(w, h);
            const pin = document.createElement('div');
            pin.className = 'node-pin ' + (isSel ? 'selected' : '');
            pin.style.background = el.color;
            pin.style.width = pinSize + 'px';
            pin.style.height = pinSize + 'px';
            pin.style.fontSize = (pinSize * 0.45) + 'px';
            if (el.imagenUrl) {
                pin.style.backgroundImage = `url('${el.imagenUrl}')`;
                pin.style.backgroundSize = 'cover';
                pin.style.backgroundPosition = 'center';
                pin.style.borderRadius = '8px';
                pin.innerHTML = '';
            } else {
                pin.innerHTML = `<i class="bi ${el.icon} text-white"></i>`;
            }
            nodeWrap.appendChild(pin);

            // Etiqueta Identificadora con Nombre / Número de Nodo e IP (Siempre horizontal y derecha)
            const labelDiv = document.createElement('div');
            labelDiv.className = 'node-label';
            const textLabel = el.label || (el.tipo ? el.tipo.toUpperCase() : 'COMPONENTE');
            const ipText = el.ip ? ` (${el.ip})` : '';
            labelDiv.textContent = `${textLabel}${ipText}`;
            nodeWrap.appendChild(labelDiv);

            // Clic para Ficha Técnica en Modo Lectura
            nodeWrap.addEventListener('click', (e) => {
                if (esModoLectura) {
                    e.stopPropagation();
                    if (!panHasMoved) {
                        mostrarFichaTecnicaModal(el);
                    }
                }
            });

            // Doble Clic para Ficha Técnica en Nodos / PCs / Cámaras / APs / SITE
            nodeWrap.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                mostrarFichaTecnicaModal(el);
            });

            // Clic Derecho para Menú Contextual
            nodeWrap.addEventListener('contextmenu', (e) => {
                abrirCustomContextMenu(e, el);
            });

            // Handles perimetrales para escalar nodos, cámaras, APs y PCs desde el contorno
            if (isSel) {
                ['nw', 'ne', 'se', 'sw', 'n', 'e', 's', 'w'].forEach(pos => {
                    const hDot = document.createElement('div');
                    hDot.className = `resize-handle handle-${pos}`;
                    hDot.title = "Arrastra desde el contorno para cambiar tamaño";
                    hDot.addEventListener('mousedown', (e) => {
                        if (esModoLectura || el.bloqueado) return;
                        e.stopPropagation();
                        seleccionarElemento(el);
                        resizingShape = { shape: el, handle: pos, startX: e.clientX, startY: e.clientY, startW: w, startH: h };
                    });
                    nodeWrap.appendChild(hDot);
                });
            }

            // Eventos Arrastrar (Drag & Drop) y Selección
            nodeWrap.addEventListener('mousedown', (e) => {
                if (esModoLectura) return;
                e.stopPropagation();
                seleccionarElemento(el);
                if (el.bloqueado) return;
                draggingElement = el;
                const container = document.getElementById('contenedorNodosCanvas');
                const rect = container.getBoundingClientRect();
                dragOffsetX = (e.clientX - rect.left) / zoomLevel - el.x;
                dragOffsetY = (e.clientY - rect.top) / zoomLevel - el.y;
            });

            container.appendChild(nodeWrap);
        });
        renderizarMinimapa();
        renderizarListaElementosSidebar();
    }

    function renderizarListaElementosSidebar() {
        const listContainer = document.getElementById('contenedorListaElementosCanvas');
        const countSpan = document.getElementById('countElementosLista');
        if (!listContainer) return;

        if (countSpan) {
            countSpan.textContent = elementosCanvas.length;
        }

        if (elementosCanvas.length === 0) {
            listContainer.innerHTML = '<div class="text-center text-secondary p-3 small opacity-75">No hay componentes en el plano.</div>';
            return;
        }

        listContainer.innerHTML = '';
        elementosCanvas.forEach((el, index) => {
            const isSel = isElementoSeleccionado(el.id);
            const isLocked = !!el.bloqueado;
            const isHidden = !!el.oculto;

            const item = document.createElement('div');
            item.id = 'item_comp_' + el.id;
            item.className = `list-group-item bg-dark border-secondary border-opacity-25 text-white d-flex align-items-center justify-content-between py-1.5 px-2 rounded-2 mb-1 ${isSel ? 'border-warning border-2' : ''}`;
            item.style.cursor = 'pointer';
            if (isSel) {
                item.style.background = 'rgba(245, 158, 11, 0.15)';
            }

            const iconClass = el.icon || (['rect','polygon','triangle','circle','line'].includes(el.tipo) ? 'bi-bounding-box-circles' : 'bi-display-fill');
            const colorStyle = (el.color && el.color.startsWith('#')) ? el.color : '#38bdf8';

            const lockIcon = isLocked ? 'bi-lock-fill text-warning' : 'bi-unlock text-secondary';
            const visIcon = isHidden ? 'bi-eye-slash-fill text-danger' : 'bi-eye text-secondary';

            item.innerHTML = `
                <div class="d-flex align-items-center gap-2 overflow-hidden me-1" onclick="seleccionarElementoDesdeLista('${el.id}')" style="flex: 1; min-width: 0;">
                    <span class="badge p-1 rounded-2 flex-shrink-0" style="background:${colorStyle}33; color:${colorStyle}; font-size: 0.85rem;">
                        <i class="bi ${iconClass}"></i>
                    </span>
                    <div class="text-truncate">
                        <div class="fw-semibold text-white small leading-tight text-truncate" style="font-size: 0.78rem;">
                            ${escapeHtml(el.label || 'Componente #' + (index + 1))}
                            ${isLocked ? '<i class="bi bi-lock-fill text-warning ms-1" style="font-size:0.65rem;" title="Bloqueado"></i>' : ''}
                            ${isHidden ? '<i class="bi bi-eye-slash-fill text-danger ms-1" style="font-size:0.65rem;" title="Oculto"></i>' : ''}
                        </div>
                        <div class="text-secondary font-monospace text-truncate" style="font-size: 0.65rem;">${el.tipo} (X:${el.x}, Y:${el.y})</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary p-0 px-1" onclick="event.stopPropagation(); alternarBloqueoElementoPorId('${el.id}')" title="${isLocked ? 'Desbloquear componente' : 'Bloquear componente (no movible)'}">
                        <i class="bi ${lockIcon}" style="font-size: 0.75rem;"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary p-0 px-1" onclick="event.stopPropagation(); alternarVisibilidadElementoPorId('${el.id}')" title="${isHidden ? 'Mostrar componente' : 'Ocultar componente'}">
                        <i class="bi ${visIcon}" style="font-size: 0.75rem;"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info p-0 px-1" onclick="event.stopPropagation(); centrarVistaEnElementoPorId('${el.id}')" title="Centrar vista en el plano">
                        <i class="bi bi-crosshair" style="font-size: 0.75rem;"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1" onclick="event.stopPropagation(); eliminarElementoPorId('${el.id}')" title="Eliminar este componente">
                        <i class="bi bi-trash-fill" style="font-size: 0.75rem;"></i>
                    </button>
                </div>
            `;

            listContainer.appendChild(item);
        });
    }

    function alternarBloqueoElementoPorId(id) {
        const target = elementosCanvas.find(e => e.id === id);
        if (!target) return;
        guardarEstadoHistorial();
        target.bloqueado = !target.bloqueado;
        renderizarNodosCanvas();
        renderizarListaElementosSidebar();
    }

    function alternarVisibilidadElementoPorId(id) {
        const target = elementosCanvas.find(e => e.id === id);
        if (!target) return;
        guardarEstadoHistorial();
        target.oculto = !target.oculto;
        renderizarNodosCanvas();
        renderizarListaElementosSidebar();
        if (typeof renderizarEscena3D === 'function' && scene3D) {
            renderizarEscena3D();
        }
    }

    function seleccionarElementoDesdeLista(id) {
        const target = elementosCanvas.find(e => e.id === id);
        if (target) {
            seleccionarElemento(target, true);
        }
    }

    function centrarVistaEnElementoPorId(id) {
        const target = elementosCanvas.find(e => e.id === id);
        if (target) {
            seleccionarElemento(target, true);
        }
    }

    function eliminarElementoPorId(id) {
        const target = elementosCanvas.find(e => e.id === id);
        if (!target) return;
        guardarEstadoHistorial();
        elementosCanvas = elementosCanvas.filter(e => e.id !== id);
        elementosSeleccionados = elementosSeleccionados.filter(e => e.id !== id);
        if (elementoSeleccionado && elementoSeleccionado.id === id) {
            elementoSeleccionado = null;
            const panel = document.getElementById('panelPropiedades');
            if (panel) panel.classList.add('d-none');
        }
        renderizarNodosCanvas();
    }

    function vaciarTodosLosElementosPlano() {
        if (elementosCanvas.length === 0) return;
        if (confirm('¿Estás seguro de que deseas eliminar TODOS los componentes colocados en el plano?')) {
            guardarEstadoHistorial();
            elementosCanvas = [];
            elementosSeleccionados = [];
            elementoSeleccionado = null;
            const panel = document.getElementById('panelPropiedades');
            if (panel) panel.classList.add('d-none');
            renderizarNodosCanvas();
        }
    }

    // FICHA TÉCNICA FLOTANTE INLINE DENTRO DE VISTA 3D (CLIC DERECHO EN 3D)
    let elementoFicha3DActivo = null;

    function mostrarFichaTecnica3DFlotante(el, mouseEvent) {
        if (!el) return;
        elementoFicha3DActivo = el;

        const overlay = document.getElementById('fichaTecnica3DOverlay');
        const container3D = document.getElementById('canvas3DContainer');
        if (!overlay || !container3D) return;

        const invData = el.inventario_data || (el.inventario_key && window.equiposInventarioMap ? window.equiposInventarioMap[el.inventario_key] : null);

        const nombreEl = document.getElementById('ficha3DNombre');
        if (nombreEl) nombreEl.textContent = invData ? (invData.nombre || el.label) : (el.label || 'Componente 3D');

        const tipoEl = document.getElementById('ficha3DTipo');
        if (tipoEl) tipoEl.textContent = (invData ? (invData.tipo || el.tipo) : (el.tipo || 'elemento')).toUpperCase();

        const iconBox = document.getElementById('ficha3DIconBox');
        const iconEl = document.getElementById('ficha3DIcon');
        const effIcon = (invData && invData.icon) ? invData.icon : (el.icon || (['rect','polygon','triangle','circle','line'].includes(el.tipo) ? 'bi-bounding-box-circles' : 'bi-display-fill'));
        const effColor = (invData && invData.color) ? invData.color : (el.color || '#38bdf8');
        if (iconEl) iconEl.className = `bi ${effIcon}`;
        if (iconBox) {
            iconBox.style.background = effColor + '33';
            iconBox.style.color = effColor;
        }

        const ipEl = document.getElementById('ficha3DIP');
        if (ipEl) ipEl.textContent = (invData && invData.ip && invData.ip !== 'N/A') ? invData.ip : (el.ip || 'No asignada');

        const deptEl = document.getElementById('ficha3DDept');
        if (deptEl) deptEl.textContent = (invData && invData.dept) ? invData.dept : (el.dept || 'General');

        const coordsEl = document.getElementById('ficha3DCoords');
        if (coordsEl) coordsEl.textContent = `X:${Math.round(el.x || 0)} Y:${Math.round(el.y || 0)} Z:${Math.round(el.z || 0)}`;

        const groupInv = document.getElementById('ficha3DGroupInv');
        if (groupInv) {
            if (invData || el.inventario_key) {
                groupInv.style.display = 'block';
                const raw = (invData && invData.raw) ? invData.raw : (invData || {});
                
                const modEl = document.getElementById('ficha3DModulo');
                if (modEl) modEl.textContent = invData ? invData.modulo : 'Inventario';

                const userEl = document.getElementById('ficha3DUsuario');
                if (userEl) {
                    const uVal = invData ? (invData.usuario || raw.usuario || 'N/A') : (el.usuario || 'N/A');
                    if (uVal && uVal !== 'N/A' && uVal !== '--') {
                        const uEsc = String(uVal).replace(/'/g, "\\'").replace(/"/g, '&quot;');
                        userEl.innerHTML = `<a href="javascript:void(0)" onclick="abrirExpedienteUsuario('${uEsc}')" class="text-info fw-bold text-decoration-underline" title="Ver Expediente del Personal"><i class="bi bi-person-badge me-1"></i>${uVal}</a>`;
                    } else {
                        userEl.textContent = 'N/A';
                    }
                }

                const mmEl = document.getElementById('ficha3DMarcaModelo');
                if (mmEl) {
                    const marcaStr = raw.marca || raw.marca_modelo || (invData ? invData.marca : '') || '';
                    const modeloStr = raw.modelo || (invData ? invData.modelo : '') || '';
                    const fullMm = (marcaStr + ' ' + modeloStr).trim();
                    mmEl.textContent = fullMm || (invData ? invData.nombre : 'N/A');
                }

                const serieEl = document.getElementById('ficha3DSerie');
                if (serieEl) {
                    const serStr = raw.serie || raw.numero_serie || (invData ? invData.serie : '') || el.inventario_key || 'N/A';
                    serieEl.textContent = serStr;
                }

                const especsGroup = document.getElementById('ficha3DGroupEspecs');
                const especsEl = document.getElementById('ficha3DEspecs');
                if (especsEl) {
                    let especsParts = [];
                    if (raw.tipo_equipo) especsParts.push('Tipo: ' + raw.tipo_equipo);
                    if (raw.procesador) especsParts.push('CPU: ' + raw.procesador + (raw.ghz ? ' ' + raw.ghz : ''));
                    if (raw.ram) especsParts.push('RAM: ' + raw.ram);
                    if (raw.dd) especsParts.push('Disco: ' + raw.dd);
                    if (raw.sistema_op) especsParts.push('OS: ' + raw.sistema_op);
                    if (raw.subtipo_camara) especsParts.push('Cámara: ' + raw.subtipo_camara);
                    if (raw.dvr_vinculado) especsParts.push('DVR: ' + raw.dvr_vinculado);
                    const especsStr = especsParts.join(' | ');
                    if (especsGroup) especsGroup.style.display = especsStr ? 'block' : 'none';
                    especsEl.textContent = especsStr || 'N/A';
                }

                const estEl = document.getElementById('ficha3DEstado');
                if (estEl) {
                    const est = raw.estatus || raw.estado || (invData ? invData.estado : '') || 'Activo';
                    const ubi = raw.ubicacion || (invData ? invData.ubicacion : '') || '';
                    estEl.textContent = est + (ubi ? ' (' + ubi + ')' : '');
                }
            } else {
                groupInv.style.display = 'none';
            }
        }

        if (mouseEvent) {
            const rect = container3D.getBoundingClientRect();
            const overlayWidth = 330;
            const overlayHeight = 280;

            let posX = mouseEvent.clientX - rect.left + 12;
            let posY = mouseEvent.clientY - rect.top + 12;

            if (posX + overlayWidth > container3D.clientWidth) {
                posX = mouseEvent.clientX - rect.left - overlayWidth - 12;
            }
            if (posY + overlayHeight > container3D.clientHeight) {
                posY = mouseEvent.clientY - rect.top - overlayHeight - 12;
            }

            posX = Math.max(10, Math.min(posX, container3D.clientWidth - overlayWidth - 10));
            posY = Math.max(10, Math.min(posY, container3D.clientHeight - overlayHeight - 10));

            overlay.style.left = posX + 'px';
            overlay.style.top = posY + 'px';
        }

        overlay.style.display = 'block';
    }

    function cerrarFichaTecnica3D() {
        const overlay = document.getElementById('fichaTecnica3DOverlay');
        if (overlay) overlay.style.display = 'none';
    }

    function abrirFichaModalCompletaDesde3D() {
        cerrarFichaTecnica3D();
        if (elementoFicha3DActivo) {
            mostrarFichaTecnicaModal(elementoFicha3DActivo);
        }
    }

    // MODAL FICHA TÉCNICA EN DOLE CLIC
    let elementoFichaActivo = null;

    function mostrarFichaTecnicaModal(el) {
        if (!el) return;
        elementoFichaActivo = el;
        seleccionarElemento(el, false);

        const invData = el.inventario_data || (el.inventario_key && window.equiposInventarioMap ? window.equiposInventarioMap[el.inventario_key] : null);

        document.getElementById('fichaNombre').textContent = invData ? (invData.nombre || el.label) : (el.label || 'Componente 2D');
        document.getElementById('fichaTipo').textContent = (invData ? (invData.tipo || el.tipo) : (el.tipo || 'elemento')).toUpperCase();
        
        const iconBox = document.getElementById('fichaIconBox');
        const iconEl = document.getElementById('fichaIcon');
        const effIcon = (invData && invData.icon) ? invData.icon : (el.icon || (['rect','polygon','triangle','circle','line'].includes(el.tipo) ? 'bi-bounding-box-circles' : 'bi-display-fill'));
        const effColor = (invData && invData.color) ? invData.color : (el.color || '#38bdf8');
        
        if (iconEl) {
            iconEl.className = `bi ${effIcon}`;
        }
        if (iconBox) {
            iconBox.style.background = effColor + '33';
            iconBox.style.color = effColor;
        }

        document.getElementById('fichaIP').textContent = (invData && invData.ip && invData.ip !== 'N/A') ? invData.ip : (el.ip || 'No asignada');
        document.getElementById('fichaDept').textContent = (invData && invData.dept) ? invData.dept : (el.dept || 'General');

        const rawData = (invData && invData.raw) ? invData.raw : (invData || {});
        const nodoVal = el.nodo || rawData.nodo || rawData.nodo_red || rawData.numero_nodo || rawData.nodo_voz_datos || rawData.nodo_datos || 'No asignado';
        const puertoVal = el.puerto_sw || el.puerto || rawData.puerto_sw || rawData.puerto_switch || rawData.puerto || rawData.patch_panel || rawData.puerto_patch || 'No asignado';

        const elemNodo = document.getElementById('fichaNodoRed');
        if (elemNodo) elemNodo.textContent = nodoVal;

        const elemPuerto = document.getElementById('fichaPuertoSW');
        if (elemPuerto) elemPuerto.textContent = puertoVal;

        document.getElementById('fichaCoords').textContent = `X: ${el.x}px, Y: ${el.y}px`;
        document.getElementById('fichaDim').textContent = `${el.width || '--'} x ${el.height || '--'} px`;

        const groupCob = document.getElementById('fichaGroupCobertura');
        if (groupCob) {
            const hasCob = (el.tipo === 'ap' || el.tipo === 'camara');
            groupCob.style.display = hasCob ? 'block' : 'none';
            if (hasCob) {
                document.getElementById('fichaRadioText').textContent = `Radio Cobertura: ${el.radio || 100} px`;
                document.getElementById('fichaAnguloText').textContent = `Ángulo de Visión: ${el.angulo || 0}°`;
            }
        }

        const groupInv = document.getElementById('fichaGroupInventario');
        if (groupInv) {
            if (invData || el.inventario_key) {
                groupInv.style.display = 'block';
                const raw = (invData && invData.raw) ? invData.raw : (invData || {});
                
                if (document.getElementById('fichaInvModulo')) {
                    document.getElementById('fichaInvModulo').textContent = invData ? invData.modulo : 'Inventario';
                }
                if (document.getElementById('fichaInvUsuario')) {
                    const uVal = invData ? (invData.usuario || raw.usuario || 'N/A') : (el.usuario || 'N/A');
                    if (uVal && uVal !== 'N/A' && uVal !== '--') {
                        const uEsc = String(uVal).replace(/'/g, "\\'").replace(/"/g, '&quot;');
                        document.getElementById('fichaInvUsuario').innerHTML = `<a href="javascript:void(0)" onclick="abrirExpedienteUsuario('${uEsc}')" class="text-info fw-bold text-decoration-underline" title="Ver Expediente del Personal"><i class="bi bi-person-badge me-1"></i>${uVal}</a>`;
                    } else {
                        document.getElementById('fichaInvUsuario').textContent = 'N/A';
                    }
                }
                if (document.getElementById('fichaInvDeptPuesto')) {
                    const deptStr = invData ? (invData.dept || raw.departamento || '') : '';
                    const puestoStr = raw.puesto ? ` (${raw.puesto})` : '';
                    document.getElementById('fichaInvDeptPuesto').textContent = (deptStr + puestoStr) || 'General';
                }
                if (document.getElementById('fichaInvMarcaModelo')) {
                    const marcaStr = raw.marca || raw.marca_modelo || invData.marca || '';
                    const modeloStr = raw.modelo || invData.modelo || '';
                    const fullMm = (marcaStr + ' ' + modeloStr).trim();
                    document.getElementById('fichaInvMarcaModelo').textContent = fullMm || (invData ? invData.nombre : 'N/A');
                }
                if (document.getElementById('fichaInvSerie')) {
                    const serStr = raw.serie || raw.numero_serie || invData.serie || el.inventario_key || 'N/A';
                    document.getElementById('fichaInvSerie').textContent = serStr;
                }
                if (document.getElementById('fichaInvEspecs')) {
                    let especsParts = [];
                    if (raw.tipo_equipo) especsParts.push('Tipo: ' + raw.tipo_equipo);
                    if (raw.procesador) especsParts.push('CPU: ' + raw.procesador + (raw.ghz ? ' ' + raw.ghz : ''));
                    if (raw.ram) especsParts.push('RAM: ' + raw.ram);
                    if (raw.dd) especsParts.push('Disco: ' + raw.dd);
                    if (raw.sistema_op) especsParts.push('OS: ' + raw.sistema_op);
                    if (raw.subtipo_camara) especsParts.push('Cámara: ' + raw.subtipo_camara);
                    if (raw.dvr_vinculado) especsParts.push('DVR: ' + raw.dvr_vinculado);
                    
                    const especsStr = especsParts.join(' | ');
                    document.getElementById('fichaInvEspecs').textContent = especsStr || 'N/A';
                }
                if (document.getElementById('fichaInvEstado')) {
                    const est = raw.estatus || raw.estado || invData.estado || 'Activo';
                    const ubi = raw.ubicacion || invData.ubicacion || '';
                    document.getElementById('fichaInvEstado').textContent = est + (ubi ? ' (' + ubi + ')' : '');
                }
                if (document.getElementById('fichaInvNotas')) {
                    const nts = raw.observaciones || raw.notas || raw.motivo_baja || '';
                    const elemNotasGroup = document.getElementById('fichaInvGroupNotas');
                    if (elemNotasGroup) {
                        elemNotasGroup.style.display = nts ? 'block' : 'none';
                        document.getElementById('fichaInvNotas').textContent = nts;
                    }
                }
            } else {
                groupInv.style.display = 'none';
            }
        }

        const rawDataF = (invData && invData.raw) ? invData.raw : (invData || {});
        const fotoVal = el.foto_url || (invData ? (invData.foto_url || (rawDataF ? (rawDataF.foto_url || rawDataF.imagen_url || rawDataF.foto || rawDataF.imagen) : '')) : (rawDataF.foto_url || rawDataF.imagen_url || rawDataF.foto || rawDataF.imagen || ''));
        const groupFoto = document.getElementById('fichaGroupFoto');
        const imgFoto = document.getElementById('fichaFotoImg');
        if (groupFoto && imgFoto) {
            if (fotoVal && String(fotoVal).trim() !== '') {
                imgFoto.src = fotoVal;
                groupFoto.style.display = 'block';
            } else {
                groupFoto.style.display = 'none';
            }
        }

        const modal = new bootstrap.Modal(document.getElementById('modalFichaTecnicaElemento'));
        modal.show();
    }

    function abrirExpedienteUsuario(nombreUsuario) {
        if (!nombreUsuario || nombreUsuario === 'N/A' || nombreUsuario === '--') return;

        const formData = new FormData();
        formData.append('accion', 'obtener_expediente_usuario');
        formData.append('usuario', nombreUsuario);

        fetch('infraestructura.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            let u = null;
            if (data && data.status === 'success' && data.found) {
                u = data.usuario;
            }

            const modalEl = document.getElementById('modalExpedienteUsuario');
            if (!modalEl) return;

            const cleanNombre = u ? u.nombre : nombreUsuario;
            const cleanNombreEsc = String(cleanNombre).replace(/'/g, "\\'").replace(/"/g, '&quot;');
            
            document.getElementById('userExpNombre').textContent = cleanNombre;
            document.getElementById('userExpEmail').textContent = u ? (u.email || 'Sin correo registrado') : (nombreUsuario.toLowerCase().replace(/\s+/g, '') + '@divolavilla.com');
            document.getElementById('userExpAgencia').textContent = u ? (u.agencia || 'DIVOL LA VILLA') : 'DIVOL LA VILLA';
            document.getElementById('userExpArea').textContent = u ? (u.area || 'General / Operaciones') : 'Ventas / Operaciones';
            document.getElementById('userExpPuesto').textContent = u ? (u.puesto || 'Responsable de Equipo') : 'Responsable de Equipo';
            document.getElementById('userExpTelefono').textContent = u ? (u.telefono || 'Sin extensión') : '5512121212';
            document.getElementById('userExpRol').textContent = u ? (u.rol || 'Usuario') : 'Usuario';

            const avatarBox = document.getElementById('userExpAvatarBox');
            if (u && u.foto_url && u.foto_url !== '') {
                avatarBox.innerHTML = `<img src="${u.foto_url}" class="rounded-circle border border-info shadow" style="width: 72px; height: 72px; object-fit: cover; cursor: pointer;" onclick="abrirLightboxFoto('${u.foto_url}', 'Fotografía de ${cleanNombreEsc}')" title="Haz clic para amplificar la imagen en tamaño completo">`;
            } else {
                avatarBox.innerHTML = `<div class="rounded-circle bg-primary bg-opacity-25 border border-primary text-info fw-bold fs-2 d-flex align-items-center justify-content-center" style="width: 72px; height: 72px;">${cleanNombre.charAt(0).toUpperCase()}</div>`;
            }

            const tieneAcceso = u ? (!u.hasOwnProperty('acceso_portal') || parseInt(u.acceso_portal) === 1) : true;
            const badgeBox = document.getElementById('userExpAccesoBadge');
            if (tieneAcceso) {
                badgeBox.innerHTML = `<span class="badge bg-primary bg-opacity-25 text-primary border border-primary rounded-pill px-3 py-1 fw-bold"><i class="bi bi-door-open-fill me-1"></i> Con Acceso al Portal</span>`;
            } else {
                badgeBox.innerHTML = `<span class="badge bg-warning bg-opacity-25 text-warning border border-warning rounded-pill px-3 py-1 fw-bold"><i class="bi bi-door-closed-fill me-1"></i> Solo Registro (Sin Acceso)</span>`;
            }

            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        })
        .catch(err => {
            console.error('Error al obtener datos del usuario:', err);
        });
    }

    function abrirLightboxFoto(url, titulo) {
        if (!url) return;
        const lb = document.getElementById('customFotoLightbox');
        const img = document.getElementById('lightbox_img');
        const tit = document.getElementById('lightbox_titulo');
        if (lb && img) {
            img.src = url;
            if (tit) tit.textContent = titulo || 'Fotografía en Tamaño Completo';
            lb.style.display = 'flex';
        }
    }

    function cerrarCustomLightbox() {
        const lb = document.getElementById('customFotoLightbox');
        if (lb) lb.style.display = 'none';
    }

    function centrarEnElementoDesdeFicha() {
        if (elementoFichaActivo) {
            centrarVistaEnElemento(elementoFichaActivo);
            const modalEl = document.getElementById('modalFichaTecnicaElemento');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        }
    }

    function abrirInspectorPropiedades() {
        const modalEl = document.getElementById('modalFichaTecnicaElemento');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
        const panel = document.getElementById('panelPropiedades');
        if (panel) {
            panel.scrollIntoView({ behavior: 'smooth' });
        }
    }

    // MANEJO DE MENÚ CONTEXTUAL DE CLIC DERECHO
    let elementoCtxActivo = null;
    let clickCtxEvent = null;

    function abrirCustomContextMenu(e, el) {
        e.preventDefault();
        e.stopPropagation();
        elementoCtxActivo = el;
        clickCtxEvent = e;
        seleccionarElemento(el, false); // Selecciona y destaca inmediatamente con borde amarillo el componente

        const ctxMenu = document.getElementById('customCanvasContextMenu');
        const header = document.getElementById('ctxMenuHeader');

        if (header) {
            header.innerHTML = `<i class="bi ${el.icon || 'bi-gear-fill'} me-1"></i> ${escapeHtml(el.label || 'Componente Seleccionado')}`;
        }

        const isShape = (el.vertices && el.vertices.length > 0) || ['rect', 'polygon', 'triangle'].includes(el.tipo);

        const optSel = document.getElementById('ctxOptSeleccionar');
        const optCop = document.getElementById('ctxOptCopiar');
        const optPeg = document.getElementById('ctxOptPegar');
        const optDup = document.getElementById('ctxOptDuplicar');
        const optFic = document.getElementById('ctxOptFicha');
        const optCen = document.getElementById('ctxOptCentrar');
        const optVer = document.getElementById('ctxOptVertice');
        const optMod = document.getElementById('ctxOptModificar');
        const optDel = document.getElementById('ctxOptEliminar');

        if (optSel) optSel.style.display = 'block';
        if (optCop) optCop.style.display = 'block';
        if (optPeg) optPeg.style.display = portapapelesElemento ? 'block' : 'none';
        if (optDup) optDup.style.display = 'block';
        if (optFic) optFic.style.display = 'block';
        if (optCen) optCen.style.display = 'block';
        if (optVer) optVer.style.display = isShape ? 'block' : 'none';
        if (optMod) optMod.style.display = 'block';
        if (optDel) optDel.style.display = 'block';

        if (ctxMenu) {
            ctxMenu.style.left = e.clientX + 'px';
            ctxMenu.style.top = e.clientY + 'px';
            ctxMenu.style.display = 'block';
        }
    }

    function ejecutarCtxAccion(evt, accion) {
        if (evt) {
            evt.preventDefault();
            evt.stopPropagation();
        }
        const ctxMenu = document.getElementById('customCanvasContextMenu');
        if (ctxMenu) ctxMenu.style.display = 'none';

        if (accion === 'seleccionar') {
            if (elementoCtxActivo) seleccionarElemento(elementoCtxActivo);
        } else if (accion === 'copiar') {
            if (elementoCtxActivo) {
                seleccionarElemento(elementoCtxActivo);
                copiarElementoSeleccionado();
            } else if (elementoSeleccionado) {
                copiarElementoSeleccionado();
            }
        } else if (accion === 'pegar') {
            if (clickCtxEvent) {
                const container = document.getElementById('contenedorNodosCanvas');
                if (container) {
                    const rect = container.getBoundingClientRect();
                    const posX = Math.round((clickCtxEvent.clientX - rect.left) / zoomLevel);
                    const posY = Math.round((clickCtxEvent.clientY - rect.top) / zoomLevel);
                    pegarElementoPortapapeles(posX, posY);
                } else {
                    pegarElementoPortapapeles();
                }
            } else {
                pegarElementoPortapapeles();
            }
        } else if (accion === 'duplicar') {
            if (elementoCtxActivo) seleccionarElemento(elementoCtxActivo);
            copiarElementoSeleccionado();
            pegarElementoPortapapeles();
        } else if (accion === 'ficha') {
            if (elementoCtxActivo) mostrarFichaTecnicaModal(elementoCtxActivo);
        } else if (accion === 'centrar') {
            if (elementoCtxActivo) centrarVistaEnElemento(elementoCtxActivo);
        } else if (accion === 'vertice') {
            if (clickCtxEvent && elementoCtxActivo) {
                guardarEstadoHistorial();
                if (!elementoCtxActivo.vertices || elementoCtxActivo.vertices.length === 0) {
                    const w = elementoCtxActivo.width || 160;
                    const h = elementoCtxActivo.height || 110;
                    elementoCtxActivo.vertices = [
                        {x: 0, y: 0},
                        {x: w, y: 0},
                        {x: w, y: h},
                        {x: 0, y: h}
                    ];
                }
                const container = document.getElementById('contenedorNodosCanvas');
                if (container) {
                    const rect = container.getBoundingClientRect();
                    const clickLocalX = Math.round((clickCtxEvent.clientX - rect.left) / zoomLevel - elementoCtxActivo.x);
                    const clickLocalY = Math.round((clickCtxEvent.clientY - rect.top) / zoomLevel - elementoCtxActivo.y);
                    insertarVerticeEnSegmentoMasCercano(elementoCtxActivo.vertices, clickLocalX, clickLocalY);
                    renderizarNodosCanvas();
                    seleccionarElemento(elementoCtxActivo);
                }
            }
        } else if (accion === 'modificar') {
            if (elementoCtxActivo) {
                seleccionarElemento(elementoCtxActivo);
                abrirInspectorPropiedades();
            }
        } else if (accion === 'eliminar') {
            if (elementoCtxActivo) seleccionarElemento(elementoCtxActivo);
            eliminarElementoSeleccionado();
        }
    }

    document.addEventListener('click', (e) => {
        const ctxMenu = document.getElementById('customCanvasContextMenu');
        if (ctxMenu && !ctxMenu.contains(e.target)) {
            ctxMenu.style.display = 'none';
        }
    });

    document.addEventListener('scroll', () => {
        const ctxMenu = document.getElementById('customCanvasContextMenu');
        if (ctxMenu) ctxMenu.style.display = 'none';
    }, true);

    // MANEJO DE MOVIMIENTOS Y RESIZE EN TIEMPO REAL
    document.addEventListener('mousemove', (e) => {
        if (isRightClickSelecting) {
            const container = document.getElementById('contenedorNodosCanvas');
            if (!container) return;
            const rect = container.getBoundingClientRect();

            const startX = Math.round((rightSelectStartX - rect.left) / zoomLevel);
            const startY = Math.round((rightSelectStartY - rect.top) / zoomLevel);
            const currentX = Math.round((e.clientX - rect.left) / zoomLevel);
            const currentY = Math.round((e.clientY - rect.top) / zoomLevel);

            const minX = Math.min(startX, currentX);
            const minY = Math.min(startY, currentY);
            const maxX = Math.max(startX, currentX);
            const maxY = Math.max(startY, currentY);

            const width = maxX - minX;
            const height = maxY - minY;

            const selectBox = document.getElementById('rightSelectBox');
            if (selectBox && (width > 5 || height > 5)) {
                selectBox.style.display = 'block';
                selectBox.style.left = minX + 'px';
                selectBox.style.top = minY + 'px';
                selectBox.style.width = width + 'px';
                selectBox.style.height = height + 'px';

                elementosCanvas.forEach(el => {
                    let elMinX = el.x;
                    let elMinY = el.y;
                    let elMaxX = el.x + (el.width || 60);
                    let elMaxY = el.y + (el.height || 60);

                    if (el.vertices && el.vertices.length > 0) {
                        const pts = el.vertices;
                        const vx = pts.map(p => p.x);
                        const vy = pts.map(p => p.y);
                        elMinX = el.x + Math.min(...vx);
                        elMinY = el.y + Math.min(...vy);
                        elMaxX = el.x + Math.max(...vx);
                        elMaxY = el.y + Math.max(...vy);
                    } else if (!['rect', 'polygon', 'triangle', 'line', 'circle', 'escalera_recta', 'escalera_l', 'escalera_espiral', 'puerta', 'escritorio'].includes(el.tipo)) {
                        const halfW = (el.width || 60) / 2;
                        const halfH = (el.height || 60) / 2;
                        elMinX = el.x - halfW;
                        elMinY = el.y - halfH;
                        elMaxX = el.x + halfW;
                        elMaxY = el.y + halfH;
                    }

                    const intersects = (elMinX <= maxX && elMaxX >= minX && elMinY <= maxY && elMaxY >= minY);

                    if (intersects) {
                        if (!elementosSeleccionados.some(s => s.id === el.id)) {
                            elementosSeleccionados.push(el);
                        }
                    }
                });

                if (elementosSeleccionados.length > 0) {
                    elementoSeleccionado = elementosSeleccionados[elementosSeleccionados.length - 1];
                }
                renderizarNodosCanvas();
            }
            return;
        }

        if (isPanningCanvas) {
            const canvasOuter = document.getElementById('canvasOuter');
            if (canvasOuter) {
                const dx = e.clientX - panStartX;
                const dy = e.clientY - panStartY;
                if (Math.abs(dx) > 8 || Math.abs(dy) > 8) {
                    panHasMoved = true;
                }
                canvasOuter.scrollLeft = panScrollLeft - dx;
                canvasOuter.scrollTop = panScrollTop - dy;
                renderizarMinimapa();
            }
            return;
        }

        if (resizingShape) {
            const dx = (e.clientX - resizingShape.startX) / zoomLevel;
            const dy = (e.clientY - resizingShape.startY) / zoomLevel;
            const s = resizingShape.shape;
            const pos = resizingShape.handle;

            if (s.vertices && s.vertices.length > 0) {
                let newW = resizingShape.startW;
                let newH = resizingShape.startH;

                if (pos.includes('e')) newW = Math.max(20, resizingShape.startW + dx);
                if (pos.includes('s')) newH = Math.max(20, resizingShape.startH + dy);
                if (pos.includes('w')) newW = Math.max(20, resizingShape.startW - dx);
                if (pos.includes('n')) newH = Math.max(20, resizingShape.startH - dy);

                const scaleX = newW / resizingShape.startW;
                const scaleY = newH / resizingShape.startH;

                const origMinX = Math.min(...resizingShape.initialVertices.map(p => p.x));
                const origMinY = Math.min(...resizingShape.initialVertices.map(p => p.y));

                s.vertices.forEach((v, idx) => {
                    const orig = resizingShape.initialVertices[idx];
                    const relX = orig.x - origMinX;
                    const relY = orig.y - origMinY;
                    v.x = Math.round(origMinX + relX * scaleX);
                    v.y = Math.round(origMinY + relY * scaleY);
                });

                renderizarNodosCanvas();
                return;
            }

            if (pos.includes('e')) s.width = Math.max(20, resizingShape.startW + dx);
            if (pos.includes('s')) s.height = Math.max(20, resizingShape.startH + dy);
            if (pos.includes('w')) s.width = Math.max(20, resizingShape.startW - dx);
            if (pos.includes('n')) s.height = Math.max(20, resizingShape.startH - dy);

            renderizarNodosCanvas();
            return;
        }

        if (draggingVertex) {
            const container = document.getElementById('contenedorNodosCanvas');
            if (!container) return;
            const rect = container.getBoundingClientRect();
            
            let localX = Math.round((e.clientX - rect.left) / zoomLevel - draggingVertex.shape.x);
            let localY = Math.round((e.clientY - rect.top) / zoomLevel - draggingVertex.shape.y);

            const vList = draggingVertex.shape.vertices || [];
            const idx = draggingVertex.idx;
            const N = vList.length;

            let isSnapped = false;
            let snapLabel = '';
            let cornerAngle = 0;

            if (N >= 3) {
                const prevPt = vList[(idx - 1 + N) % N];
                const nextPt = vList[(idx + 1) % N];

                const SNAP_THRESH = 9; // Umbral de atracción magnética en px

                // Encaje magnético en eje X (Alineación vertical con vértices vecinos)
                if (Math.abs(localX - prevPt.x) <= SNAP_THRESH) {
                    localX = prevPt.x;
                    isSnapped = true;
                } else if (Math.abs(localX - nextPt.x) <= SNAP_THRESH) {
                    localX = nextPt.x;
                    isSnapped = true;
                }

                // Encaje magnético en eje Y (Alineación horizontal con vértices vecinos)
                if (Math.abs(localY - prevPt.y) <= SNAP_THRESH) {
                    localY = prevPt.y;
                    isSnapped = true;
                } else if (Math.abs(localY - nextPt.y) <= SNAP_THRESH) {
                    localY = nextPt.y;
                    isSnapped = true;
                }

                // Cálculo exacto del ángulo del doblez (en grados °)
                let angle1 = Math.atan2(prevPt.y - localY, prevPt.x - localX) * (180 / Math.PI);
                let angle2 = Math.atan2(nextPt.y - localY, nextPt.x - localX) * (180 / Math.PI);
                let diffAngle = Math.abs(angle1 - angle2);
                if (diffAngle > 180) diffAngle = 360 - diffAngle;
                cornerAngle = Math.round(diffAngle);

                if (cornerAngle >= 88 && cornerAngle <= 92) {
                    cornerAngle = 90;
                    snapLabel = '📐 90° (Ángulo Recto)';
                } else if (cornerAngle >= 178 && cornerAngle <= 182) {
                    cornerAngle = 180;
                    snapLabel = '📏 180° (Pared Recta)';
                } else if (cornerAngle >= 268 && cornerAngle <= 272) {
                    cornerAngle = 270;
                    snapLabel = '📐 270° (Ángulo Recto Ex)';
                } else {
                    snapLabel = `📐 ${cornerAngle}°`;
                }
            }

            draggingVertex.vertex.x = localX;
            draggingVertex.vertex.y = localY;

            // Mostrar Tooltip Flotante de Ángulo en Grados
            let indicator = document.getElementById('vertexAngleIndicator');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.id = 'vertexAngleIndicator';
                indicator.style.position = 'fixed';
                indicator.style.zIndex = '9999';
                indicator.style.pointerEvents = 'none';
                indicator.style.borderRadius = '8px';
                indicator.style.padding = '4px 10px';
                indicator.style.fontFamily = 'monospace';
                indicator.style.fontWeight = 'bold';
                indicator.style.fontSize = '0.85rem';
                indicator.style.boxShadow = '0 4px 16px rgba(0,0,0,0.6)';
                document.body.appendChild(indicator);
            }

            indicator.style.display = 'block';
            indicator.style.left = (e.clientX + 16) + 'px';
            indicator.style.top = (e.clientY - 28) + 'px';

            if (isSnapped || [90, 180, 270].includes(cornerAngle)) {
                indicator.style.background = '#22c55e';
                indicator.style.color = '#ffffff';
                indicator.style.border = '1.5px solid #ffffff';
                indicator.innerHTML = `✨ ${snapLabel} (Recto / Ajustado)`;
            } else {
                indicator.style.background = 'rgba(9, 26, 48, 0.95)';
                indicator.style.color = '#38bdf8';
                indicator.style.border = '1.5px solid #38bdf8';
                indicator.innerHTML = snapLabel;
            }

            renderizarNodosCanvas();
            return;
        }

        if (draggingElement) {
            const container = document.getElementById('contenedorNodosCanvas');
            if (!container) return;
            const rect = container.getBoundingClientRect();
            
            let newX = (e.clientX - rect.left) / zoomLevel - dragOffsetX;
            let newY = (e.clientY - rect.top) / zoomLevel - dragOffsetY;

            if (newX < 10) newX = 10;
            if (newY < 10) newY = 10;

            draggingElement.x = Math.round(newX);
            draggingElement.y = Math.round(newY);

            renderizarNodosCanvas();
        }
    });

    document.addEventListener('mouseup', (e) => {
        const indicator = document.getElementById('vertexAngleIndicator');
        if (indicator) indicator.style.display = 'none';

        if (isRightClickSelecting) {
            isRightClickSelecting = false;
            const selectBox = document.getElementById('rightSelectBox');
            if (selectBox) selectBox.style.display = 'none';

            if (elementosSeleccionados.length > 1) {
                const ctxMenu = document.getElementById('customCanvasContextMenu');
                const header = document.getElementById('ctxMenuHeader');
                if (header) {
                    header.innerHTML = `<i class="bi bi-check2-all text-warning me-1"></i> ${elementosSeleccionados.length} Componentes Seleccionados`;
                }

                const optSel = document.getElementById('ctxOptSeleccionar');
                const optCop = document.getElementById('ctxOptCopiar');
                const optPeg = document.getElementById('ctxOptPegar');
                const optDup = document.getElementById('ctxOptDuplicar');
                const optFic = document.getElementById('ctxOptFicha');
                const optCen = document.getElementById('ctxOptCentrar');
                const optVer = document.getElementById('ctxOptVertice');
                const optMod = document.getElementById('ctxOptModificar');
                const optDel = document.getElementById('ctxOptEliminar');

                if (optSel) optSel.style.display = 'block';
                if (optCop) optCop.style.display = 'block';
                if (optPeg) optPeg.style.display = portapapelesElemento ? 'block' : 'none';
                if (optDup) optDup.style.display = 'block';
                if (optFic) optFic.style.display = 'none';
                if (optCen) optCen.style.display = 'none';
                if (optVer) optVer.style.display = 'none';
                if (optMod) optMod.style.display = 'none';
                if (optDel) optDel.style.display = 'block';

                if (ctxMenu && e) {
                    ctxMenu.style.left = e.clientX + 'px';
                    ctxMenu.style.top = e.clientY + 'px';
                    ctxMenu.style.display = 'block';
                }
            }
        }

        if (isPanningCanvas) {
            isPanningCanvas = false;
            const canvasOuter = document.getElementById('canvasOuter');
            if (canvasOuter) canvasOuter.style.cursor = 'grab';
        }
        resizingShape = null;
        draggingVertex = null;
        draggingElement = null;
    });

    function seleccionarElemento(el, autoCenter = false) {
        if (!el) return;
        elementoSeleccionado = el;
        if (autoCenter) {
            centrarVistaEnElemento(el);
        }

        const itemCard = document.getElementById('item_comp_' + el.id);
        if (itemCard) {
            itemCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        actualizarEstadoBotonesInventario();

        if (esModoLectura) {
            return;
        }
        renderizarNodosCanvas();

        const panel = document.getElementById('panelPropiedades');
        if (panel) {
            panel.classList.remove('d-none');
            document.getElementById('propLabel').value = el.label || '';
            document.getElementById('propWidth').value = el.width || 80;
            document.getElementById('propHeight').value = el.height || 80;
            document.getElementById('propHeight3D').value = el.height3d || (el.vertices ? 45 : 50);
            document.getElementById('propElevation3D').value = el.elevation3d || 0;

            document.getElementById('propAngulo').value = el.angulo || 0;
            document.getElementById('propAnguloX').value = el.anguloX || 0;
            document.getElementById('propAnguloZ').value = el.anguloZ || 0;

            if (document.getElementById('valAnguloY')) document.getElementById('valAnguloY').textContent = (el.angulo || 0) + '°';
            if (document.getElementById('valAnguloX')) document.getElementById('valAnguloX').textContent = (el.anguloX || 0) + '°';
            if (document.getElementById('valAnguloZ')) document.getElementById('valAnguloZ').textContent = (el.anguloZ || 0) + '°';
            if (document.getElementById('valElevation3D')) document.getElementById('valElevation3D').textContent = (el.elevation3d || 0) + 'px';

            const defaultOpac = (el.opacidad !== undefined ? el.opacidad : (['rect', 'polygon', 'triangle', 'circle'].includes(el.tipo) ? 0.45 : 1.0));
            const opacPct = Math.round(defaultOpac * 100);
            if (document.getElementById('propOpacidad')) document.getElementById('propOpacidad').value = opacPct;
            if (document.getElementById('valOpacidad')) document.getElementById('valOpacidad').textContent = opacPct + '%';

            if (document.getElementById('propRadio')) document.getElementById('propRadio').value = el.radio || 120;
            if (document.getElementById('propRadioNum')) document.getElementById('propRadioNum').value = el.radio || 120;
            document.getElementById('propBorderWidth').value = el.borderWidth || 2;
            document.getElementById('propColor').value = (el.color && el.color.startsWith('#')) ? el.color : '#3b82f6';

            if (document.getElementById('propPosX')) document.getElementById('propPosX').value = Math.round(el.x || 0);
            if (document.getElementById('propPosY')) document.getElementById('propPosY').value = Math.round(el.y || 0);

            const esDispositivoRed = ['nodo', 'pc', 'ap', 'camara', 'site', 'impresora', 'telefono', 'pantalla', 'equipo_inv'].includes(el.tipo);
            if (document.getElementById('groupPropOpacidad')) {
                document.getElementById('groupPropOpacidad').style.display = esDispositivoRed ? 'none' : 'block';
            }

            const groupSubtipo = document.getElementById('groupSubtipoPC');
            if (groupSubtipo) {
                groupSubtipo.style.display = (el.tipo === 'pc') ? 'block' : 'none';
                if (el.tipo === 'pc' && document.getElementById('propSubtipoPC')) {
                    document.getElementById('propSubtipoPC').value = el.subtipo || 'desktop';
                }
            }

            const esPolygon = (el.vertices && el.vertices.length > 0) || el.tipo === 'polygon';
            document.getElementById('groupPolygonControls').classList.toggle('d-none', !esPolygon);
            document.getElementById('groupPropRadio').style.display = (el.tipo === 'ap') ? 'block' : 'none';

            const groupCuadro = document.getElementById('groupCuadroImagen');
            if (groupCuadro) {
                const esCuadroOTv = (el.tipo === 'cuadro_imagen' || el.tipo === 'pantalla');
                groupCuadro.style.display = esCuadroOTv ? 'block' : 'none';
                if (esCuadroOTv) {
                    if (document.getElementById('propImagenUrl')) {
                        document.getElementById('propImagenUrl').value = el.imagenUrl || '';
                    }
                    actualizarPreviewImagenCuadro(el.imagenUrl || '');
                }
            }

            renderizarControlesColoresParedes();
        }
    }

    function syncRadioFromSlider(val) {
        if (document.getElementById('propRadioNum')) {
            document.getElementById('propRadioNum').value = val;
        }
        actualizarPropiedadElemento();
    }

    function syncRadioFromNum(val) {
        const num = Math.max(10, parseInt(val) || 120);
        if (document.getElementById('propRadio')) {
            document.getElementById('propRadio').value = Math.min(1500, num);
        }
        actualizarPropiedadElemento();
    }

    function rotarElementoRapido(delta) {
        if (!elementoSeleccionado) return;
        guardarEstadoHistorial();
        let n = ((elementoSeleccionado.angulo || 0) + delta) % 360;
        if (n < 0) n += 360;
        elementoSeleccionado.angulo = n;
        document.getElementById('propAngulo').value = n;
        actualizarPropiedadElemento();
    }

    function cargarImagenParaCuadro(input) {
        if (!elementoSeleccionado) return;
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const dataUrl = e.target.result;
                elementoSeleccionado.imagenUrl = dataUrl;
                if (document.getElementById('propImagenUrl')) {
                    document.getElementById('propImagenUrl').value = dataUrl;
                }
                actualizarPreviewImagenCuadro(dataUrl);
                renderizarNodosCanvas();
                if (scene3D) renderizarEscena3D();
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function actualizarPreviewImagenCuadro(url) {
        const wrap = document.getElementById('previewCuadroWrap');
        const img = document.getElementById('imgPreviewCuadro');
        if (!wrap || !img) return;
        if (url && url.trim() !== '') {
            img.src = url;
            wrap.style.display = 'block';
        } else {
            img.src = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\'/%3E';
            wrap.style.display = 'none';
        }
    }

    function actualizarPropiedadElemento() {
        if (!elementoSeleccionado) return;
        elementoSeleccionado.label = document.getElementById('propLabel').value;
        elementoSeleccionado.width = Math.max(5, parseInt(document.getElementById('propWidth').value) || 80);
        elementoSeleccionado.height = Math.max(5, parseInt(document.getElementById('propHeight').value) || 80);
        elementoSeleccionado.height3d = Math.max(1, parseInt(document.getElementById('propHeight3D').value) || 50);
        elementoSeleccionado.elevation3d = parseInt(document.getElementById('propElevation3D').value) || 0;

        if (elementoSeleccionado.tipo === 'pc' && document.getElementById('propSubtipoPC')) {
            const sub = document.getElementById('propSubtipoPC').value;
            elementoSeleccionado.subtipo = sub;
            elementoSeleccionado.icon = (sub === 'laptop' ? 'bi-laptop' : (sub === 'allinone' ? 'bi-display' : 'bi-pc-display-horizontal'));
        }

        elementoSeleccionado.angulo = parseInt(document.getElementById('propAngulo').value) || 0;
        elementoSeleccionado.anguloX = parseInt(document.getElementById('propAnguloX').value) || 0;
        elementoSeleccionado.anguloZ = parseInt(document.getElementById('propAnguloZ').value) || 0;

        if (document.getElementById('propOpacidad')) {
            const opacPct = parseInt(document.getElementById('propOpacidad').value) || 100;
            elementoSeleccionado.opacidad = Math.max(0.05, Math.min(1.0, opacPct / 100));
            if (document.getElementById('valOpacidad')) document.getElementById('valOpacidad').textContent = opacPct + '%';
        }

        if (document.getElementById('propImagenUrl')) {
            const imgUrlVal = document.getElementById('propImagenUrl').value.trim();
            elementoSeleccionado.imagenUrl = imgUrlVal;
            actualizarPreviewImagenCuadro(imgUrlVal);
        }

        elementoSeleccionado.radio = parseInt(document.getElementById('propRadio').value) || 100;
        elementoSeleccionado.borderWidth = parseInt(document.getElementById('propBorderWidth').value) || 2;

        if (document.getElementById('valAnguloY')) document.getElementById('valAnguloY').textContent = elementoSeleccionado.angulo + '°';
        if (document.getElementById('valAnguloX')) document.getElementById('valAnguloX').textContent = elementoSeleccionado.anguloX + '°';
        if (document.getElementById('valAnguloZ')) document.getElementById('valAnguloZ').textContent = elementoSeleccionado.anguloZ + '°';
        if (document.getElementById('valElevation3D')) document.getElementById('valElevation3D').textContent = elementoSeleccionado.elevation3d + 'px';

        if (document.getElementById('propPosX')) elementoSeleccionado.x = parseInt(document.getElementById('propPosX').value) || 0;
        if (document.getElementById('propPosY')) elementoSeleccionado.y = parseInt(document.getElementById('propPosY').value) || 0;

        const colVal = document.getElementById('propColor').value;
        if (colVal) {
            if (['rect', 'circle', 'triangle', 'polygon'].includes(elementoSeleccionado.tipo)) {
                elementoSeleccionado.color = colVal + '55';
                elementoSeleccionado.borderColor = colVal;
            } else {
                elementoSeleccionado.color = colVal;
            }
        }
        renderizarNodosCanvas();
        if (scene3D) {
            renderizarEscena3D();
        }
    }

    function renderizarControlesColoresParedes() {
        const container = document.getElementById('containerColoresParedes');
        if (!container) return;
        if (!elementoSeleccionado || !elementoSeleccionado.vertices || elementoSeleccionado.vertices.length === 0) {
            container.style.display = 'none';
            return;
        }
        container.style.display = 'block';
        const listDiv = document.getElementById('listaColoresParedes');
        if (!listDiv) return;

        if (!elementoSeleccionado.coloresParedes) {
            elementoSeleccionado.coloresParedes = [];
        }
        const defaultColor = elementoSeleccionado.borderColor || elementoSeleccionado.color || '#3b82f6';
        const numParedes = elementoSeleccionado.vertices.length;

        let html = '';
        for (let i = 0; i < numParedes; i++) {
            const valCol = elementoSeleccionado.coloresParedes[i] || defaultColor;
            html += `
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-secondary small" style="font-size: 0.65rem;">Pared ${i + 1} (Lado ${i + 1}):</span>
                    <input type="color" value="${valCol}" class="form-control form-control-color bg-dark border-secondary px-1" style="height: 24px; width: 50px;" onchange="actualizarColorParedIndividual(${i}, this.value)">
                </div>
            `;
        }
        listDiv.innerHTML = html;
    }

    function actualizarColorParedIndividual(idx, colorHex) {
        if (!elementoSeleccionado) return;
        guardarEstadoHistorial();
        if (!elementoSeleccionado.coloresParedes) elementoSeleccionado.coloresParedes = [];
        elementoSeleccionado.coloresParedes[idx] = colorHex;
        renderizarNodosCanvas();
        if (scene3D) renderizarEscena3D();
    }

    function eliminarElementoSeleccionado() {
        if (!elementoSeleccionado) return;
        guardarEstadoHistorial();
        elementosCanvas = elementosCanvas.filter(item => item.id !== elementoSeleccionado.id);
        elementoSeleccionado = null;
        document.getElementById('panelPropiedades').classList.add('d-none');
        renderizarNodosCanvas();
        if (scene3D) renderizarEscena3D();
    }

    function cargarImagenFondoCanvas(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById('imgFondoPlano');
                img.src = e.target.result;
                img.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // NOTIFICACIÓN FLOTANTE TIPO TOAST (SIN BLOQUEAR EL PROCESO NI RECARGAR LA PÁGINA)
    function mostrarNotificacionToast(mensaje, tipo = 'success') {
        let container = document.getElementById('toastNotificationContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastNotificationContainer';
            container.style.position = 'fixed';
            container.style.bottom = '24px';
            container.style.right = '24px';
            container.style.zIndex = '999999';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '10px';
            container.style.pointerEvents = 'none';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast-item alert alert-${tipo} shadow-lg d-flex align-items-center mb-0 py-2 px-3 rounded-4 border-0 text-white`;
        toast.style.background = tipo === 'success' ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #ef4444, #dc2626)';
        toast.style.boxShadow = '0 10px 25px -5px rgba(0, 0, 0, 0.3)';
        toast.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        toast.style.pointerEvents = 'auto';

        const icon = tipo === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
        toast.innerHTML = `<i class="bi ${icon} fs-5 me-2"></i> <span class="fw-semibold fs-6">${escapeHtml(mensaje)}</span>`;

        container.appendChild(toast);

        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(20px)';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }

    // GUARDAR PLANO 2D/3D EN EL SERVIDOR SIN PAUSAR NI RECARGAR LA PÁGINA
    function guardarPlano2DEnServidor() {
        if (!planoActualData) return;
        const btnGuardar = document.querySelector('button[onclick="guardarPlano2DEnServidor()"]');
        let originalHtml = '';
        if (btnGuardar) {
            originalHtml = btnGuardar.innerHTML;
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Guardando...';
        }

        const formData = new FormData();
        formData.append('accion', 'guardar_plano_2d');
        formData.append('plano_id', planoActualData.id);
        formData.append('nombre_plano', planoActualData.nombre_plano);
        formData.append('elementos_json', JSON.stringify(elementosCanvas));

        const imgInput = document.getElementById('inputFondoImage');
        if (imgInput && imgInput.files && imgInput.files[0]) {
            formData.append('imagen_fondo_file', imgInput.files[0]);
        }

        fetch('infraestructura.php?sec=diagramas&plano_id=' + planoActualData.id, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (btnGuardar) {
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = originalHtml;
            }
            if (data.status === 'success') {
                if (data.plano_id) {
                    planoActualData.id = data.plano_id;
                }
                mostrarNotificacionToast('¡Plano guardado exitosamente!');
            } else {
                mostrarNotificacionToast(data.message || 'No se pudo guardar el plano.', 'danger');
            }
        })
        .catch(err => {
            if (btnGuardar) {
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = originalHtml;
            }
            console.error('Error al guardar plano:', err);
            mostrarNotificacionToast('Error al conectar con el servidor al guardar.', 'danger');
        });
    }

    // LIMPIEZA DE RECURSOS WEBGL AL SALIR DE LA PÁGINA PARA EVITAR BLOQUEOS
    window.addEventListener('beforeunload', () => {
        if (typeof animFrameId3D !== 'undefined' && animFrameId3D) {
            cancelAnimationFrame(animFrameId3D);
        }
        if (typeof renderer3D !== 'undefined' && renderer3D) {
            renderer3D.dispose();
        }
    });

