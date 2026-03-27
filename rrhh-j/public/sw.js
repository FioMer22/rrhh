// Service Worker — JR RRHH
// Versión con soporte Web Push

const CACHE_NAME = 'jr-rrhh-v3';
const ASSETS = [
  '/rrhh-j/public/assets/css/jr.css',
  '/rrhh-j/public/assets/img/logo-jr.png',
];

// ── Instalación ───────────────────────────────────────────────────────────────
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: network-first para PHP, cache-first para assets ───────────────────
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;

  if (url.pathname.endsWith('.php') || url.pathname.endsWith('/')) {
    // PHP → network first
    e.respondWith(
      fetch(e.request).catch(() => caches.match(e.request))
    );
  } else {
    // Assets → cache first
    e.respondWith(
      caches.match(e.request).then(cached => cached || fetch(e.request))
    );
  }
});

// ── Push: mostrar notificación ────────────────────────────────────────────────
self.addEventListener('push', e => {
  if (!e.data) return;

  let data = {};
  try { data = e.data.json(); } catch { data = { title: 'JR RRHH', body: e.data.text() }; }

  const title   = data.title || 'JR RRHH';
  const options = {
    body:    data.body  || '',
    icon:    data.icon  || '/rrhh-j/public/assets/img/jr-icon-192.png',
    badge:   data.badge || '/rrhh-j/public/assets/img/jr-icon-192.png',
    data:    { url: data.url || '/rrhh-j/public/dashboard.php' },
    vibrate: [200, 100, 200],
    requireInteraction: false,
  };

  e.waitUntil(self.registration.showNotification(title, options));
});

// ── Click en notificación → abrir la URL correspondiente ─────────────────────
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = e.notification.data?.url || '/rrhh-j/public/dashboard.php';

  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      // Si ya hay una pestaña abierta del sistema, la enfoca
      for (const client of list) {
        if (client.url.includes('rrhh-j') && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      // Si no, abre una nueva
      return clients.openWindow(url);
    })
  );
});