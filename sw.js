// Service Worker para Portal Sistemas Grupo Huerta (PWA)
const CACHE_NAME = 'portal-gh-cache-v1';
const PRECACHE_ASSETS = [
  './manifest.json',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/apple-touch-icon.png'
];

// Instalación: Precarga de recursos clave
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(PRECACHE_ASSETS).catch((err) => {
        console.warn('[PWA] Error en precarga de assets:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

// Activación: Limpieza de cachés antiguas
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Estrategia Network-First con fallback a Caché
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // Evitar interceptar peticiones de scripts de extensiones o orígenes cruzados que no sean HTTPS/HTTP
  if (!url.protocol.startsWith('http')) return;

  event.respondWith(
    fetch(event.request)
      .then((networkResponse) => {
        // Si la respuesta es válida, clonar y actualizar en caché para assets estáticos
        if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            // Guardar solo recursos estáticos o de navegación para no saturar memoria
            if (url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|woff2|woff|ttf)$/i)) {
              cache.put(event.request, responseToCache);
            }
          });
        }
        return networkResponse;
      })
      .catch(() => {
        // En caso de estar sin conexión, devolver desde caché
        return caches.match(event.request).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse;
          }
          // Si es una petición de navegación (HTML) y falló la red
          if (event.request.headers.get('accept')?.includes('text/html')) {
            return caches.match('./login.php') || caches.match('./index.php');
          }
        });
      })
  );
});
