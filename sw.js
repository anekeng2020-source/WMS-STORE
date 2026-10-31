/* PWA service worker — required for "install", and lets the app shell open offline.
   Strategy:
   - page navigations + same-origin .js/.css: NETWORK FIRST (fresh after every deploy, cache = offline fallback)
   - icons / images / fonts: CACHE FIRST
   - API / data endpoints (API_PATTERNS): never touched → always live
   Bump VERSION whenever you change this file so old caches are dropped. */
const VERSION = 'mywms-v2';
const SHELL = ['./', 'index.html', 'mobile.html', 'pos.html',
  'manifest.webmanifest', 'manifest-mobile.webmanifest', 'manifest-pos.webmanifest',
  'install.js', 'icon.svg', 'icon-192.png', 'icon-512.png', 'icon-180.png', 'images/logo.svg'];
const API_PATTERNS = [/api_supabase\.php$/, /\/api\.php$/, /\/api\//, /supabase\.co\//];   // live data only — never cached

self.addEventListener('install', (e) => {
  // add files one by one so a single missing file does not abort the whole install
  e.waitUntil(
    caches.open(VERSION)
      .then((c) => Promise.all(SHELL.map((u) => c.add(u).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

function networkFirst(req, cacheKey) {
  return fetch(req)
    .then((res) => {
      if (res.ok) {
        const copy = res.clone();
        caches.open(VERSION).then((c) => c.put(cacheKey || req, copy));
      }
      return res;
    })
    .catch(() => caches.match(cacheKey || req).then((hit) => hit || Response.error()));
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (API_PATTERNS.some((re) => re.test(url.pathname))) return;

  const isFont = /fonts\.(googleapis|gstatic)\.com$/.test(url.hostname);
  if (url.origin !== location.origin && !isFont) return;

  if (req.mode === 'navigate') {
    // three pages share this scope (index / mobile / pos): cache each under its own file name,
    // and only fall back to index.html when that page has never been cached
    var page = url.pathname.split('/').pop() || 'index.html';
    e.respondWith(
      fetch(req)
        .then(function (res) {
          if (res.ok) { var copy = res.clone(); caches.open(VERSION).then(function (c) { c.put(page, copy); }); }
          return res;
        })
        .catch(function () { return caches.match(page).then(function (hit) { return hit || caches.match('index.html'); }).then(function (r) { return r || Response.error(); }); })
    );
    return;
  }
  if (url.origin === location.origin && /\.(js|css|webmanifest)$/.test(url.pathname)) {
    e.respondWith(networkFirst(req));
    return;
  }
  // cache-first only for static files (icons, images, fonts); anything else goes straight to the network untouched
  if (!isFont && !/\.(png|jpe?g|gif|webp|svg|ico|woff2?|ttf|otf)$/i.test(url.pathname)) return;
  e.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok || res.type === 'opaque') {
        const copy = res.clone();
        caches.open(VERSION).then((c) => c.put(req, copy));
      }
      return res;
    }).catch(() => Response.error()))                          // offline / failed: a normal network error, not an uncaught exception
  );
});
