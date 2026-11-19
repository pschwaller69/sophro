// Service worker : permet l'installation et l'affichage hors connexion du dernier contenu consulté.
const VERSION = 'sophro-v2';
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

// Notification du matin : le serveur envoie un simple signal, on va chercher le contenu du jour.
self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let d = { title: 'Votre pause du jour est arrivée', body: 'Prenez un instant pour vous.', url: '/' };
    try { d = await (await fetch('/api/today.php', { cache: 'no-store' })).json(); } catch (err) { /* message par défaut */ }
    if (self.navigator.setAppBadge) { try { await self.navigator.setAppBadge(1); } catch (err) {} }
    await self.registration.showNotification(d.title, {
      body: d.body,
      icon: '/assets/icon-192.png',
      badge: '/assets/badge-96.png',
      image: d.image || undefined,
      tag: 'sophro-du-jour',
      data: { url: d.url || '/' },
    });
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil((async () => {
    if (self.navigator.clearAppBadge) { try { await self.navigator.clearAppBadge(); } catch (err) {} }
    const wins = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) { if ('focus' in w) { w.navigate(url).catch(() => {}); return w.focus(); } }
    return clients.openWindow(url);
  })());
});
