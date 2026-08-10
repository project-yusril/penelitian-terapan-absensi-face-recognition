/* Service Worker — Web Push dashboard Absensi Mahasiswa.
 * Menampilkan notifikasi push & menangani klik untuk membuka dashboard.
 */

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let payload = { title: 'Notifikasi', body: '', data: {} };

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (e) {
            payload.body = event.data.text();
        }
    }

    const title = payload.title || 'Absensi Mahasiswa';
    const options = {
        body: payload.body || '',
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        data: payload.data || {},
        tag: payload.data && payload.data.tag ? payload.data.tag : undefined,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl =
        (event.notification.data && event.notification.data.url) || '/dashboard';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if ('focus' in client) {
                        client.navigate(targetUrl);
                        return client.focus();
                    }
                }
                if (self.clients.openWindow) {
                    return self.clients.openWindow(targetUrl);
                }
            })
    );
});
