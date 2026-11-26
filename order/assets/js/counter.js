/*
 * Counter screen shared code (every tab). Plain JavaScript for Chrome 109 on Windows 7:
 * no newer APIs (see ARCHITECTURE.md §10). Exposes window.Counter:
 *   api(url, body?)        JSON call with CSRF header; throws {message, code}
 *   print(url)             prints a page silently through a hidden iframe (--kiosk-printing)
 *   toast(message, type)   short message at the bottom
 *   numpad({...})          on-screen number pad dialog → Promise<string|null>
 *   choose({...})          pick a reason (or type one) → Promise<string|null>
 *   money(paise)           '₹1,250' / '₹40.50'
 *   isOnline(), beep(), t(key, params)
 */
window.Counter = (function () {
  'use strict';

  var app = document.querySelector('.counter-app');
  var texts = app ? JSON.parse(app.getAttribute('data-i18n') || '{}') : {};
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var online = true;

  function t(key, params) {
    var text = texts[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      text = text.split('{' + name + '}').join(String(params[name]));
    });
    return text;
  }

  function money(paise) {
    var sign = paise < 0 ? '-' : '';
    var abs = Math.abs(paise);
    var rupees = Math.floor(abs / 100).toLocaleString('en-IN');
    var fraction = abs % 100;
    return sign + '₹' + rupees + (fraction ? '.' + String(fraction).padStart(2, '0') : '');
  }

  // ---------- connection ----------

  var banner = document.querySelector('[data-offline-banner]');

  function setOnline(value) {
    if (online === value) {
      return;
    }
    online = value;
    if (banner) {
      banner.hidden = value;
    }
    document.dispatchEvent(new CustomEvent('counter:online', { detail: value }));
  }

  async function api(url, body) {
    var options = {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf }
    };
    if (body) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }

    var response;
    try {
      response = await fetch(url, options);
    } catch (networkError) {
      setOnline(false);
      throw { message: t('offline'), code: 'offline' };
    }
    setOnline(true);

    if (response.status === 401) {
      window.location.href = '/counter/';
      throw { message: '', code: 'auth.required' };
    }
    var data;
    try {
      data = await response.json();
    } catch (parseError) {
      throw { message: t('error'), code: 'error' };
    }
    if (!data.ok) {
      throw { message: data.message || t('error'), code: data.error || 'error' };
    }
    return data.data;
  }

  // ---------- poll (every 5 s): new orders, table alerts, connection check ----------
  // The Online tab asks for the full order list (setPollFull), the Tables tab for the tiles
  // (setPollView('tables')); other tabs only need counts. Pages listen for 'counter:poll'.
  // The alert bar + beep repeat until a staff member taps the bar (ARCHITECTURE §7); what was
  // acknowledged (newest order id, newest alert id) is kept in this counter's localStorage.

  var pollFull = false;
  var pollView = '';
  var ACK_KEY = 'mahavir.counter.ack2';
  var badge = document.querySelector('[data-online-badge]');
  var tablesBadge = document.querySelector('[data-tables-badge]');
  var alertBar = document.querySelector('[data-alert-bar]');
  var latest = { o: 0, a: 0 };
  var lastSeen = { o: 0, a: 0 };
  var unacked = { o: false, a: false };

  function acked() {
    try {
      var value = JSON.parse(window.localStorage.getItem(ACK_KEY));
      return value && typeof value === 'object' ? { o: value.o || 0, a: value.a || 0 } : { o: 0, a: 0 };
    } catch (e) {
      return { o: 0, a: 0 };
    }
  }

  function acknowledge() {
    try {
      window.localStorage.setItem(ACK_KEY, JSON.stringify(latest));
    } catch (e) { /* the bar shows again on the next poll */ }
    unacked = { o: false, a: false };
    if (alertBar) {
      alertBar.hidden = true;
    }
  }

  function setBadge(el, count) {
    if (el) {
      el.textContent = count;
      el.hidden = count === 0;
    }
  }

  function updateAlerts(data) {
    latest = { o: data.latest_new_id, a: data.latest_alert_id };
    setBadge(badge, data.new_count);
    setBadge(tablesBadge, data.alert_count);
    var seen = acked();
    unacked = {
      o: data.new_count > 0 && data.latest_new_id > seen.o,
      a: data.alert_count > 0 && data.latest_alert_id > seen.a
    };
    if (alertBar) {
      var parts = [];
      if (unacked.o) {
        parts.push(t('new_orders', { n: data.new_count }));
      }
      if (unacked.a) {
        parts.push(t('table_alerts', { n: data.alert_count }));
      }
      alertBar.hidden = parts.length === 0;
      alertBar.textContent = parts.join(' · ');
    }
    if ((unacked.o && data.latest_new_id > lastSeen.o) || (unacked.a && data.latest_alert_id > lastSeen.a)) {
      beep(); // something new just arrived: beep at once, then every 10 s
    }
    lastSeen = { o: data.latest_new_id, a: data.latest_alert_id };
  }

  async function poll() {
    var params = [];
    if (pollFull) {
      params.push('full=1');
    }
    if (pollView) {
      params.push('view=' + pollView);
    }
    try {
      var data = await api('/api/poll.php' + (params.length ? '?' + params.join('&') : ''));
      updateAlerts(data);
      document.dispatchEvent(new CustomEvent('counter:poll', { detail: data }));
    } catch (e) { /* offline banner is already shown by api() */ }
  }

  window.setInterval(poll, 5000);
  window.setTimeout(poll, 300);
  window.setInterval(function () {
    if (alertBar && !alertBar.hidden) {
      beep();
    }
  }, 10000);
  window.addEventListener('offline', function () { setOnline(false); });
  window.addEventListener('online', poll);

  if (alertBar) {
    alertBar.addEventListener('click', function () {
      var target = unacked.o ? '/counter/online.php' : '/counter/tables.php';
      acknowledge();
      if (window.location.pathname !== target) {
        window.location.href = target;
      }
    });
  }

  // ---------- printing ----------

  var frame = document.querySelector('[data-print-frame]');

  function print(url) {
    return new Promise(function (resolve) {
      frame.onload = function () {
        var doc = frame.contentDocument;
        var go = function () {
          frame.contentWindow.focus();
          frame.contentWindow.print();
          resolve();
        };
        if (doc && doc.fonts && doc.fonts.ready) {
          doc.fonts.ready.then(go);
        } else {
          go();
        }
      };
      frame.src = url;
    });
  }

  // ---------- toast ----------

  var toastEl = document.querySelector('[data-toast]');
  var toastTimer = null;

  function toast(message, type) {
    if (!toastEl || !message) {
      return;
    }
    toastEl.textContent = message;
    toastEl.className = 'toast toast-' + (type || 'info');
    toastEl.hidden = false;
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () { toastEl.hidden = true; }, type === 'error' ? 6000 : 4000);
  }

  // ---------- number pad dialog ----------

  var numpadEl = document.querySelector('[data-numpad]');

  /**
   * options: title, mask (show dots, for PINs), maxLength, minLength, prefix ('₹'), initial
   * Resolves with the typed digits, or null when cancelled.
   */
  function numpad(options) {
    options = options || {};
    var value = options.initial || '';
    var display = numpadEl.querySelector('[data-numpad-display]');
    var error = numpadEl.querySelector('[data-numpad-error]');
    numpadEl.querySelector('[data-numpad-title]').textContent = options.title || '';
    error.hidden = true;

    function render() {
      var shown = options.mask ? '●'.repeat(value.length) : value;
      display.textContent = (options.prefix || '') + (shown || ' ');
    }
    render();

    return new Promise(function (resolve) {
      function close(result) {
        numpadEl.removeEventListener('click', onClick);
        document.removeEventListener('keydown', onKey);
        numpadEl.close();
        resolve(result);
      }
      function press(key) {
        if (key === 'back') {
          value = value.slice(0, -1);
        } else if (key === 'clear') {
          value = '';
        } else if (value.length < (options.maxLength || 6)) {
          value += key;
        }
        error.hidden = true;
        render();
        if (options.mask && options.maxLength && value.length === options.maxLength) {
          close(value);
        }
      }
      function onClick(event) {
        var button = event.target.closest('button');
        if (!button) {
          return;
        }
        if (button.hasAttribute('data-key')) {
          press(button.getAttribute('data-key'));
        } else if (button.hasAttribute('data-numpad-cancel')) {
          close(null);
        } else if (button.hasAttribute('data-numpad-ok')) {
          if (value.length < (options.minLength || 1)) {
            error.textContent = options.minError || '';
            error.hidden = !options.minError;
            return;
          }
          close(value);
        }
      }
      function onKey(event) {
        if (/^[0-9]$/.test(event.key)) {
          press(event.key);
        } else if (event.key === 'Backspace') {
          press('back');
        } else if (event.key === 'Enter') {
          numpadEl.querySelector('[data-numpad-ok]').click();
        }
      }
      numpadEl.addEventListener('click', onClick);
      document.addEventListener('keydown', onKey);
      numpadEl.addEventListener('cancel', function onCancel(event) {
        event.preventDefault();
        numpadEl.removeEventListener('cancel', onCancel);
        close(null);
      });
      numpadEl.showModal();
    });
  }

  // ---------- choose-a-reason dialog ----------

  var chooseEl = document.querySelector('[data-choose]');

  /** options: title, options (list of strings), other (true = allow typing). Resolves text or null. */
  function choose(options) {
    var list = chooseEl.querySelector('[data-choose-options]');
    var otherBox = chooseEl.querySelector('[data-choose-other]');
    var input = chooseEl.querySelector('[data-choose-input]');
    var ok = chooseEl.querySelector('[data-choose-ok]');
    chooseEl.querySelector('[data-choose-title]').textContent = options.title || '';
    list.textContent = '';
    otherBox.hidden = true;
    ok.hidden = true;
    input.value = '';

    var choices = (options.options || []).slice();
    if (options.other) {
      choices.push(null);
    }
    choices.forEach(function (text) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'counter-btn';
      button.textContent = text === null ? t('other') : text;
      button.setAttribute('data-choice', text === null ? '' : text);
      if (text === null) {
        button.setAttribute('data-choose-other-btn', '');
      }
      list.appendChild(button);
    });

    return new Promise(function (resolve) {
      function close(result) {
        chooseEl.removeEventListener('click', onClick);
        chooseEl.close();
        resolve(result);
      }
      function onClick(event) {
        var button = event.target.closest('button');
        if (!button) {
          return;
        }
        if (button.hasAttribute('data-choose-other-btn')) {
          otherBox.hidden = false;
          ok.hidden = false;
          input.focus();
        } else if (button.hasAttribute('data-choice')) {
          close(button.getAttribute('data-choice'));
        } else if (button.hasAttribute('data-choose-cancel')) {
          close(null);
        } else if (button.hasAttribute('data-choose-ok') && input.value.trim() !== '') {
          close(input.value.trim());
        }
      }
      chooseEl.addEventListener('click', onClick);
      chooseEl.addEventListener('cancel', function onCancel(event) {
        event.preventDefault();
        chooseEl.removeEventListener('cancel', onCancel);
        close(null);
      });
      chooseEl.showModal();
    });
  }

  // ---------- sound (used for order alerts from M3) ----------
  // Chrome only plays sound after a tap on the page, unless the counter shortcut has
  // --autoplay-policy=no-user-gesture-required (DEPLOYMENT.md §6). The first tap anywhere
  // unlocks it; a small hint shows until then.

  var AudioCtx = window.AudioContext || window.webkitAudioContext;
  var audio = AudioCtx ? new AudioCtx() : null;
  var hint = document.querySelector('[data-sound-hint]');

  function updateHint() {
    if (hint) {
      hint.hidden = !audio || audio.state === 'running';
    }
  }
  if (audio) {
    document.addEventListener('pointerdown', function unlock() {
      audio.resume().then(updateHint);
      if (audio.state === 'running') {
        document.removeEventListener('pointerdown', unlock);
      }
    });
    window.setTimeout(updateHint, 500);
  }

  /** Two short tones (no audio files needed). */
  function beep() {
    if (!audio || audio.state !== 'running') {
      return;
    }
    [0, 0.35].forEach(function (offset, i) {
      var osc = audio.createOscillator();
      var gain = audio.createGain();
      osc.frequency.value = i === 0 ? 880 : 1175;
      gain.gain.value = 0.25;
      osc.connect(gain);
      gain.connect(audio.destination);
      osc.start(audio.currentTime + offset);
      osc.stop(audio.currentTime + offset + 0.3);
    });
  }

  // ---------- cash payment dialog (billing + online pickup) ----------

  var cashDialog = document.querySelector('[data-cash-dialog]');
  var cashState = null;

  function tenderOptions(total) {
    var options = [total];
    [5000, 10000, 50000].forEach(function (step) {
      var next = Math.ceil((total + 1) / step) * step;
      if (options.indexOf(next) === -1) {
        options.push(next);
      }
    });
    return options.sort(function (a, b) { return a - b; }).slice(0, 4);
  }

  function renderCash() {
    var change = cashState.tendered - cashState.total;
    cashDialog.querySelector('[data-cash-received]').textContent = money(cashState.tendered);
    cashDialog.querySelector('[data-cash-change]').textContent = change >= 0 ? money(change) : '—';
    cashDialog.querySelector('[data-cash-save]').disabled = change < 0 || cashState.busy;
    cashDialog.querySelectorAll('[data-tender]').forEach(function (button) {
      button.classList.toggle('is-selected', parseInt(button.getAttribute('data-tender'), 10) === cashState.tendered);
    });
  }

  /**
   * Cash payment of `total` paise: quick amounts (exact, next ₹50 / ₹100 / ₹500, other) and the
   * change to give. Save calls onSave(), which resolves true when the bill was saved.
   */
  function cash(total, onSave) {
    cashState = { total: total, tendered: total, onSave: onSave, busy: false };
    cashDialog.querySelector('[data-cash-total]').textContent = money(total);
    var grid = cashDialog.querySelector('[data-tender-options]');
    grid.textContent = '';
    tenderOptions(total).forEach(function (amount) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'counter-btn';
      button.setAttribute('data-tender', amount);
      button.textContent = money(amount);
      grid.appendChild(button);
    });
    var other = document.createElement('button');
    other.type = 'button';
    other.className = 'counter-btn';
    other.setAttribute('data-tender-other', '');
    other.textContent = t('other');
    grid.appendChild(other);
    renderCash();
    cashDialog.showModal();
  }

  if (cashDialog) {
    cashDialog.addEventListener('click', async function (event) {
      var button = event.target.closest('button');
      if (!button || !cashState) {
        return;
      }
      if (button.hasAttribute('data-tender')) {
        cashState.tendered = parseInt(button.getAttribute('data-tender'), 10);
        renderCash();
      } else if (button.hasAttribute('data-tender-other')) {
        var rupees = await numpad({ title: t('received_amount'), prefix: '₹', maxLength: 6, minLength: 1 });
        if (rupees) {
          cashState.tendered = parseInt(rupees, 10) * 100;
          renderCash();
        }
      } else if (button.hasAttribute('data-cash-cancel')) {
        cashDialog.close();
      } else if (button.hasAttribute('data-cash-save')) {
        cashState.busy = true;
        renderCash();
        var saved = await cashState.onSave();
        cashState.busy = false;
        if (saved) {
          cashDialog.close();
        } else {
          renderCash();
        }
      }
    });
  }

  /**
   * Asks how `total` is paid and calls onPay(method, cashPaise) → Promise<boolean>.
   * Used by the Online tab's "Picked up"; billing has its own three buttons.
   */
  async function pay(total, onPay) {
    var labels = [t('pay_cash'), t('pay_upi'), t('pay_mixed')];
    var choice = await choose({ title: t('pay_title', { total: money(total) }), options: labels });
    if (choice === labels[0]) {
      cash(total, function () { return onPay('cash', 0); });
    } else if (choice === labels[1]) {
      await onPay('upi', 0);
    } else if (choice === labels[2]) {
      var rupees = await numpad({ title: t('cash_part', { total: money(total) }), prefix: '₹', maxLength: 6, minLength: 1 });
      if (!rupees) {
        return;
      }
      var part = parseInt(rupees, 10) * 100;
      if (part <= 0 || part >= total) {
        toast(t('mixed_invalid'), 'error');
        return;
      }
      await onPay('mixed', part);
    }
  }

  return {
    api: api,
    print: print,
    toast: toast,
    numpad: numpad,
    choose: choose,
    cash: cash,
    pay: pay,
    money: money,
    beep: beep,
    t: t,
    acknowledge: acknowledge,
    setPollFull: function (value) {
      pollFull = value;
      poll();
    },
    setPollView: function (view) {
      pollView = view;
      poll();
    },
    refresh: poll,
    isOnline: function () { return online; }
  };
})();
