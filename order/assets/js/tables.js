/*
 * Counter Tables tab: tiles refreshed by Counter's 5 s poll (view=tables); a tile opens the
 * table's visit: alerts (dismiss), orders (Accept / Served / Move / Cancel), discount with
 * owner PIN, Settle (one combined bill → payment → print → table free). Chrome 109.
 */
(function () {
  'use strict';

  var C = window.Counter;
  var root = document.querySelector('[data-tables]');
  if (!root) {
    return;
  }
  var TEXT = JSON.parse(root.getAttribute('data-texts'));
  var DISCOUNT_REASONS = JSON.parse(root.getAttribute('data-discount-reasons'));
  var CANCEL_REASONS = JSON.parse(root.getAttribute('data-cancel-reasons'));
  var grid = root.querySelector('[data-table-grid]');
  var dialog = document.querySelector('[data-table-dialog]');
  var current = null;   // detail of the open table
  var discount = null;  // {amount, reason} approved for the open table
  var busy = false;

  function text(key, params) {
    var value = TEXT[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      value = value.split('{' + name + '}').join(String(params[name]));
    });
    return value;
  }

  function el(tag, className, content) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (content !== undefined) {
      node.textContent = content;
    }
    return node;
  }

  function button(label, attrs, className) {
    var b = el('button', 'counter-btn' + (className ? ' ' + className : ''), label);
    b.type = 'button';
    Object.keys(attrs).forEach(function (name) { b.setAttribute(name, attrs[name]); });
    return b;
  }

  // ---------- tiles ----------

  function renderTiles(tables) {
    root.querySelector('[data-tables-loading]').hidden = true;
    grid.textContent = '';
    tables.forEach(function (table) {
      var tile = button('', { 'data-table': table.id }, 'table-tile status-' + table.status + (table.unsettled ? ' is-unsettled' : '') + (table.alerts.length ? ' has-alert' : ''));
      tile.appendChild(el('span', 'table-tile__number', 'T' + table.number));
      if (table.outside) {
        tile.appendChild(el('span', 'table-tile__area', text('outside')));
      }
      tile.appendChild(el('span', 'table-tile__status', text(table.status)));
      if (table.status !== 'free') {
        tile.appendChild(el('span', 'table-tile__total', C.money(table.total_paise)));
        var mins = table.minutes;
        tile.appendChild(el('span', 'table-tile__minutes', mins >= 60
          ? text('hours_minutes', { h: Math.floor(mins / 60), m: mins % 60 })
          : text('minutes', { n: mins })));
      }
      if (table.unserved > 0) {
        tile.appendChild(el('span', 'table-tile__chip', text('unserved', { n: table.unserved })));
      }
      table.alerts.forEach(function (type) {
        tile.appendChild(el('span', 'table-tile__chip is-alert', text(type)));
      });
      if (table.unsettled) {
        tile.appendChild(el('span', 'table-tile__chip is-warning', text('unsettled')));
      }
      grid.appendChild(tile);
    });
  }

  document.addEventListener('counter:poll', function (event) {
    if (event.detail.tables) {
      renderTiles(event.detail.tables);
    }
  });

  grid.addEventListener('click', function (event) {
    var tile = event.target.closest('[data-table]');
    if (tile) {
      discount = null;
      openDetail(parseInt(tile.getAttribute('data-table'), 10));
    }
  });

  // ---------- detail ----------

  function totals() {
    var subtotal = current ? current.total_paise : 0;
    var off = discount ? Math.min(discount.amount, subtotal) : 0;
    return { subtotal: subtotal, discount: off, total: Math.floor((subtotal - off + 50) / 100) * 100 };
  }

  function renderDetail() {
    dialog.querySelector('[data-td-title]').textContent = current.label;

    var alerts = dialog.querySelector('[data-td-alerts]');
    alerts.textContent = '';
    current.alerts.forEach(function (alert) {
      var row = el('p', 'td-alert spread');
      row.appendChild(el('strong', '', alert.label + ' · ' + alert.time));
      row.appendChild(button(text('dismiss'), { 'data-dismiss': alert.id }));
      alerts.appendChild(row);
    });

    var orders = dialog.querySelector('[data-td-orders]');
    orders.textContent = '';
    if (!current.orders.length) {
      orders.appendChild(el('p', 'muted', text('no_orders')));
    }
    current.orders.forEach(function (order) {
      var card = el('div', 'td-order status-' + order.status);
      var head = el('div', 'spread');
      head.appendChild(el('strong', '', '#' + order.token + (order.customer ? ' · ' + order.customer : '')));
      head.appendChild(el('span', 'order-card__status', order.label));
      card.appendChild(head);
      card.appendChild(el('p', '', order.items.join(', ')));
      if (order.note) {
        card.appendChild(el('p', 'order-card__note', order.note));
      }
      var actions = el('div', 'td-order__actions');
      actions.appendChild(el('span', 'td-order__total', C.money(order.total_paise)));
      if (order.status === 'received') {
        actions.appendChild(button(text('accept'), { 'data-order-action': 'accept', 'data-order': order.id }, 'btn-go'));
      }
      if (order.status === 'preparing') {
        actions.appendChild(button(text('serve'), { 'data-order-action': 'serve', 'data-order': order.id }, 'btn-go'));
      }
      if (order.status !== 'cancelled') {
        actions.appendChild(button(text('move'), { 'data-move': order.id }));
        actions.appendChild(button(text('cancel'), { 'data-cancel': order.id, 'data-status': order.status, 'data-token': order.token }, 'btn-danger-c'));
      }
      card.appendChild(actions);
      orders.appendChild(card);
    });

    var t = totals();
    dialog.querySelector('[data-td-subtotal]').textContent = C.money(t.subtotal);
    dialog.querySelector('[data-td-discount-row]').hidden = t.discount === 0;
    dialog.querySelector('[data-td-discount]').textContent = '−' + C.money(t.discount);
    dialog.querySelector('[data-td-discount-reason]').textContent = discount ? '(' + discount.reason + ')' : '';
    dialog.querySelector('[data-td-total]').textContent = C.money(t.total);

    var billable = current.session_id !== null && t.subtotal > 0;
    dialog.querySelector('[data-td-settle]').disabled = !billable || busy;
    dialog.querySelector('[data-td-discount]').disabled = !billable || busy;
    dialog.querySelector('[data-td-discount]').textContent = discount ? text('remove_discount') : text('discount');
    dialog.querySelector('[data-td-close-empty]').hidden = !(current.session_id !== null && t.subtotal === 0);
  }

  async function openDetail(tableId) {
    try {
      current = await C.api('/api/table_detail.php?id=' + tableId);
      renderDetail();
      if (!dialog.open) {
        dialog.showModal();
      }
    } catch (err) {
      C.toast(err.message, 'error');
    }
  }

  function reload() {
    C.refresh();
    if (current && dialog.open) {
      openDetail(current.id);
    }
  }

  async function act(url, body, done) {
    busy = true;
    try {
      var result = await C.api(url, body);
      if (done) {
        C.toast(done(result), 'success');
      }
      return result;
    } catch (err) {
      C.toast(err.message, 'error');
      return null;
    } finally {
      busy = false;
      reload();
    }
  }

  dialog.addEventListener('click', async function (event) {
    var b = event.target.closest('button');
    if (!b || b.disabled || !current) {
      return;
    }

    if (b.hasAttribute('data-td-close')) {
      dialog.close();
    } else if (b.hasAttribute('data-dismiss')) {
      await act('/api/table_action.php', { action: 'dismiss', alert_id: parseInt(b.getAttribute('data-dismiss'), 10) });
    } else if (b.hasAttribute('data-order-action')) {
      var orderId = parseInt(b.getAttribute('data-order'), 10);
      var result = await act('/api/order_action.php', { action: b.getAttribute('data-order-action'), order_id: orderId });
      if (result && result.print_url) {
        C.printKot(orderId); // kitchen ticket, printed once
      }
    } else if (b.hasAttribute('data-move')) {
      var labels = current.move_targets.map(function (target) { return target.label; });
      var choice = await C.choose({ title: text('move_title'), options: labels });
      var target = current.move_targets[labels.indexOf(choice)];
      if (target) {
        await act('/api/table_action.php', { action: 'move', order_id: parseInt(b.getAttribute('data-move'), 10), to_table_id: target.id }, function () {
          return text('moved', { table: target.label });
        });
      }
    } else if (b.hasAttribute('data-cancel')) {
      var reason = await C.choose({ title: text('cancel_title', { token: b.getAttribute('data-token') }), options: CANCEL_REASONS, other: true });
      if (!reason) {
        return;
      }
      var pin = '';
      if (b.getAttribute('data-status') !== 'received') {
        pin = await C.numpad({ title: C.t('owner_pin'), mask: true, maxLength: 4, minLength: 4 });
        if (!pin) {
          return;
        }
      }
      await act('/api/order_action.php', { action: 'cancel', order_id: parseInt(b.getAttribute('data-cancel'), 10), reason: reason, pin: pin });
    } else if (b.hasAttribute('data-td-discount')) {
      await toggleDiscount();
    } else if (b.hasAttribute('data-td-settle')) {
      await settle();
    } else if (b.hasAttribute('data-td-close-empty')) {
      await act('/api/table_action.php', { action: 'close', session_id: current.session_id }, function () { return text('closed'); });
      dialog.close();
    }
  });

  async function toggleDiscount() {
    if (discount) {
      discount = null;
      renderDetail();
      return;
    }
    var rupees = await C.numpad({ title: text('discount_amount'), prefix: '₹', maxLength: 5, minLength: 1 });
    if (!rupees || parseInt(rupees, 10) <= 0) {
      return;
    }
    var reason = await C.choose({ title: text('discount_reason'), options: DISCOUNT_REASONS, other: true });
    if (!reason) {
      return;
    }
    var pin = await C.numpad({ title: C.t('owner_pin'), mask: true, maxLength: 4, minLength: 4 });
    if (!pin) {
      return;
    }
    try {
      var approval = await C.api('/api/discount_approve.php', { amount_paise: parseInt(rupees, 10) * 100, reason: reason, pin: pin });
      discount = { amount: approval.amount_paise, reason: approval.reason };
      C.toast(text('discount_approved', { name: approval.owner_name }), 'success');
      renderDetail();
    } catch (err) {
      C.toast(err.message, 'error');
    }
  }

  async function settle() {
    var unserved = current.orders.filter(function (o) { return o.status === 'received' || o.status === 'preparing'; }).length;
    if (unserved && !window.confirm(text('confirm_unserved', { n: unserved }))) {
      return;
    }
    var t = totals();
    var sessionId = current.session_id;
    var label = current.label;
    C.pay(t.total, async function (method, cashPaise) {
      try {
        var result = await C.api('/api/table_action.php', {
          action: 'settle',
          session_id: sessionId,
          method: method,
          cash_paise: cashPaise,
          expected_total: t.total,
          discount_paise: t.discount
        });
        C.print(result.print_url);
        C.toast(text('settled', { table: label, bill: result.bill_no }), 'success');
        discount = null;
        dialog.close();
        C.refresh();
        return true;
      } catch (err) {
        C.toast(err.message, 'error');
        return false;
      }
    });
  }

  C.setPollView('tables');
})();
