/*
 * Customer order status: checks every 10 s while the page is visible (SPEC §6.7, ARCHITECTURE §7)
 * and stops once the order is finished. Cancel is offered only while the order is Received.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-status]');
  if (!root) {
    return;
  }
  var id = root.getAttribute('data-id');
  var key = root.getAttribute('data-key');
  var HELP = JSON.parse(root.getAttribute('data-help'));
  var FINAL = ['completed', 'cancelled', 'no_show'];
  var STEPS = ['received', 'preparing', 'ready', 'completed'];
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var label = root.querySelector('[data-status-label]');
  var cancelButton = root.querySelector('[data-cancel-order]');
  var errorEl = root.querySelector('[data-status-error]');
  var current = root.getAttribute('data-current');
  var timer = null;

  function show(status, text, canCancel) {
    current = status;
    label.textContent = text;
    label.className = 'status-label status-' + status;
    root.querySelector('[data-status-help]').textContent = HELP[status] || '';
    var reached = STEPS.indexOf(status);
    root.querySelectorAll('[data-step]').forEach(function (step) {
      var index = STEPS.indexOf(step.getAttribute('data-step'));
      step.classList.toggle('is-done', reached >= 0 && index <= reached);
      step.classList.toggle('is-current', index === reached);
    });
    cancelButton.hidden = !canCancel;
  }

  async function check() {
    try {
      var response = await fetch('/api/order_status.php?id=' + encodeURIComponent(id) + '&k=' + encodeURIComponent(key), {
        headers: { 'Accept': 'application/json' }
      });
      var data = await response.json();
      if (data.ok) {
        show(data.data.status, data.data.label, data.data.can_cancel);
      }
    } catch (e) { /* try again next time */ }
    schedule();
  }

  function schedule() {
    window.clearTimeout(timer);
    if (FINAL.indexOf(current) === -1 && !document.hidden) {
      timer = window.setTimeout(check, 10000);
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      window.clearTimeout(timer);
    } else {
      check();
    }
  });

  cancelButton.addEventListener('click', async function () {
    if (!window.confirm(root.getAttribute('data-confirm'))) {
      return;
    }
    cancelButton.disabled = true;
    try {
      var response = await fetch('/api/customer_cancel.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ id: id, k: key })
      });
      var data = await response.json();
      if (data.ok) {
        show('cancelled', data.data.label, false);
        errorEl.hidden = true;
      } else {
        errorEl.textContent = data.message;
        errorEl.hidden = false;
        check();
      }
    } catch (e) {
      cancelButton.disabled = false;
    }
  });

  show(current, label.textContent, !cancelButton.hidden);
  schedule();
})();
