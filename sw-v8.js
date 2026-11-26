const CACHE_NAME = 'unipresenze-shell-v2026.11.18-1';
const SHELL_URLS = ['./', './index.html', './manifest.webmanifest', './icon-512.png', './apple-touch-icon.png'];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL_URLS)).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('unipresenze-shell-') && key !== CACHE_NAME).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.endsWith('/api.php') || url.pathname.endsWith('/lezioni.ics')) return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).then(response => {
      const copy = response.clone();
      caches.open(CACHE_NAME).then(cache => cache.put('./index.html', copy));
      return response;
    }).catch(() => caches.match('./index.html').then(response => response || caches.match('./'))));
    return;
  }
  event.respondWith(caches.match(request).then(cached => cached || fetch(request).then(response => {
    if (response.ok) caches.open(CACHE_NAME).then(cache => cache.put(request, response.clone()));
    return response;
  })));
});

self.addEventListener('push', event => {
  let data = {};
  if (event.data) { try { data = event.data.json(); } catch (e) { data = { title: 'UniPresenze', body: event.data.text() }; } }
  const receipt = data._push_receipt;
  const workerEventAt = new Date().toISOString();
  const report = (stage, error = '') => {
    if (!receipt) return Promise.resolve();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 1500);
    return fetch('api.php?action=push_receipt', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ receipt, stage, error, worker_event_at: workerEventAt }),
      signal: controller.signal
    }).catch(() => {}).finally(() => clearTimeout(timeout));
  };
  const title = data.title || 'UniPresenze';
  const notification = {
    body: data.body || 'Promemoria frequenze', icon: data.icon || './icon-512.png',
    badge: data.badge || './apple-touch-icon.png', data: { url: data.url || './' },
    vibrate: [100, 50, 100], renotify: true, tag: data.tag || 'unipresenze-' + Date.now()
  };
  // Showing the notification must not wait for the diagnostic API request.
  const showTask = self.registration.showNotification(title, notification);
  const receiptTask = report('received');
  const handling = Promise.allSettled([showTask, receiptTask]).then(results => {
    const shown = results[0];
    return shown.status === 'fulfilled'
      ? report('shown')
      : report('show_error', String(shown.reason || 'showNotification failed'));
  });
  event.waitUntil(handling);
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data && event.notification.data.url || './', self.location.origin).href;
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
    for (const client of clientList) if (client.url.startsWith(self.location.origin) && 'focus' in client) return client.focus();
    return clients.openWindow ? clients.openWindow(targetUrl) : undefined;
  }));
});
