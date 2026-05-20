// GalixMovie Service Worker v5.3 (DHARMA #60 Fix — Force CSS cache refresh for Bouncing Letters)
const CACHE_NAME = 'galix-v5.3';


// Instalar: activar inmediatamente sin pre-cachear nada crítico
self.addEventListener('install', (e) => {
    self.skipWaiting();
});

// Activar: limpiar TODOS los caches anteriores y tomar control inmediato
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    const path = url.pathname.toLowerCase();

    // 🚀 BYPASS TOTAL: Streaming, video, backend PHP → siempre red directa
    if (
        path.match(/\.(m3u8|ts|mp4|mkv|mpd)$/i) ||
        url.search.includes('url=http') ||
        path.includes('/backend/') ||
        path.includes('admin.html') ||
        path.includes('login.html')
    ) {
        return; // El navegador lo maneja directamente
    }

    // 🌐 TMDB y APIs externas → siempre red
    if (url.hostname.includes('tmdb') || url.hostname.includes('googleapis') || url.hostname.includes('api.')) {
        e.respondWith(fetch(e.request).catch(() => new Response('', { status: 503 })));
        return;
    }

    // 📄 HTML, JS y CSS → Network-First (siempre la versión más reciente del servidor)
    // Incluye: archivos .html/.js/.css, raíz '/', y paths de directorio como '/GalixMovie/'
    const isAppFile = path.endsWith('.html') || path.endsWith('.js') || path.endsWith('.css') ||
                      path === '/' || path === '' || path.endsWith('/');
    if (isAppFile) {
        e.respondWith(
            fetch(e.request)
                .then(res => {
                    // Guardar en caché solo si la respuesta es válida
                    if (res && res.status === 200) {
                        const clone = res.clone();
                        caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
                    }
                    return res;
                })
                .catch(() => caches.match(e.request)) // Fallback a caché si no hay red
        );
        return;
    }

    // 🖼️ Imágenes y otros assets → Cache-First (no cambian frecuentemente)
    e.respondWith(
        caches.match(e.request).then(cached => cached || fetch(e.request).then(res => {
            if (res && res.status === 200) {
                const clone = res.clone();
                caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
            }
            return res;
        }))
    );
});
