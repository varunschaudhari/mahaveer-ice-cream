/*
 * Counter Online tab: active takeaway orders as cards, refreshed by Counter's 5 s poll.
 * Received → Accept; Preparing → Ready / Picked up; Ready → Picked up. Cancel needs a reason,
 * plus the owner PIN once the order is accepted (SPEC §3). Chrome 109.
 */
(function () {
  'use strict';

  var C = window.Counter;
  var root = document.querySelector('[data-online]');
  if (!root) {
    return;
  }
  var TEXT = JSON.parse(root.getAttribute('data-texts'));
  var REASONS = JSON.parse(root.getAttribute('data-cancel-reasons'));
  var list = root.querySelector('[data-online-list]');
  var empty = root.querySelector('[data-online-empty]');
  var orders = [];
  var working = {}; // order id → true while a request runs (prevents double taps)

  function text(key, params) {
    var value = TEXT[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      value = value.split('{' + name + '}').join(String(params[name]));
    });
    return value;
  }

  var ACTIONS = {
    received: ['accept', 'cancel'],
    preparing: ['ready', 'picked_up', 'cancel'],
    ready: ['picked_up', 'cancel']
  };

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

  function card(order) {
    var article = el('article', 'order-card status-' + order.status);

    var head = el('header', 'order-card__head');
    head.appendChild(el('span', 'order-card__token', text('token', { n: order.token })));
    head.appendChild(el('span', 'order-card__status', order.status_label));
    head.appendChild(el('span', 'order-card__age', order.age_mins < 1 ? text('just_now') : text('age', { n: order.age_mins })));
    article.appendChild(head);

    article.appendChild(el('p', 'order-card__slot', text('pickup', { slot: order.slot })));

    var who = el('p', 'order-card__customer', order.customer + ' ');
    if (order.mobile) {
      var phone = el('a', 'order-card__mobile', order.mobile);
      phone.href = 'tel:+91' + order.mobile;
      who.appendChild(phone);
    }
    article.appendChild(who);

    var items = el('ul', 'order-card__items');
    order.items.forEach(function (line) {
      items.appendChild(el('li', '', line.qty + ' × ' + line.name));
    });
    article.appendChild(items);

    if (order.note) {
      article.appendChild(el('p', 'order-card__note', order.note));
    }
    article.appendChild(el('p', 'order-card__total', C.money(order.total_paise)));

    var actions = el('div', 'order-card__actions');
    (ACTIONS[order.status] || []).forEach(function (action) {
      var button = el('button', 'counter-btn' + (action === 'cancel' ? ' btn-danger-c' : ' btn-go'), text(action));
      button.type = 'button';
      button.setAttribute('data-action', action);
      button.setAttribute('data-id', order.id);
      button.disabled = !!working[order.id];
      actions.appendChild(button);
    });
    article.appendChild(actions);
    return article;
  }

  function render() {
    list.textContent = '';
    orders.forEach(function (order) {
      list.appendChild(card(order));
    });
    empty.hidden = orders.length > 0;
    empty.textContent = text('empty'); // replaces "Loading…" after the first poll
  }

  document.addEventListener('counter:poll', function (event) {
    if (event.detail.orders) {
      orders = event.detail.orders;
      render();
    }
  });

  async function run(id, body, doneMessage) {
    working[id] = true;
    render();
    try {
      var result = await C.api('/api/order_action.php', body);
      if (doneMessage) {
        C.toast(doneMessage(result), 'success');
      }
      return result;
    } catch (err) {
      C.toast(err.message, 'error');
      return null;
    } finally {
      delete working[id];
      C.setPollFull(true); // refresh the list now
    }
  }

  list.addEventListener('click', async function (event) {
    var button = event.target.closest('[data-action]');
    if (!button || button.disabled) {
      return;
    }
    var id = parseInt(button.getAttribute('data-id'), 10);
    var order = orders.filter(function (o) { return o.id === id; })[0];
    if (!order) {
      return;
    }
    var action = button.getAttribute('data-action');

    if (action === 'accept' || action === 'ready') {
      await run(id, { action: action, order_id: id });
    } else if (action === 'picked_up') {
      C.pay(order.total_paise, async function (method, cashPaise) {
        var result = await run(id, { action: 'pickup', order_id: id, method: method, cash_paise: cashPaise }, function (r) {
          return text('picked_up_done', { token: order.token, bill: r.bill_no });
        });
        if (result) {
          C.print(result.print_url);
        }
        return !!result;
      });
    } else if (action === 'cancel') {
      var reason = await C.choose({ title: text('cancel_title', { token: order.token }), options: REASONS, other: true });
      if (!reason) {
        return;
      }
      var pin = '';
      if (order.status !== 'received') {
        pin = await C.numpad({ title: C.t('owner_pin'), mask: true, maxLength: 4, minLength: 4 });
        if (!pin) {
          return;
        }
      }
      await run(id, { action: 'cancel', order_id: id, reason: reason, pin: pin }, function () {
        return text('cancelled', { token: order.token });
      });
    }
  });

  C.setPollFull(true);
})();
