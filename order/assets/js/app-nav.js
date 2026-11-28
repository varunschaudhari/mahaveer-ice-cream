/*
 * The owner + admin menu on phones and tablets (app_nav.php): ☰ slides the menu in, the close
 * button, a tap on the dimmed page or Escape closes it. On wide screens the menu is always shown
 * (app-nav.css) and this does nothing. Plain JavaScript (admin also runs on Chrome 109).
 */
(function () {
  'use strict';

  var menu = document.querySelector('[data-nav]');
  var openButton = document.querySelector('[data-nav-open]');
  var scrim = document.querySelector('[data-nav-scrim]');
  if (!menu || !openButton) {
    return;
  }
  var wide = window.matchMedia('(min-width: 1024px)');

  function setOpen(open) {
    document.body.classList.toggle('nav-open', open);
    openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (scrim) {
      scrim.hidden = !open;
    }
    if (open) {
      var current = menu.querySelector('[aria-current="page"]') || menu.querySelector('a');
      if (current) {
        current.scrollIntoView({ block: 'nearest' });
        current.focus({ preventScroll: true });
      }
    } else if (!wide.matches) {
      openButton.focus({ preventScroll: true });
    }
  }

  function isOpen() {
    return document.body.classList.contains('nav-open');
  }

  openButton.addEventListener('click', function () {
    setOpen(true);
  });
  document.querySelectorAll('[data-nav-close]').forEach(function (el) {
    el.addEventListener('click', function () {
      setOpen(false);
    });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isOpen()) {
      setOpen(false);
    }
  });
  // Rotating a tablet to a wide layout: the side menu takes over, so the drawer state is reset.
  var reset = function () {
    if (wide.matches && isOpen()) {
      setOpen(false);
    }
  };
  if (wide.addEventListener) {
    wide.addEventListener('change', reset);
  } else if (wide.addListener) {
    wide.addListener(reset);
  }
})();
