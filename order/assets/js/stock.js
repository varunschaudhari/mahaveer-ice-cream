/* Counter Stock tab: tap an item to switch it between available and sold out (Chrome 109). */
(function () {
  'use strict';

  var C = window.Counter;
  var root = document.querySelector('[data-stock]');
  if (!root) {
    return;
  }

  function show(button, on) {
    button.classList.toggle('is-on', on);
    button.classList.toggle('is-off', !on);
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
    button.querySelector('.stock-toggle__state').textContent = root.getAttribute(on ? 'data-on' : 'data-off');
  }

  root.addEventListener('click', async function (event) {
    var button = event.target.closest('[data-item]');
    if (!button || button.disabled) {
      return;
    }
    var wasOn = button.getAttribute('aria-pressed') === 'true';
    button.disabled = true;
    show(button, !wasOn);
    try {
      await C.api('/api/stock.php', { item_id: parseInt(button.getAttribute('data-item'), 10), available: !wasOn });
    } catch (err) {
      show(button, wasOn);
      C.toast(err.message, 'error');
    } finally {
      button.disabled = false;
    }
  });
})();
