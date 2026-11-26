/*
 * Public website. Location page: the Google Map loads only after a tap (developer decision M8),
 * so the page stays fast and Google is not contacted before the visitor asks for the map.
 * Without JavaScript the button is a normal link to Google Maps.
 */
(function () {
  'use strict';

  var map = document.querySelector('[data-map]');
  if (!map) {
    return;
  }
  map.querySelector('[data-map-load]').addEventListener('click', function (event) {
    event.preventDefault();
    var frame = document.createElement('iframe');
    frame.src = map.getAttribute('data-map-src');
    frame.title = map.getAttribute('data-map-title');
    frame.className = 'map__frame';
    frame.setAttribute('loading', 'lazy');
    frame.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
    frame.setAttribute('allowfullscreen', '');
    map.textContent = '';
    map.appendChild(frame);
  });
})();
