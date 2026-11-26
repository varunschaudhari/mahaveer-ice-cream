/*
 * Table page (t.php): the table's current orders with live status (every 10 s while visible)
 * and the Call staff / Request bill buttons (SPEC §7.5–7.6). Ordering itself is shop.js.
 */
(function () {
  'use strict';

  var shop = document.querySelector('[data-shop]');
  var box = document.querySelector('[data-table-orders]');
  if (!shop || !box) {
    return;
  }
  var token = box.getAttribute('data-token');
  var TEXT = JSON.parse(shop.getAttribute('data-texts'));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var list = box.querySelector('[data-table-order-list]');
  var message = document.querySelector('[data-table-message]');
  var billButton = document.querySelector('[data-table-alert="request_bill"]');
  var timer = null;

  function money(paise) {
    return '₹' + Math.floor(paise / 100).toLocaleString('en-IN') + (paise % 100 ? '.' + String(paise % 100).padStart(2, '0') : '');
  }

  function render(data) {
    box.hidden = data.orders.length === 0;
    billButton.hidden = data.session_id === null;
    list.textContent = '';
    data.orders.forEach(function (order) {
      var li = document.createElement('li');
      li.className = 'table-order status-' + order.status;
      var head = document.createElement('div');
      head.className = 'spread';
      var title = document.createElement('strong');
      title.textContent = '#' + order.token;
      var status = document.createElement('span');
      status.className = 'table-order__status';
      status.textContent = order.label;
      head.appendChild(title);
      head.appendChild(status);
      li.appendChild(head);
      var items = document.createElement('p');
      items.className = 'text-sm';
      items.textContent = order.items.join(', ');
      li.appendChild(items);
      list.appendChild(li);
    });
    box.querySelector('[data-table-total]').textContent = money(data.total_paise);
    box.querySelector('[data-bill-requested]').hidden = !data.bill_requested;
  }

  async function refresh() {
    try {
      var response = await fetch('/api/table_status.php?t=' + encodeURIComponent(token), { headers: { 'Accept': 'application/json' } });
      var data = await response.json();
      if (data.ok) {
        render(data.data);
      }
    } catch (e) { /* try again next time */ }
    schedule();
  }

  function schedule() {
    window.clearTimeout(timer);
    if (!document.hidden) {
      timer = window.setTimeout(refresh, 10000);
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      window.clearTimeout(timer);
    } else {
      refresh();
    }
  });

  function show(text, isError) {
    message.textContent = text;
    message.className = 'flash' + (isError ? ' flash-error' : '');
    message.hidden = false;
    window.setTimeout(function () { message.hidden = true; }, 6000);
  }

  document.querySelectorAll('[data-table-alert]').forEach(function (button) {
    button.addEventListener('click', async function () {
      var type = button.getAttribute('data-table-alert');
      if (type === 'request_bill' && !window.confirm(TEXT.confirm_bill)) {
        return;
      }
      button.disabled = true;
      try {
        var response = await fetch('/api/table_alert.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ t: token, type: type })
        });
        var data = await response.json();
        show(data.ok ? data.data.message : data.message, !data.ok);
        refresh();
      } catch (e) {
        show(TEXT.offline, true);
      } finally {
        window.setTimeout(function () { button.disabled = false; }, 3000);
      }
    });
  });

  refresh();
})();
