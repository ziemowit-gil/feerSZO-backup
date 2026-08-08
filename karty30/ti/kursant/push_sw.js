// Service Worker dla Web Push — panel kursanta TI
'use strict';

self.addEventListener('push', function(e) {
    var data = {};
    try { data = e.data ? e.data.json() : {}; } catch (_) {}
    var title = data.title || 'Panel kursanta';
    var body  = data.body  || 'Masz nowe informacje w panelu.';
    var url   = data.url   || 'index.php';
    e.waitUntil(
        self.registration.showNotification(title, {
            body:  body,
            icon:  data.icon  || '/favicon.ico',
            badge: data.badge || '/favicon.ico',
            data:  { url: url }
        })
    );
});

self.addEventListener('notificationclick', function(e) {
    e.notification.close();
    var url = (e.notification.data && e.notification.data.url) ? e.notification.data.url : 'index.php';
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            for (var i = 0; i < list.length; i++) {
                if (list[i].url.indexOf('index.php') !== -1 && 'focus' in list[i]) {
                    return list[i].focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});
