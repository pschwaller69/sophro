// Service worker : permet l'installation et l'affichage hors connexion du dernier contenu consulté.
const VERSION = 'sophro-v1';
const STATIC = ['/', '/assets/style.css', '/assets/app.js', '/assets/icon.svg', '/assets/fonts/Lora.ttf', '/assets/fonts/Lora-Italic.ttf', '/manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.startsWith('/admin')) return;     // l'admin n'est jamais mis en cache
  if (req.headers.has('range')) return;              // lecture audio

  // Pages : réseau d'abord (contenu du jour toujours à jour), cache si hors connexion
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req)
        .then((res) => { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); return res; })
        .catch(() => caches.match(req).then((r) => r || caches.match('/')))
    );
    return;
  }

  // Fichiers statiques et images : cache d'abord, mise à jour en arrière-plan
  if (url.pathname.startsWith('/assets/') || /\.(jpg|jpeg|png|webp|gif)$/i.test(url.pathname)) {
    e.respondWith(
      caches.match(req).then((cached) => {
        const net = fetch(req).then((res) => {
          if (res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); }
          return res;
        }).catch(() => cached);
        return cached || net;
      })
    );
  }
});
