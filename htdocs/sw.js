/* Service worker: оформление из кэша, заглушка без интернета и пуш-уведомления.
   Страницы всегда грузятся из сети — расписание должно быть актуальным. */
const VERSION = 'sail-v29';
const BASE = self.location.pathname.replace(/sw\.js$/, '');
const ASSETS = ['assets/app.css?v=29', 'assets/app.js?v=29', 'assets/icons/icon-192.png?v=20', 'assets/icons/badge-96.png?v=20', 'offline.html'].map(p => BASE + p);

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(ASSETS)).catch(() => {}).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET' || new URL(req.url).origin !== self.location.origin) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(BASE + 'offline.html')));
    return;
  }
  if (req.url.includes('/assets/')) {
    e.respondWith(caches.match(req).then(hit => hit || fetch(req).then(res => {
      if (res.ok) { const copy = res.clone(); caches.open(VERSION).then(c => c.put(req, copy)); }
      return res;
    })));
  }
});

/* ---------- пуш-уведомления: приходят, даже когда сайт закрыт ---------- */
self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'ITMO Sail', {
    body: d.body || 'Новое уведомление',
    icon: BASE + 'assets/icons/icon-192.png?v=20',
    badge: BASE + 'assets/icons/badge-96.png?v=20',
    tag: d.tag || undefined,
    renotify: !!d.tag,
    lang: 'ru',
    data: { url: d.url || BASE + 'notifications.php' },
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || BASE + 'notifications.php', self.location.origin).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const c of list) {
      if (c.url.startsWith(self.location.origin) && 'focus' in c) {
        return c.focus().then(w => (w && 'navigate' in w) ? w.navigate(url) : null);
      }
    }
    return self.clients.openWindow(url);
  }));
});

/* Браузер сам обновил подписку — сообщим серверу при следующем открытии сайта */
self.addEventListener('pushsubscriptionchange', e => {
  e.waitUntil(self.clients.matchAll({ type: 'window' }).then(list => list.forEach(c => c.postMessage({ type: 'resubscribe' }))));
});
