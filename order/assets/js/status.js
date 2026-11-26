/*
 * Customer order status (takeaway and bulk): checks every 10 s while the page is visible
 * (SPEC §6.7, ARCHITECTURE §7) and stops once the order is finished. The server sends the label,
 * the help line and whether the customer may still cancel; the progress steps come from the page.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-status]');
  if (!root) {
    return;
  }
  var id = root.getAttribute('data-id');
  var key = root.getAttribute('data-key');
  var STEPS = JSON.parse(root.getAttribute('data-steps'));
  var FINAL = ['completed', 'cancelled', 'no_show'];
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var label = root.querySelector('[data-status-label]');
  var help = root.querySelector('[data-status-help]');
  var cancelButton = root.querySelector('[data-cancel-order]');
  var errorEl = root.querySelector('[data-status-error]');
  var current = root.getAttribute('data-current');
  var timer = null;

  function show(data) {
    current = data.status;
    label.textContent = data.label;
    label.className = 'status-label status-' + data.status;
    if (data.help !== undefined) {
      help.textContent = data.help;
    }
    var reached = STEPS.indexOf(data.status);
    root.querySelectorAll('[data-step]').forEach(function (step) {
      var index = STEPS.indexOf(step.getAttribute('data-step'));
      step.classList.toggle('is-done', reached >= 0 && index <= reached);
      step.classList.toggle('is-current', index === reached);
    });
    cancelButton.hidden = !data.can_cancel;
  }

  async function check() {
    try {
      var response = await fetch('/api/order_status.php?id=' + encodeURIComponent(id) + '&k=' + encodeURIComponent(key), {
        headers: { 'Accept': 'application/json' }
      });
      var data = await response.json();
      if (data.ok) {
        show(data.data);
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
      if (!data.ok) {
        errorEl.textContent = data.message;
        errorEl.hidden = false;
      } else {
        errorEl.hidden = true;
      }
      check();
    } catch (e) {
      cancelButton.disabled = false;
    }
  });

  show({ status: current, label: label.textContent, can_cancel: !cancelButton.hidden });
  schedule();
})();
