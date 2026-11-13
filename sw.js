const CACHE_NAME = 'kcc-docms-static-v9';
const STATIC_ASSETS = [
  './manifest.webmanifest',
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/icons/kcc-logo.png',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/icons/icon-maskable-512.png',
  './assets/icons/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Never cache API calls or file downloads/previews
  if (url.pathname.includes('/api/')) {
    return;
  }

  // Network-first for HTML/navigation so CSRF token and session state stay fresh
  if (req.mode === 'navigate' || url.pathname.endsWith('index.php') || url.pathname.endsWith('/')) {
    event.respondWith(
      fetch(req).catch(() => new Response(
        '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline - KCC DOCMS</title><style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;text-align:center}.card{background:#1e293b;padding:28px;border-radius:14px;max-width:380px}button{background:#2563eb;color:#fff;border:none;padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;margin-top:12px}</style></head><body><div class="card"><h2>You are offline</h2><p>Connect to the network or Wi-Fi where KCC DOCMS is hosted, then tap Retry.</p><button onclick="location.reload()">Retry</button></div></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      ))
    );
    return;
  }

  // Stale-while-revalidate for static CSS/JS/icons/manifest
  event.respondWith(
    caches.match(req).then((cached) => {
      const fetchPromise = fetch(req).then((networkRes) => {
        if (networkRes && networkRes.ok) {
          const clone = networkRes.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, clone));
        }
        return networkRes;
      }).catch(() => cached);

      return cached || fetchPromise;
    })
  );
});
