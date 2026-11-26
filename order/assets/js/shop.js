/*
 * Ordering on the customer's phone, two modes (data-mode on [data-shop]):
 *  - takeaway (index.php): pickup slot, ₹5 parcel charge, goes to the status page after ordering
 *  - table (t.php): no slot, no parcel charge, name + mobile asked once per table visit, the
 *    page reloads to show the order in the table's list
 * Cart in sessionStorage (SPEC §6.2); the server re-checks every line, price, slot and number.
 * Name and mobile are remembered in this phone's localStorage to pre-fill the next order.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-shop]');
  if (!root) {
    return;
  }

  var MODE = root.getAttribute('data-mode') || 'takeaway';
  var IS_TABLE = MODE === 'table';
  var TABLE_TOKEN = root.getAttribute('data-table-token') || '';
  var SESSION = root.getAttribute('data-session') || '';
  var PARCEL = parseInt(root.getAttribute('data-parcel'), 10) || 0;
  var OPEN = root.getAttribute('data-open') === '1';
  var TEXT = JSON.parse(root.getAttribute('data-texts'));
  var CART_KEY = 'mahavir.shop.cart.' + MODE + TABLE_TOKEN;
  var CUSTOMER_KEY = 'mahavir.customer';
  var VISIT_KEY = 'mahavir.table.visit'; // table visit (session id) this phone already ordered in
  var ORDER_KEY = 'mahavir.shop.key';
  var MAX_QTY = 50;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  var bar = document.querySelector('[data-cart-bar]');
  var checkout = root.querySelector('[data-checkout]');
  var form = root.querySelector('[data-checkout-form]');
  var linesEl = root.querySelector('[data-cart-lines]');
  // Not form.name: that is the form's own name attribute, not the field.
  var nameInput = form.querySelector('#c-name');
  var mobileInput = form.querySelector('#c-mobile');
  var noteInput = form.querySelector('#c-note');
  var placing = false;

  function text(key, params) {
    var value = TEXT[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      value = value.split('{' + name + '}').join(String(params[name]));
    });
    return value;
  }

  function money(paise) {
    var rupees = Math.floor(paise / 100).toLocaleString('en-IN');
    var fraction = paise % 100;
    return '₹' + rupees + (fraction ? '.' + String(fraction).padStart(2, '0') : '');
  }

  function store(storage, key, value) {
    try {
      if (value === null) {
        storage.removeItem(key);
      } else {
        storage.setItem(key, typeof value === 'string' ? value : JSON.stringify(value));
      }
    } catch (e) { /* private mode: works for this page only */ }
  }

  function read(storage, key) {
    try {
      return storage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function button(variantId) {
    return root.querySelector('.add-btn[data-variant="' + variantId + '"]');
  }

  // ---------- cart ----------

  var lines = [];
  try {
    lines = (JSON.parse(read(window.sessionStorage, CART_KEY)) || []).filter(function (line) {
      var b = button(line.variant);
      if (!b || b.disabled) {
        return false;
      }
      line.price = parseInt(b.getAttribute('data-price'), 10); // today's price
      return true;
    });
  } catch (e) {
    lines = [];
  }

  function add(variantId, qty) {
    var b = button(variantId);
    if (!b || b.disabled) {
      return false;
    }
    var line = lines.filter(function (l) { return l.variant === variantId; })[0];
    if (line) {
      line.qty = Math.min(MAX_QTY, line.qty + qty);
    } else {
      lines.push({
        variant: variantId,
        name: b.getAttribute('data-name'),
        label: b.getAttribute('data-label'),
        price: parseInt(b.getAttribute('data-price'), 10),
        qty: Math.min(MAX_QTY, qty)
      });
    }
    return true;
  }

  function totals() {
    var subtotal = lines.reduce(function (sum, l) { return sum + l.price * l.qty; }, 0);
    var parcel = lines.length ? PARCEL : 0;
    var exact = subtotal + parcel;
    return { subtotal: subtotal, parcel: parcel, total: Math.floor((exact + 50) / 100) * 100 };
  }

  function render() {
    var count = lines.reduce(function (sum, l) { return sum + l.qty; }, 0);
    var t = totals();
    bar.hidden = count === 0 || !checkout.hidden;
    bar.querySelector('[data-cart-count]').textContent = text('items', { n: count });
    bar.querySelector('[data-cart-total]').textContent = money(t.total);

    linesEl.textContent = '';
    lines.forEach(function (line, index) {
      var row = document.createElement('div');
      row.className = 'checkout-line';
      row.innerHTML =
        '<span class="checkout-line__name"></span>' +
        '<span class="checkout-line__qty">' +
        '<button type="button" class="btn btn-secondary" data-dec aria-label="−">−</button>' +
        '<strong></strong>' +
        '<button type="button" class="btn btn-secondary" data-inc aria-label="+">+</button>' +
        '</span>' +
        '<span class="checkout-line__amount"></span>';
      row.querySelector('.checkout-line__name').textContent = line.name + ' (' + line.label + ')';
      row.querySelector('strong').textContent = line.qty;
      row.querySelector('.checkout-line__amount').textContent = money(line.price * line.qty);
      row.setAttribute('data-index', index);
      linesEl.appendChild(row);
    });
    root.querySelector('[data-subtotal]').textContent = money(t.subtotal);
    var parcelEl = root.querySelector('[data-parcel-amount]');
    if (parcelEl) {
      parcelEl.textContent = money(t.parcel);
    }
    root.querySelector('[data-total]').textContent = money(t.total);
    form.querySelector('[data-place]').disabled = !OPEN || placing || lines.length === 0;

    if (lines.length === 0 && !checkout.hidden) {
      closeCheckout();
    }
    store(window.sessionStorage, CART_KEY, lines);
  }

  root.addEventListener('click', function (event) {
    var b = event.target.closest('.add-btn');
    if (b && !b.disabled && add(parseInt(b.getAttribute('data-variant'), 10), 1)) {
      b.classList.add('is-added');
      window.setTimeout(function () { b.classList.remove('is-added'); }, 400);
      render();
    }
  });

  linesEl.addEventListener('click', function (event) {
    var b = event.target.closest('button');
    var row = event.target.closest('.checkout-line');
    if (!b || !row) {
      return;
    }
    var line = lines[parseInt(row.getAttribute('data-index'), 10)];
    if (b.hasAttribute('data-inc')) {
      line.qty = Math.min(MAX_QTY, line.qty + 1);
    } else if (b.hasAttribute('data-dec')) {
      line.qty -= 1;
    }
    lines = lines.filter(function (l) { return l.qty > 0; });
    render();
  });

  // ---------- reorder (this phone's recent orders) ----------

  root.querySelectorAll('[data-reorder]').forEach(function (b) {
    b.addEventListener('click', function () {
      var wanted = JSON.parse(b.getAttribute('data-reorder'));
      var missing = 0;
      wanted.forEach(function (line) {
        if (!add(line.v, line.q)) {
          missing++;
        }
      });
      render();
      if (missing) {
        window.alert(text('reorder_partial'));
      }
      if (lines.length) {
        openCheckout();
      }
    });
  });

  // ---------- checkout ----------

  function openCheckout() {
    checkout.hidden = false;
    bar.hidden = true;
    var saved = null;
    try {
      saved = JSON.parse(read(window.localStorage, CUSTOMER_KEY));
    } catch (e) { /* none */ }
    if (saved) {
      nameInput.value = nameInput.value || saved.name || '';
      mobileInput.value = mobileInput.value || saved.mobile || '';
    }
    if (IS_TABLE) {
      // Already ordered in this visit from this phone: don't ask name + mobile again (SPEC §7.2).
      var known = saved && SESSION !== '' && read(window.localStorage, VISIT_KEY) === SESSION;
      form.querySelector('[data-identity]').hidden = !!known;
      form.querySelector('[data-identity-known]').hidden = !known;
      if (known) {
        form.querySelector('[data-identity-name]').textContent = text('ordered_as', { name: saved.name });
      }
    } else {
      refreshSlots();
    }
    checkout.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  if (IS_TABLE) {
    form.querySelector('[data-identity-change]').addEventListener('click', function () {
      form.querySelector('[data-identity]').hidden = false;
      form.querySelector('[data-identity-known]').hidden = true;
    });
  }

  function closeCheckout() {
    checkout.hidden = true;
    render();
  }

  bar.querySelector('[data-open-cart]').addEventListener('click', openCheckout);
  form.querySelector('[data-back]').addEventListener('click', function () {
    closeCheckout();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  async function refreshSlots() {
    var grid = form.querySelector('[data-slots]');
    var chosen = (form.querySelector('input[name="slot"]:checked') || {}).value;
    try {
      var response = await fetch('/api/slots.php', { headers: { 'Accept': 'application/json' } });
      var data = (await response.json()).data;
      grid.textContent = '';
      data.slots.forEach(function (slot) {
        var label = document.createElement('label');
        label.className = 'slot' + (slot.full ? ' is-full' : '');
        var input = document.createElement('input');
        input.type = 'radio';
        input.name = 'slot';
        input.value = slot.start;
        input.disabled = slot.full;
        input.checked = slot.start === chosen && !slot.full;
        var span = document.createElement('span');
        span.textContent = slot.label.split(' – ')[0];
        label.appendChild(input);
        label.appendChild(span);
        if (slot.full) {
          var small = document.createElement('small');
          small.textContent = text('full');
          label.appendChild(small);
        }
        grid.appendChild(label);
      });
      if (!data.slots.length) {
        grid.textContent = text('no_slots');
      }
      OPEN = data.open;
      render();
    } catch (e) { /* keep the slots rendered with the page */ }
  }

  function showErrors(errors) {
    form.querySelectorAll('[data-error-for]').forEach(function (el) {
      var message = errors[el.getAttribute('data-error-for')];
      el.textContent = message || '';
      el.hidden = !message;
    });
  }

  function newKey() {
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (placing) {
      return;
    }
    if (IS_TABLE && form.querySelector('[data-identity]').hidden) {
      // Hidden because this phone already ordered in this visit: use what it remembers.
      try {
        var saved = JSON.parse(read(window.localStorage, CUSTOMER_KEY)) || {};
        nameInput.value = saved.name || '';
        mobileInput.value = saved.mobile || '';
      } catch (e) { /* the fields will show their errors */ }
    }
    var mobile = mobileInput.value.replace(/\D/g, '').replace(/^(91|0)(?=[6-9]\d{9}$)/, '');
    var slot = (form.querySelector('input[name="slot"]:checked') || {}).value;
    var errors = {};
    if (!nameInput.value.trim()) {
      errors.name = text('name_required');
    }
    if (!/^[6-9]\d{9}$/.test(mobile)) {
      errors.mobile = text('mobile_invalid');
    }
    if (!slot && !IS_TABLE) {
      errors.slot = text('slot_required');
    }
    showErrors(errors);
    if (Object.keys(errors).length) {
      return;
    }

    // One key per attempt: a repeat after a dropped connection returns the same order.
    var key = read(window.sessionStorage, ORDER_KEY) || newKey();
    store(window.sessionStorage, ORDER_KEY, key);

    placing = true;
    render();
    try {
      var response = await fetch(IS_TABLE ? '/api/place_table_order.php' : '/api/place_order.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({
          order_key: key,
          table_token: TABLE_TOKEN,
          name: nameInput.value.trim(),
          mobile: mobile,
          slot: slot || '',
          note: noteInput.value.trim(),
          lines: lines.map(function (l) { return { variant_id: l.variant, qty: l.qty }; })
        })
      });
      var data = await response.json();
      if (!data.ok) {
        showErrors(data.fields || { _: data.message || text('error') });
        if (data.error === 'checkout.error.slot_full' || data.error === 'checkout.error.slot') {
          refreshSlots();
        }
        if (data.error !== 'error.csrf') {
          store(window.sessionStorage, ORDER_KEY, null); // nothing was saved; next try is a new order
        }
        return;
      }
      store(window.localStorage, CUSTOMER_KEY, { name: nameInput.value.trim(), mobile: mobile });
      store(window.sessionStorage, CART_KEY, null);
      store(window.sessionStorage, ORDER_KEY, null);
      if (IS_TABLE) {
        store(window.localStorage, VISIT_KEY, String(data.data.session_id));
        lines = [];
        window.alert(text('placed', { token: data.data.token_no }));
        window.location.reload(); // shows the order in the table's list
      } else {
        window.location.href = data.data.status_url;
      }
    } catch (e) {
      showErrors({ _: text('offline') }); // keep the key: retrying returns the same order
    } finally {
      placing = false;
      render();
    }
  });

  render();
})();
