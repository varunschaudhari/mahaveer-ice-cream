/*
 * Bulk order form: pick an item + quantity (decimals allowed: 2.5 × "1 kg" = 2.5 kg), see an
 * estimated total, send the request. The owner confirms the final price when approving.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-bulk]');
  if (!root) {
    return;
  }
  var TEXT = JSON.parse(root.getAttribute('data-texts'));
  var STORE = 'mahavir.bulk.lines';
  var KEY_STORE = 'mahavir.bulk.key';
  var CUSTOMER_KEY = 'mahavir.customer';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var form = root.querySelector('[data-bulk-form]');
  var select = form.querySelector('[data-bulk-variant]');
  var qtyInput = form.querySelector('[data-bulk-qty]');
  var list = form.querySelector('[data-bulk-lines]');
  var sending = false;
  var lines = [];

  function store(storage, key, value) {
    try {
      if (value === null) {
        storage.removeItem(key);
      } else {
        storage.setItem(key, typeof value === 'string' ? value : JSON.stringify(value));
      }
    } catch (e) { /* private mode */ }
  }

  function read(storage, key) {
    try {
      return storage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function money(paise) {
    return '₹' + Math.floor(paise / 100).toLocaleString('en-IN') + (paise % 100 ? '.' + String(paise % 100).padStart(2, '0') : '');
  }

  /** '2.5' → 250 (hundredths); null when not a quantity from 0.01 to 999.99. */
  function hundredths(text) {
    var value = String(text).trim().replace(',', '.');
    if (!/^\d{1,3}(\.\d{1,2})?$/.test(value)) {
      return null;
    }
    var parts = value.split('.');
    var result = parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
    return result >= 1 ? result : null;
  }

  function showErrors(errors) {
    form.querySelectorAll('[data-error-for]').forEach(function (el) {
      var message = errors[el.getAttribute('data-error-for')];
      el.textContent = message || '';
      el.hidden = !message;
    });
  }

  function render() {
    list.textContent = '';
    var total = 0;
    lines.forEach(function (line, index) {
      var amount = Math.floor((line.price * line.qty + 50) / 100);
      total += amount;
      var li = document.createElement('li');
      li.className = 'bulk-line';
      var name = document.createElement('span');
      name.textContent = (line.qty / 100) + ' × ' + line.name;
      var value = document.createElement('strong');
      value.textContent = money(amount);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn-secondary';
      remove.textContent = '×';
      remove.setAttribute('aria-label', TEXT.remove);
      remove.setAttribute('data-remove', index);
      li.appendChild(name);
      li.appendChild(value);
      li.appendChild(remove);
      list.appendChild(li);
    });
    form.querySelector('[data-bulk-total]').textContent = money(Math.floor((total + 50) / 100) * 100);
    store(window.sessionStorage, STORE, lines);
  }

  form.querySelector('[data-bulk-add]').addEventListener('click', function () {
    var option = select.options[select.selectedIndex];
    var qty = hundredths(qtyInput.value);
    if (!option || !option.value) {
      showErrors({ lines: TEXT.choose });
      return;
    }
    if (qty === null) {
      showErrors({ lines: TEXT.qty });
      return;
    }
    var id = parseInt(option.value, 10);
    var existing = lines.filter(function (l) { return l.variant === id; })[0];
    if (existing) {
      existing.qty = Math.min(99999, existing.qty + qty);
    } else {
      lines.push({ variant: id, name: option.getAttribute('data-name'), price: parseInt(option.getAttribute('data-price'), 10), qty: qty });
    }
    showErrors({});
    select.selectedIndex = 0;
    qtyInput.value = '1';
    render();
  });

  list.addEventListener('click', function (event) {
    var button = event.target.closest('[data-remove]');
    if (button) {
      lines.splice(parseInt(button.getAttribute('data-remove'), 10), 1);
      render();
    }
  });

  function newKey() {
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (sending) {
      return;
    }
    if (!lines.length) {
      showErrors({ lines: TEXT.no_lines });
      return;
    }
    var key = read(window.sessionStorage, KEY_STORE) || newKey();
    store(window.sessionStorage, KEY_STORE, key);
    var mobile = form.querySelector('#b-mobile').value.replace(/\D/g, '').replace(/^(91|0)(?=[6-9]\d{9}$)/, '');

    sending = true;
    form.querySelector('[data-bulk-send]').disabled = true;
    try {
      var response = await fetch('/api/bulk_request.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({
          order_key: key,
          name: form.querySelector('#b-name').value.trim(),
          mobile: mobile,
          note: form.querySelector('#b-note').value.trim(),
          event_date: form.querySelector('#b-date').value,
          pickup_time: form.querySelector('#b-time').value,
          lines: lines.map(function (l) { return { variant_id: l.variant, qty: String(l.qty / 100) }; })
        })
      });
      var data = await response.json();
      if (!data.ok) {
        showErrors(data.fields || { _: data.message || TEXT.error });
        store(window.sessionStorage, KEY_STORE, null);
        return;
      }
      store(window.localStorage, CUSTOMER_KEY, { name: form.querySelector('#b-name').value.trim(), mobile: mobile });
      store(window.sessionStorage, STORE, null);
      store(window.sessionStorage, KEY_STORE, null);
      window.location.href = data.data.status_url;
    } catch (e) {
      showErrors({ _: TEXT.offline });
    } finally {
      sending = false;
      form.querySelector('[data-bulk-send]').disabled = false;
    }
  });

  try {
    lines = JSON.parse(read(window.sessionStorage, STORE)) || [];
    var saved = JSON.parse(read(window.localStorage, CUSTOMER_KEY));
    if (saved) {
      form.querySelector('#b-name').value = saved.name || '';
      form.querySelector('#b-mobile').value = saved.mobile || '';
    }
  } catch (e) {
    lines = [];
  }
  render();
})();
