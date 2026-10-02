<!-- Banner Flotante de Instalación Móvil PWA -->
<div id="pwaInstallBanner" style="display: none; position: fixed; bottom: 20px; right: 20px; z-index: 99999; max-width: 380px; width: calc(100% - 40px); background: linear-gradient(135deg, rgba(8, 24, 48, 0.96) 0%, rgba(5, 15, 32, 0.98) 100%); border: 1px solid rgba(56, 189, 248, 0.5); box-shadow: 0 12px 35px rgba(0,0,0,0.65), 0 0 25px rgba(2, 132, 199, 0.3); border-radius: 18px; padding: 14px 18px; backdrop-filter: blur(12px); color: #ffffff; animation: pwaSlideUp 0.35s ease;">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: #fff; box-shadow: 0 0 15px rgba(56, 189, 248, 0.5); flex-shrink: 0;">
                <i class="bi bi-phone-fill"></i>
            </div>
            <div>
                <div class="fw-bold text-white small" style="letter-spacing: -0.3px;">Instalar App en Celular</div>
                <div class="text-secondary small" style="font-size: 0.75rem; line-height: 1.2;">Accede directo desde tu pantalla de inicio sin usar el navegador.</div>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white small" onclick="cerrarBannerPWA()" aria-label="Cerrar" style="font-size: 0.7rem;"></button>
    </div>
    <div class="mt-3 d-flex gap-2">
        <button type="button" id="btnInstalarPWA" class="btn btn-sm w-100 rounded-pill fw-bold text-white shadow-sm" style="background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%); border: 1px solid rgba(56, 189, 248, 0.5);" onclick="ejecutarInstalacionPWA()">
            <i class="bi bi-download me-1"></i> Instalar Aplicación
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 text-light border-opacity-50" onclick="cerrarBannerPWA()">
            Ahora no
        </button>
    </div>
</div>

<!-- Modal Guía para iPhone / Safari (iOS) -->
<div class="modal fade" id="modalInstalarIos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="background: #0d1e38; color: #f8fafc; border-radius: 20px; border: 1px solid rgba(56, 189, 248, 0.3);">
            <div class="modal-header border-bottom border-secondary border-opacity-25 pb-3">
                <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
                    <i class="bi bi-apple text-info"></i> Instalar en iPhone / iPad
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-3">
                    <div style="width: 64px; height: 64px; border-radius: 16px; background: #0284c7; margin: 0 auto; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #fff; box-shadow: 0 0 20px rgba(56, 189, 248, 0.4);">
                        <i class="bi bi-box-arrow-up"></i>
                    </div>
                </div>
                <h6 class="fw-bold text-white mb-2">Para instalar en tu dispositivo Apple:</h6>
                <ol class="text-start text-secondary small ps-3 mb-0" style="line-height: 1.8;">
                    <li>Toca el botón <strong class="text-white">Compartir</strong> <i class="bi bi-box-arrow-up text-info"></i> en la barra inferior de Safari.</li>
                    <li>Baja en el menú y selecciona <strong class="text-white">"Agregar a pantalla de inicio"</strong> <i class="bi bi-plus-square text-info"></i>.</li>
                    <li>Toca <strong class="text-white">"Agregar"</strong> en la esquina superior derecha.</li>
                </ol>
            </div>
            <div class="modal-footer border-top border-secondary border-opacity-25 pt-2">
                <button type="button" class="btn btn-info btn-sm rounded-pill px-4 fw-bold text-dark w-100" data-bs-dismiss="modal">Entendido</button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes pwaSlideUp {
    from { transform: translateY(50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
</style>

<script>
// Registro de Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
            .then(reg => console.log('[PWA] Service Worker registrado:', reg.scope))
            .catch(err => console.log('[PWA] Error al registrar Service Worker:', err));
    });
}

// Detección de instalación
let deferredPwaPrompt = null;
const isIosDevice = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
const isInStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPwaPrompt = e;
    // Mostrar banner si no está en modo standalone y no ha sido descartado hoy
    if (!isInStandalone && !sessionStorage.getItem('pwa_banner_dismissed')) {
        const banner = document.getElementById('pwaInstallBanner');
        if (banner) banner.style.display = 'block';
    }
});

function ejecutarInstalacionPWA() {
    if (deferredPwaPrompt) {
        deferredPwaPrompt.prompt();
        deferredPwaPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('[PWA] El usuario aceptó la instalación');
            }
            deferredPwaPrompt = null;
            cerrarBannerPWA();
        });
    } else if (isIosDevice) {
        const modalEl = document.getElementById('modalInstalarIos');
        if (modalEl) {
            const modalIos = new bootstrap.Modal(modalEl);
            modalIos.show();
        }
    } else {
        alert('Para instalar la aplicación, toca los tres puntos del navegador (⋮) y selecciona "Instalar aplicación" o "Agregar a la pantalla principal".');
        cerrarBannerPWA();
    }
}

function cerrarBannerPWA() {
    const banner = document.getElementById('pwaInstallBanner');
    if (banner) banner.style.display = 'none';
    sessionStorage.setItem('pwa_banner_dismissed', 'true');
}

// En iOS, si no está instalada, mostrar el banner tras 3 segundos
if (isIosDevice && !isInStandalone && !sessionStorage.getItem('pwa_banner_dismissed')) {
    setTimeout(() => {
        const banner = document.getElementById('pwaInstallBanner');
        if (banner) banner.style.display = 'block';
    }, 3000);
}
</script>
