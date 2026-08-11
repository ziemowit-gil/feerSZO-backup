'use strict';

self.addEventListener('push', function (e) {
  var data = {};
  try { data = e.data ? e.data.json() : {}; } catch (_) {}
  var title = data.title || 'Panel Kursanta';
  var body  = data.body  || 'Masz nowe informacje w panelu kursanta.';
  var url   = data.url   || './';
  e.waitUntil(
    self.registration.showNotification(title, {
      body:     body,
      icon:     data.icon  || './icons/icon-192x192.png',
      badge:    data.badge || './icons/icon-192x192.png',
      tag:      data.tag   || 'kursant',
      renotify: true,
      data:     { url: url },
    })
  );
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var target = (e.notification.data && e.notification.data.url) ? e.notification.data.url : './';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        var c = list[i];
        if ('focus' in c) {
          try { c.navigate(target); } catch (_) {}
          return c.focus();
        }
      }
      return clients.openWindow(target);
    })
  );
});
