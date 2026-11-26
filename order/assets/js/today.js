/* Counter Today tab: reprint (marked DUPLICATE), cancel with reason + owner PIN, print day summary. */
(function () {
  'use strict';

  var C = window.Counter;
  var root = document.querySelector('[data-today]');
  if (!root) {
    return;
  }
  var TEXT = JSON.parse(root.getAttribute('data-texts'));
  var REASONS = JSON.parse(root.getAttribute('data-cancel-reasons'));

  function text(key, params) {
    var value = TEXT[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      value = value.split('{' + name + '}').join(String(params[name]));
    });
    return value;
  }

  root.addEventListener('click', async function (event) {
    var button = event.target.closest('button');
    if (!button || button.disabled) {
      return;
    }

    if (button.hasAttribute('data-print-day')) {
      C.print('/counter/print.php?type=day');
      return;
    }

    if (button.hasAttribute('data-reprint')) {
      button.disabled = true;
      try {
        var result = await C.api('/api/order_action.php', { action: 'reprint', order_id: parseInt(button.getAttribute('data-reprint'), 10) });
        await C.print(result.print_url);
      } catch (err) {
        C.toast(err.message, 'error');
      } finally {
        button.disabled = false;
      }
      return;
    }

    if (button.hasAttribute('data-reprint-session')) {
      button.disabled = true;
      try {
        var reprint = await C.api('/api/table_action.php', { action: 'reprint', session_id: parseInt(button.getAttribute('data-reprint-session'), 10) });
        await C.print(reprint.print_url);
      } catch (err) {
        C.toast(err.message, 'error');
      } finally {
        button.disabled = false;
      }
      return;
    }

    if (button.hasAttribute('data-cancel-session')) {
      var billNo = button.getAttribute('data-bill');
      var why = await C.choose({ title: text('cancel_title', { bill: billNo }), options: REASONS, other: true });
      if (!why) {
        return;
      }
      var ownerPin = await C.numpad({ title: C.t('owner_pin'), mask: true, maxLength: 4, minLength: 4 });
      if (!ownerPin) {
        return;
      }
      button.disabled = true;
      try {
        await C.api('/api/table_action.php', {
          action: 'cancel_bill',
          session_id: parseInt(button.getAttribute('data-cancel-session'), 10),
          reason: why,
          pin: ownerPin
        });
        C.toast(text('cancelled', { bill: billNo }), 'success');
        window.setTimeout(function () { window.location.reload(); }, 800);
      } catch (err) {
        C.toast(err.message, 'error');
        button.disabled = false;
      }
      return;
    }

    if (button.hasAttribute('data-cancel')) {
      var bill = button.getAttribute('data-bill');
      var reason = await C.choose({ title: text('cancel_title', { bill: bill }), options: REASONS, other: true });
      if (!reason) {
        return;
      }
      var pin = await C.numpad({ title: C.t('owner_pin'), mask: true, maxLength: 4, minLength: 4 });
      if (!pin) {
        return;
      }
      button.disabled = true;
      try {
        await C.api('/api/order_action.php', {
          action: 'cancel',
          order_id: parseInt(button.getAttribute('data-cancel'), 10),
          reason: reason,
          pin: pin
        });
        C.toast(text('cancelled', { bill: bill }), 'success');
        window.setTimeout(function () { window.location.reload(); }, 800);
      } catch (err) {
        C.toast(err.message, 'error');
        button.disabled = false;
      }
    }
  });
})();
