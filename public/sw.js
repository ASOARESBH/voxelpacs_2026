/**
 * VOXEL PACS — Service Worker (PWA)
 * Estratégia: Network-first para /estudos (dados sempre frescos),
 * Cache-first para assets estáticos (CSS, JS, fontes, imagens).
 */

const CACHE_NAME   = 'voxelpacs-worklist-v2';
const STATIC_CACHE = 'voxelpacs-static-v2';

// Assets estáticos a pré-cachear na instalação
const PRECACHE_ASSETS = [
    '/assets/css/pacs.css',
    '/assets/css/mobile-responsive.css',
    '/assets/img/pwa-icon-192.png',
    '/assets/img/pwa-icon-512.png',
    '/assets/img/logo-voxel-pacs.png',
];

// ── Instalação ────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// ── Ativação ──────────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(k => k !== CACHE_NAME && k !== STATIC_CACHE)
                    .map(k => caches.delete(k))
            )
        ).then(() => self.clients.claim())
    );
});

// ── Fetch ─────────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Ignorar requisições não-GET e cross-origin
    if (event.request.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    // Assets estáticos: Cache-first
    if (url.pathname.startsWith('/assets/')) {
        event.respondWith(
            caches.match(event.request).then(cached => {
                if (cached) return cached;
                return fetch(event.request).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(STATIC_CACHE).then(c => c.put(event.request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // Páginas da worklist (/estudos*): Network-first
    // Dados médicos devem ser sempre atualizados — cache apenas como fallback offline
    if (url.pathname.startsWith('/estudos') || url.pathname === '/') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
                    }
                    return response;
                })
                .catch(() => caches.match(event.request))
        );
        return;
    }

    // Demais rotas: Network-first sem cache
    event.respondWith(fetch(event.request).catch(() => caches.match(event.request)));
});
