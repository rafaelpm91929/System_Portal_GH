<?php
// =============================================================================
// COMPONENTE: CAMPANITA DE NOTIFICACIONES MULTIMÓDULO
// Se integra en el navbar superior. Consume api_notificaciones.php respetando RBAC.
// =============================================================================
?>
<!-- CONTENEDOR DE LA CAMPANITA DE NOTIFICACIONES -->
<div class="dropdown d-inline-block notif-dropdown-wrapper" id="dropNotifWrapper">
    <button class="btn btn-notif-bell position-relative" type="button" id="btnCampanaNotif" data-bs-toggle="dropdown" aria-expanded="false" title="Centro de Notificaciones">
        <i class="bi bi-bell-fill fs-5 text-light"></i>
        <!-- Contador de no leídas -->
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge-counter d-none" id="notifBadgeCounter">
            0
        </span>
    </button>

    <div class="dropdown-menu dropdown-menu-end notif-dropdown-menu shadow-lg p-0" aria-labelledby="btnCampanaNotif">
        <!-- Encabezado del Dropdown -->
        <div class="notif-menu-header d-flex align-items-center justify-content-between px-3 py-2.5 border-bottom border-secondary border-opacity-25">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-bell-fill text-warning"></i>
                <span class="fw-bold text-white small tracking-wide">Notificaciones</span>
                <span class="badge bg-primary rounded-pill px-2 py-0.5 small d-none" id="notifTotalBadge">0</span>
            </div>
            <button type="button" class="btn btn-sm btn-link text-warning text-opacity-80 text-decoration-none p-0 small fw-semibold" id="btnMarcarTodasLeidas" onclick="marcarTodasNotifLeidas(event)" title="Marcar todas como leídas">
                <i class="bi bi-check2-all me-1"></i>Marcar leídas
            </button>
        </div>

        <!-- Cuerpo / Lista de Notificaciones con Scroll -->
        <div class="notif-menu-body" id="notifContenedorLista">
            <div class="p-4 text-center text-secondary small" id="notifCargando">
                <div class="spinner-border spinner-border-sm text-warning mb-2" role="status"></div>
                <div>Cargando notificaciones...</div>
            </div>
        </div>

        <!-- Pie del Dropdown -->
        <div class="notif-menu-footer text-center py-2 px-3 border-top border-secondary border-opacity-25 bg-black bg-opacity-30">
            <div class="text-secondary" style="font-size: 0.72rem;">
                <i class="bi bi-shield-check text-warning me-1"></i> Notificaciones según permisos asignados
            </div>
        </div>
    </div>
</div>

<style>
/* ESTILOS DEL BOTÓN DE CAMPANITA */
.btn-notif-bell {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    padding: 0;
    cursor: pointer;
}
.btn-notif-bell:hover,
.btn-notif-bell:focus,
.show > .btn-notif-bell {
    background: rgba(56, 189, 248, 0.15);
    border-color: rgba(56, 189, 248, 0.5);
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
}
.btn-notif-bell:hover i,
.show > .btn-notif-bell i {
    color: #38bdf8 !important;
}

/* BADGE CONTADOR CON PULSO */
.notif-badge-counter {
    font-size: 0.68rem;
    font-weight: 800;
    padding: 3px 6px;
    box-shadow: 0 0 10px rgba(239, 68, 68, 0.8);
    animation: notifPulse 2s infinite;
}
@keyframes notifPulse {
    0% { transform: translate(-50%, -50%) scale(1); }
    50% { transform: translate(-50%, -50%) scale(1.12); }
    100% { transform: translate(-50%, -50%) scale(1); }
}

/* MENÚ DROPDOWN */
.notif-dropdown-menu {
    width: 360px;
    max-width: 90vw;
    background: #061122 !important;
    border: 1px solid rgba(56, 189, 248, 0.35) !important;
    border-radius: 16px !important;
    overflow: hidden;
    backdrop-filter: blur(14px);
    margin-top: 10px !important;
    animation: fadeInNotif 0.2s ease;
}
@keyframes fadeInNotif {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

.notif-menu-header {
    background: rgba(4, 13, 26, 0.85);
}

.notif-menu-body {
    max-height: 380px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(56, 189, 248, 0.4) transparent;
}
.notif-menu-body::-webkit-scrollbar {
    width: 6px;
}
.notif-menu-body::-webkit-scrollbar-thumb {
    background: rgba(56, 189, 248, 0.3);
    border-radius: 4px;
}

/* ÍTEM DE NOTIFICACIÓN */
.notif-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    text-decoration: none;
    color: #e2e8f0;
    transition: background 0.15s ease;
    cursor: pointer;
    position: relative;
}
.notif-item:hover {
    background: rgba(255, 255, 255, 0.04);
    color: #ffffff;
}
.notif-item.no-leida {
    background: rgba(37, 99, 235, 0.08);
}
.notif-item.no-leida:hover {
    background: rgba(37, 99, 235, 0.14);
}
.notif-item:last-child {
    border-bottom: none;
}

.notif-icon-box {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.notif-dot-unread {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #38bdf8;
    box-shadow: 0 0 8px #38bdf8;
    flex-shrink: 0;
    margin-top: 6px;
}
.notif-modulo-tag {
    font-size: 0.62rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 1px 6px;
    border-radius: 4px;
    display: inline-block;
}
</style>

<script>
// =============================================================================
// MOTOR JAVASCRIPT DE NOTIFICACIONES CLIENTE
// =============================================================================
let notificacionesCache = [];

function cargarNotificaciones() {
    fetch('api_notificaciones.php?action=listar')
        .then(response => {
            if (!response.ok) throw new Error('Error al conectar con la API de notificaciones');
            return response.json();
        })
        .then(data => {
            if (data && data.exito) {
                notificacionesCache = data.notificaciones || [];
                renderizarNotificaciones(notificacionesCache, data.total_no_leidas || 0);
            }
        })
        .catch(err => {
            console.warn('Centro de notificaciones:', err.message);
        });
}

function renderizarNotificaciones(lista, noLeidas) {
    const badge = document.getElementById('notifBadgeCounter');
    const totalBadge = document.getElementById('notifTotalBadge');
    const cont = document.getElementById('notifContenedorLista');
    if (!cont) return;

    // Actualizar badge del botón
    if (badge) {
        if (noLeidas > 0) {
            badge.innerText = noLeidas > 99 ? '99+' : noLeidas;
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    }

    if (totalBadge) {
        if (noLeidas > 0) {
            totalBadge.innerText = noLeidas + ' nuevas';
            totalBadge.classList.remove('d-none');
        } else {
            totalBadge.classList.add('d-none');
        }
    }

    if (!lista || lista.length === 0) {
        cont.innerHTML = `
            <div class="p-4 text-center text-secondary">
                <i class="bi bi-bell-slash opacity-50 display-6 d-block mb-2"></i>
                <div class="fw-bold text-white small mb-1">Sin notificaciones pendientes</div>
                <div style="font-size: 0.74rem;">Estás al día con todos los comunicados y políticas autorizadas para tu perfil.</div>
            </div>
        `;
        return;
    }

    let html = '';
    lista.forEach(item => {
        const esLeido = Boolean(Number(item.leido));
        const modulo = (item.modulo || 'general').toLowerCase();
        
        let colorBg = 'rgba(147, 51, 234, 0.15)';
        let colorTxt = '#c084fc';
        let moduloLabel = 'POLÍTICAS';

        if (modulo === 'politicas') {
            colorBg = 'rgba(147, 51, 234, 0.2)';
            colorTxt = '#d8b4fe';
            moduloLabel = 'POLÍTICAS';
        } else if (modulo === 'compliance') {
            colorBg = 'rgba(212, 175, 55, 0.2)';
            colorTxt = '#f5df9e';
            moduloLabel = 'COMPLIANCE';
        } else if (modulo === 'tickets') {
            colorBg = 'rgba(6, 182, 212, 0.2)';
            colorTxt = '#67e8f9';
            moduloLabel = 'TICKETS';
        } else {
            colorBg = 'rgba(59, 130, 246, 0.2)';
            colorTxt = '#93c5fd';
            moduloLabel = modulo.toUpperCase();
        }

        const icono = item.icono || 'bi-bell-fill';
        const urlDestino = item.enlace || '#';

        html += `
            <div class="notif-item ${!esLeido ? 'no-leida' : ''}" onclick="onNotifClick(${item.id}, '${urlDestino}', event)">
                <div class="notif-icon-box" style="background: ${colorBg}; color: ${colorTxt};">
                    <i class="bi ${icono}"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="notif-modulo-tag" style="background: ${colorBg}; color: ${colorTxt};">${moduloLabel}</span>
                        <span class="text-secondary" style="font-size: 0.68rem;"><i class="bi bi-clock me-1"></i>${item.tiempo_relativo || 'Hoy'}</span>
                    </div>
                    <div class="fw-bold text-white small text-truncate" style="font-size: 0.82rem;">${item.titulo || 'Notificación'}</div>
                    <div class="text-light text-opacity-75 text-truncate" style="font-size: 0.74rem;">${item.mensaje || ''}</div>
                </div>
                ${!esLeido ? '<span class="notif-dot-unread" title="No leída"></span>' : ''}
            </div>
        `;
    });

    cont.innerHTML = html;
}

function onNotifClick(notifId, destino, evt) {
    if (evt) evt.preventDefault();
    fetch('api_notificaciones.php?action=marcar_leida&id=' + notifId)
        .then(() => {
            if (destino && destino !== '#' && destino !== '') {
                window.location.href = destino;
            } else {
                cargarNotificaciones();
            }
        })
        .catch(() => {
            if (destino && destino !== '#' && destino !== '') {
                window.location.href = destino;
            }
        });
}

function marcarTodasNotifLeidas(evt) {
    if (evt) {
        evt.preventDefault();
        evt.stopPropagation();
    }
    const btn = document.getElementById('btnMarcarTodasLeidas');
    if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando...';

    fetch('api_notificaciones.php?action=marcar_todas')
        .then(res => res.json())
        .then(() => {
            cargarNotificaciones();
            if (btn) btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Marcar leídas';
        })
        .catch(() => {
            if (btn) btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Marcar leídas';
        });
}

// Cargar al iniciar la página y cada 45 segundos
document.addEventListener('DOMContentLoaded', function() {
    cargarNotificaciones();
    setInterval(cargarNotificaciones, 45000);

    const btnCampana = document.getElementById('btnCampanaNotif');
    if (btnCampana) {
        btnCampana.addEventListener('show.bs.dropdown', function() {
            cargarNotificaciones();
        });
    }
});
</script>
