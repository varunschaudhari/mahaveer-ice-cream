/*
 * Service worker for the owner's phone, registered by owner/ with scope /owner/ (ARCHITECTURE §9).
 * Only two jobs:
 *  - push: show the notification (title/body come in Marathi and English; the language is the
 *    one the owner page last used on this phone, kept in Cache Storage by owner.js)
 *  - offline: when there is no internet, show the "No internet" page instead of Chrome's error
 * Order data is never cached: it must always be live. Change CACHE when offline.php changes.
 */
'use strict';

var CACHE = 'mahavir-owner-v1';
var PREFS_CACHE = 'mahavir-prefs';
var PREFS_URL = '/__prefs/owner';
var OFFLINE_URL = '/owner/offline.php';
var OFFLINE_FILES = [OFFLINE_URL, '/assets/css/base.css', '/assets/css/owner.css'];
var ICON = '/assets/img/icon-192.png';
var BADGE = '/assets/img/badge-72.png';

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE)
      .then(function (cache) {
        return cache.addAll(OFFLINE_FILES.map(function (url) { return new Request(url, { cache: 'reload' }); }));
      })
      // The offline page is a nice-to-have: if storing it fails, notifications must still work.
      .catch(function () {})
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys.filter(function (key) {
          return key.indexOf('mahavir-owner-') === 0 && key !== CACHE;
        }).map(function (key) { return caches.delete(key); }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(function () {
      return caches.match(OFFLINE_URL, { cacheName: CACHE });
    }));
    return;
  }
  // The offline page's own styles (requested without ?v=, so they match the cache).
  var url = new URL(request.url);
  if (url.origin === self.location.origin && url.search === '' && OFFLINE_FILES.indexOf(url.pathname) > 0) {
    event.respondWith(fetch(request).catch(function () {
      return caches.match(url.pathname, { cacheName: CACHE });
    }));
  }
});

function language() {
  return caches.open(PREFS_CACHE)
    .then(function (cache) { return cache.match(PREFS_URL); })
    .then(function (response) { return response ? response.json() : {}; })
    .then(function (prefs) { return prefs.lang === 'en' ? 'en' : 'mr'; })
    .catch(function () { return 'mr'; });
}

/** Only pages of the owner app may be opened from a notification. */
function safeUrl(url) {
  return typeof url === 'string' && /^\/owner\/(\?order=\d+)?$/.test(url) ? url : '/owner/';
}

self.addEventListener('push', function (event) {
  var message = {};
  try {
    message = event.data ? event.data.json() : {};
  } catch (err) {
    message = {};
  }

  event.waitUntil(language().then(function (lang) {
    function pick(value) {
      if (typeof value === 'string') {
        return value;
      }
      return value ? (value[lang] || value.mr || value.en || '') : '';
    }
    var options = {
      body: pick(message.body),
      icon: ICON,
      badge: BADGE,
      lang: lang,
      vibrate: [200, 100, 200],
      data: { url: safeUrl(message.url) }
    };
    if (message.tag) {
      options.tag = String(message.tag);
      options.renotify = true; // a repeat (e.g. a second bill request) still rings
    }
    return self.registration.showNotification(pick(message.title), options);
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var path = safeUrl(event.notification.data && event.notification.data.url);
  var url = new URL(path, self.location.origin).href;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
      for (var i = 0; i < windows.length; i++) {
        var client = windows[i];
        if (new URL(client.url).pathname === '/owner/' && 'focus' in client) {
          client.postMessage({ type: 'open', url: path }); // the open page scrolls to the order
          return client.focus();
        }
      }
      return self.clients.openWindow(url);
    })
  );
});
