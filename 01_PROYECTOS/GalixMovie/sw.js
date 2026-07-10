const CACHE_NAME = 'galix-movie-v6.4'; // Bump v6.4: Bypass nativo para imágenes (evita cuello de botella SW)
const MAX_CACHE_ITEMS = 200;

self.addEventListener('install', (e) => {
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.map(k => {
                if (k !== CACHE_NAME) return caches.delete(k);
            })))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    const path = url.pathname.toLowerCase();

    // BYPASS: streaming, backend, admin, y el propio sw.js
    const isBackend = path.includes('/backend/');
    const isAssetProxy = path.includes('asset_proxy.php');
    
    // BYPASS NATIVO PARA IMÁGENES:
    // El SW colapsa con 100+ peticiones "eager" simultáneas.
    // Dejar que el navegador gestione su propio HTTP cache y concurrencia.
    const isImage = e.request.destination === 'image' || 
                    path.match(/\.(jpg|jpeg|png|gif|webp|svg)$/i) || 
                    url.hostname.includes('image.tmdb.org') || 
                    isAssetProxy;

    if (
        isImage ||
        path.match(/\.(m3u8|ts|mp4|mkv|mpd)$/i) ||
        (isBackend && !isAssetProxy) ||
        path.includes('admin.html') ||
        path.includes('login.html') ||
        path.includes('sw.js')
    ) {
        return;
    }

    // External APIs (JSON/Metadatos): network-only with failover
    if (url.hostname.includes('tmdb') || url.hostname.includes('googleapis') || url.hostname.includes('api.')) {
        e.respondWith(
            fetch(e.request).catch(() => new Response('', { status: 503 }))
        );
        return;
    }

    // App files (HTML/JS/CSS): network-first, cache fallback
    const isAppFile = path.endsWith('.html') || path.endsWith('.js') || path.endsWith('.css') ||
                      path === '/' || path === '' || path.endsWith('/');
    if (isAppFile) {
        e.respondWith(
            fetch(e.request)
                .then(res => {
                    if (res && res.status === 200) {
                        const clone = res.clone();
                        caches.open(CACHE_NAME).then(c => {
                            trimCache(c);
                            c.put(e.request, clone);
                        });
                    }
                    return res;
                })
                .catch(() => caches.match(e.request).then(cached => {
                    if (cached) return cached;
                    return new Response('Offline', { status: 503 });
                }))
        );
        return;
    }

    // Images & static assets: STRICT Cache-First (no network request if cached)
    e.respondWith(
        caches.match(e.request).then(cached => {
            if (cached) {
                return cached;
            }
            return fetch(e.request).then(res => {
                if (res && res.status === 200) {
                    const clone = res.clone();
                    caches.open(CACHE_NAME).then(c => {
                        trimCache(c);
                        c.put(e.request, clone);
                    });
                }
                return res;
            }).catch(() => new Response('', { status: 503 }));
        })
    );
});

function trimCache(cache) {
    cache.keys().then(keys => {
        if (keys.length > MAX_CACHE_ITEMS) {
            cache.delete(keys[0]).then(() => trimCache(cache));
        }
    });
}
