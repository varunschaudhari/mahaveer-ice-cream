/*
 * Counter Billing tab. Chrome 109. The cart lives in sessionStorage so it survives switching
 * tabs; the server re-checks every line and price when the bill is saved.
 *
 * Each bill gets a random key before it is first sent. If the network drops after the server
 * saved it, tapping Save again sends the same key and the server returns the same bill instead
 * of making a second one. The key changes only after a successful save.
 */
(function () {
  'use strict';

  var C = window.Counter;
  var root = document.querySelector('[data-billing]');
  if (!root) {
    return;
  }

  var PARCEL = parseInt(root.getAttribute('data-parcel'), 10) || 0;
  var TEXT = JSON.parse(root.getAttribute('data-texts'));
  var REASONS = JSON.parse(root.getAttribute('data-discount-reasons'));
  var STORE = 'mahavir.counter.cart';
  var MAX_QTY = 50;

  var cart = document.querySelector('[data-cart]');
  var linesEl = cart.querySelector('[data-cart-lines]');
  // Phones: the bill opens as a sheet from a bottom bar (counter.css below 1024 px).
  var billBar = document.querySelector('[data-cart-open]');
  var cartScrim = document.querySelector('.cart-scrim');

  function setCartOpen(open) {
    document.body.classList.toggle('cart-open', open);
    if (cartScrim) {
      cartScrim.hidden = !open;
    }
  }

  if (billBar) {
    billBar.addEventListener('click', function () { setCartOpen(true); });
  }
  document.querySelectorAll('[data-cart-close]').forEach(function (el) {
    el.addEventListener('click', function () { setCartOpen(false); });
  });
  var busy = false;

  function text(key, params) {
    var value = TEXT[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      value = value.split('{' + name + '}').join(String(params[name]));
    });
    return value;
  }

  function newKey() {
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  function freshState() {
    return { lines: [], service: 'dine_in', discount: null, key: newKey() };
  }

  /** Buttons on the page are the source of truth for names, prices and sold-out state. */
  function variantButton(id) {
    return root.querySelector('.variant-btn[data-variant="' + id + '"]');
  }

  function load() {
    try {
      var saved = JSON.parse(window.sessionStorage.getItem(STORE));
      if (!saved || !Array.isArray(saved.lines)) {
        return null;
      }
      saved.lines = saved.lines.filter(function (line) {
        var button = variantButton(line.variant);
        if (!button || button.disabled) {
          return false;
        }
        line.price = parseInt(button.getAttribute('data-price'), 10);
        return true;
      });
      return saved;
    } catch (e) {
      return null;
    }
  }

  function persist() {
    try {
      window.sessionStorage.setItem(STORE, JSON.stringify(state));
    } catch (e) { /* private mode: the cart just won't survive a tab switch */ }
  }

  var state = load() || freshState();

  // Same rules as PricingService: parcel on Parcel bills, total rounded to the nearest rupee.
  function totals() {
    var subtotal = state.lines.reduce(function (sum, line) { return sum + line.price * line.qty; }, 0);
    var parcel = state.service === 'parcel' && state.lines.length ? PARCEL : 0;
    var discount = state.discount ? state.discount.amount : 0;
    var exact = Math.max(0, subtotal + parcel - discount);
    var total = Math.floor((exact + 50) / 100) * 100;
    return { subtotal: subtotal, parcel: parcel, discount: discount, roundOff: total - exact, total: total };
  }

  // ---------- rendering ----------

  function row(selector, show) {
    var el = cart.querySelector(selector);
    el.hidden = !show;
    return el;
  }

  function render() {
    linesEl.textContent = '';
    state.lines.forEach(function (line, index) {
      var el = document.createElement('div');
      el.className = 'cart-line';
      el.innerHTML =
        '<div class="cart-line__name"></div>' +
        '<div class="cart-line__controls">' +
        '  <button type="button" class="counter-btn" data-dec aria-label="−">−</button>' +
        '  <span class="cart-line__qty"></span>' +
        '  <button type="button" class="counter-btn" data-inc aria-label="+">+</button>' +
        '  <span class="cart-line__amount"></span>' +
        '  <button type="button" class="counter-btn cart-line__remove" data-remove>×</button>' +
        '</div>';
      el.querySelector('.cart-line__name').textContent = line.name + ' (' + line.label + ')';
      el.querySelector('.cart-line__qty').textContent = line.qty;
      el.querySelector('.cart-line__amount').textContent = C.money(line.price * line.qty);
      el.querySelector('[data-remove]').setAttribute('aria-label', text('remove'));
      el.setAttribute('data-index', index);
      linesEl.appendChild(el);
    });
    cart.querySelector('[data-cart-empty]').hidden = state.lines.length > 0;

    var t = totals();
    cart.querySelector('[data-subtotal]').textContent = C.money(t.subtotal);
    row('[data-parcel-row]', t.parcel > 0).querySelector('dd').textContent = C.money(t.parcel);
    row('[data-discount-row]', t.discount > 0).querySelector('dd').textContent = '−' + C.money(t.discount);
    cart.querySelector('[data-discount-reason]').textContent = state.discount ? '(' + state.discount.reason + ')' : '';
    row('[data-roundoff-row]', t.roundOff !== 0).querySelector('dd').textContent = C.money(t.roundOff);
    cart.querySelector('[data-total]').textContent = C.money(t.total);

    cart.querySelectorAll('[data-service] button').forEach(function (button) {
      button.classList.toggle('is-selected', button.getAttribute('data-value') === state.service);
    });
    cart.querySelector('[data-discount]').textContent = state.discount ? text('remove_discount') : text('discount');
    cart.querySelectorAll('[data-pay-total]').forEach(function (el) {
      el.textContent = state.lines.length ? ' ' + C.money(t.total) : '';
    });
    var canPay = state.lines.length > 0 && !busy && C.isOnline();
    cart.querySelectorAll('[data-pay]').forEach(function (button) {
      button.disabled = !canPay || (button.getAttribute('data-pay') === 'mixed' && t.total < 200);
    });
    if (billBar) {
      var count = state.lines.reduce(function (sum, l) { return sum + l.qty; }, 0);
      billBar.hidden = state.lines.length === 0;
      billBar.querySelector('[data-bill-count]').textContent = text('items', { n: Number.isInteger(count) ? count : state.lines.length });
      billBar.querySelector('[data-bill-total]').textContent = C.money(t.total);
    }
    if (state.lines.length === 0) {
      setCartOpen(false); // paid or cleared: back to the menu
    }
    persist();
  }

  // ---------- menu ----------

  root.querySelector('.category-bar').addEventListener('click', function (event) {
    var button = event.target.closest('[data-category]');
    if (!button) {
      return;
    }
    var id = button.getAttribute('data-category');
    root.querySelectorAll('[data-category]').forEach(function (b) { b.classList.toggle('is-selected', b === button); });
    root.querySelectorAll('[data-category-panel]').forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-category-panel') !== id;
    });
  });

  root.addEventListener('click', function (event) {
    var button = event.target.closest('.variant-btn');
    if (!button || button.disabled) {
      return;
    }
    var id = parseInt(button.getAttribute('data-variant'), 10);
    var line = state.lines.filter(function (l) { return l.variant === id; })[0];
    if (line) {
      line.qty = Math.min(MAX_QTY, line.qty + 1);
    } else {
      state.lines.push({
        variant: id,
        name: button.getAttribute('data-name'),
        label: button.getAttribute('data-label'),
        price: parseInt(button.getAttribute('data-price'), 10),
        qty: 1
      });
    }
    render();
  });

  // ---------- cart ----------

  linesEl.addEventListener('click', function (event) {
    var button = event.target.closest('button');
    var lineEl = event.target.closest('.cart-line');
    if (!button || !lineEl) {
      return;
    }
    var index = parseInt(lineEl.getAttribute('data-index'), 10);
    var line = state.lines[index];
    if (button.hasAttribute('data-inc')) {
      line.qty = Math.min(MAX_QTY, line.qty + 1);
    } else if (button.hasAttribute('data-dec')) {
      line.qty -= 1;
      if (line.qty < 1) {
        state.lines.splice(index, 1);
      }
    } else if (button.hasAttribute('data-remove')) {
      state.lines.splice(index, 1);
    }
    render();
  });

  cart.querySelector('[data-service]').addEventListener('click', function (event) {
    var button = event.target.closest('[data-value]');
    if (button) {
      state.service = button.getAttribute('data-value');
      render();
    }
  });

  cart.querySelector('[data-clear]').addEventListener('click', function () {
    if (state.lines.length === 0 || window.confirm(text('confirm_clear'))) {
      state = freshState();
      render();
    }
  });

  cart.querySelector('[data-discount]').addEventListener('click', async function () {
    if (state.discount) {
      state.discount = null;
      render();
      return;
    }
    var rupees = await C.numpad({ title: text('discount_amount'), prefix: '₹', maxLength: 5, minLength: 1 });
    if (!rupees || parseInt(rupees, 10) <= 0) {
      return;
    }
    var reason = await C.choose({ title: text('discount_reason'), options: REASONS, other: true });
    if (!reason) {
      return;
    }
    var pin = await C.numpad({ title: C.t('owner_pin'), mask: true, maxLength: 4, minLength: 4 });
    if (!pin) {
      return;
    }
    try {
      var approval = await C.api('/api/discount_approve.php', {
        amount_paise: parseInt(rupees, 10) * 100,
        reason: reason,
        pin: pin
      });
      state.discount = { amount: approval.amount_paise, reason: approval.reason };
      C.toast(text('discount_approved', { name: approval.owner_name }), 'success');
      render();
    } catch (err) {
      C.toast(err.message, 'error');
    }
  });

  // ---------- paying and saving ----------

  async function save(method, cashPaise) {
    if (busy || !state.lines.length) {
      return false;
    }
    busy = true;
    render();
    var t = totals();
    try {
      var result = await C.api('/api/counter_bill.php', {
        order_key: state.key,
        lines: state.lines.map(function (line) { return { variant_id: line.variant, qty: line.qty }; }),
        service_type: state.service,
        payment_method: method,
        cash_paise: cashPaise || 0,
        expected_total: t.total,
        discount_paise: t.discount
      });
      C.print(result.print_url);
      C.toast(text(result.duplicate ? 'duplicate' : 'saved', { bill: result.bill_no, token: result.token_no }), 'success');
      state = freshState();
      return true;
    } catch (err) {
      C.toast(err.message, 'error');
      if (err.code === 'billing.error.price_changed') {
        window.setTimeout(function () { window.location.reload(); }, 2500);
      }
      return false;
    } finally {
      busy = false;
      render();
    }
  }

  cart.querySelector('.pay-buttons').addEventListener('click', async function (event) {
    var button = event.target.closest('[data-pay]');
    if (!button || button.disabled) {
      return;
    }
    var method = button.getAttribute('data-pay');
    var total = totals().total;
    if (method === 'cash') {
      C.cash(total, function () { return save('cash'); });
    } else if (method === 'upi') {
      save('upi');
    } else {
      var rupees = await C.numpad({
        title: C.t('cash_part', { total: C.money(total) }),
        prefix: '₹',
        maxLength: 6,
        minLength: 1
      });
      if (!rupees) {
        return;
      }
      var cashPart = parseInt(rupees, 10) * 100;
      if (cashPart <= 0 || cashPart >= total) {
        C.toast(C.t('mixed_invalid'), 'error');
        return;
      }
      save('mixed', cashPart);
    }
  });

  document.addEventListener('counter:online', render);
  render();
})();
