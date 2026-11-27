/*
 * Admin pages. Plain JavaScript (admin is also used on the Chrome 109 counter PC).
 * - <form data-confirm="…"> asks before submitting (deactivate staff, …)
 * - buttons are disabled after submit so a double tap does not save twice
 * - on phones the menu is a sideways-scrolling row: start it at the current page
 */
(function () {
  'use strict';

  var nav = document.querySelector('.admin-nav');
  var current = nav && nav.querySelector('[aria-current="page"]');
  if (current && nav.scrollWidth > nav.clientWidth) {
    nav.scrollLeft = Math.max(0, current.offsetLeft - 16);
  }

  // <button data-print-page> (posters): no inline onclick, the CSP forbids it.
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-print-page]')) {
      window.print();
    }
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    // Disable after the browser has collected the form data (which includes the clicked button).
    window.setTimeout(function () {
      form.querySelectorAll('button[type="submit"]').forEach(function (button) {
        button.disabled = true;
      });
    }, 0);
  });
})();
