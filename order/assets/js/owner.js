/*
 * Owner's phone (SPEC §11). Polls api/poll.php?view=owner every 10 s: today's sales, tables
 * calling, live orders and bulk orders, with a beep when something new arrives while the page
 * is open. Accept / Cancel / Approve / Reject go to api/owner_action.php.
 *
 * Web Push: registers /sw.js for /owner/, asks permission on a tap, sends this phone's
 * subscription to api/push_subscribe.php (mute switch, test button). The page language is kept
 * in Cache Storage, where the service worker reads it, so notifications use the same language.
 * Same code style as the counter (ARCHITECTURE §10).
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-owner]');
  if (!root) {
    return;
  }
  var TEXT = JSON.parse(root.getAttribute('data-texts'));
  var CANCEL_REASONS = JSON.parse(root.getAttribute('data-cancel-reasons'));
  var BULK_REASONS = JSON.parse(root.getAttribute('data-bulk-reasons'));
  var LANG = root.getAttribute('data-lang');
  var VAPID = root.getAttribute('data-vapid');
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var POLL_MS = 10000;
  var LOGIN_URL = '/admin/?next=%2Fowner%2F';
  var PREFS_CACHE = 'mahavir-prefs';
  var PREFS_URL = '/__prefs/owner';

  var data = null;          // latest owner block from the poll
  var known = null;         // ids that needed attention at the last poll (null before the first)
  var working = {};         // order id → true while an action runs
  var highlightId = orderFromUrl(window.location.href);
  var highlightShown = false;

  // ---------- small helpers ----------

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

  function money(paise) {
    var rupees = Math.floor(paise / 100).toLocaleString('en-IN');
    var fraction = paise % 100;
    return '₹' + rupees + (fraction ? '.' + String(fraction).padStart(2, '0') : '');
  }

  /** '250' / '250.5' / '250.50' → paise, without floating point; null when not an amount. */
  function toPaise(value) {
    var match = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(String(value).trim());
    if (!match) {
      return null;
    }
    return parseInt(match[1], 10) * 100 + parseInt((match[2] || '').padEnd(2, '0'), 10);
  }

  function orderFromUrl(url) {
    var match = /[?&]order=(\d+)/.exec(url || '');
    return match ? parseInt(match[1], 10) : 0;
  }

  var toastEl = document.querySelector('[data-toast]');
  var toastTimer = null;
  function toast(message, type) {
    if (!message) {
      return;
    }
    toastEl.textContent = message;
    toastEl.className = 'owner-toast is-' + (type || 'info');
    toastEl.hidden = false;
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () { toastEl.hidden = true; }, 4000);
  }

  var offlineEl = document.querySelector('[data-offline]');
  function setOnline(online) {
    offlineEl.hidden = online;
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
      throw { message: text('offline') };
    }
    setOnline(true);
    if (response.status === 401) {
      window.location.href = LOGIN_URL;
      throw { message: '' };
    }
    var json;
    try {
      json = await response.json();
    } catch (parseError) {
      throw { message: text('error') };
    }
    if (!json.ok) {
      throw { message: json.message || text('error') };
    }
    return json.data;
  }

  // ---------- sound: two short tones when something new arrives ----------
  // Phones only play sound after a tap on the page; a hint shows until then.

  var AudioCtx = window.AudioContext || window.webkitAudioContext;
  var audio = null;
  var soundHint = document.querySelector('[data-sound-hint]');

  function updateSoundHint() {
    soundHint.hidden = !AudioCtx || (audio !== null && audio.state === 'running');
  }
  if (AudioCtx) {
    document.addEventListener('pointerdown', function unlock() {
      if (audio === null) {
        audio = new AudioCtx();
      }
      audio.resume().then(updateSoundHint);
      if (audio.state === 'running') {
        document.removeEventListener('pointerdown', unlock);
      }
    });
  }
  updateSoundHint();

  function beep() {
    if (audio === null || audio.state !== 'running') {
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

  // ---------- rendering ----------

  var ACTIONS = {
    takeaway: { received: ['accept', 'cancel'], preparing: ['cancel'], ready: ['cancel'] },
    table: { received: ['accept', 'cancel'], preparing: ['cancel'] },
    bulk: { pending_approval: ['approve', 'reject'], preparing: ['cancel'], ready: ['cancel'] }
  };

  function card(order) {
    var article = el('article', 'owner-card status-' + order.status + ' type-' + order.type);
    article.id = 'order-' + order.id;
    if (order.id === highlightId) {
      article.className += ' is-highlight';
    }

    var head = el('div', 'owner-card__head');
    if (order.type === 'table') {
      head.appendChild(el('span', 'owner-card__table', text('table', { n: order.table })));
    }
    head.appendChild(el('span', 'owner-card__token', text('token', { n: order.token })));
    head.appendChild(el('span', 'owner-card__status', order.status_label));
    article.appendChild(head);

    var meta = [];
    if (order.age_mins !== undefined) {
      meta.push(order.age_mins < 1 ? text('just_now') : text('age', { n: order.age_mins }));
    }
    if (order.slot || order.pickup) {
      meta.push(text('pickup', { slot: order.slot || order.pickup }));
    }
    article.appendChild(el('p', 'owner-card__meta', meta.join(' · ')));

    if (order.customer || order.mobile) {
      var who = el('p', 'owner-card__customer', order.customer + ' ');
      if (order.mobile) {
        var phone = el('a', '', order.mobile);
        phone.href = 'tel:+91' + order.mobile;
        who.appendChild(phone);
      }
      article.appendChild(who);
    }

    var items = el('ul', 'owner-card__items');
    order.items.forEach(function (line) {
      items.appendChild(el('li', '', line.qty + ' × ' + line.name));
    });
    article.appendChild(items);

    if (order.note) {
      article.appendChild(el('p', 'owner-card__note', order.note));
    }

    var total = el('p', 'owner-card__total', money(order.total_paise));
    if (order.type === 'bulk' && order.status !== 'pending_approval') {
      total.appendChild(el('span', 'owner-card__paid', ' · ' + text('advance', { amount: money(order.advance_paise) }) + ' · ' + text('paid', { amount: money(order.paid_paise) })));
    }
    article.appendChild(total);

    var actions = el('div', 'owner-card__actions');
    ((ACTIONS[order.type] || {})[order.status] || []).forEach(function (action) {
      var danger = action === 'cancel' || action === 'reject';
      var button = el('button', 'btn ' + (danger ? 'btn-secondary owner-btn-danger' : 'btn-primary'), text(action));
      button.type = 'button';
      button.setAttribute('data-action', action);
      button.setAttribute('data-id', order.id);
      button.disabled = !!working[order.id];
      actions.appendChild(button);
    });
    if (actions.firstChild) {
      article.appendChild(actions);
    }
    return article;
  }

  function renderList(listSelector, emptySelector, orders, emptyKey) {
    var list = document.querySelector(listSelector);
    var empty = document.querySelector(emptySelector);
    list.textContent = '';
    orders.forEach(function (order) {
      list.appendChild(card(order));
    });
    empty.textContent = text(emptyKey);
    empty.hidden = orders.length > 0;
  }

  function render(time) {
    if (!data) {
      return;
    }
    document.querySelector('[data-sales]').textContent = money(data.sales_paise);
    document.querySelector('[data-bills]').textContent = text('bills', { n: data.bill_count });
    if (time) {
      document.querySelector('[data-updated]').textContent = '· ' + text('updated', { time: time });
    }

    var alerts = document.querySelector('[data-alerts]');
    alerts.textContent = '';
    data.alerts.forEach(function (alert) {
      var item = el('li', 'owner-alert');
      item.appendChild(el('strong', 'owner-alert__table', text('table', { n: alert.table })));
      item.appendChild(el('span', '', alert.label + ' · ' + alert.time));
      alerts.appendChild(item);
    });
    document.querySelector('[data-alerts-section]').hidden = data.alerts.length === 0;

    renderList('[data-orders]', '[data-orders-empty]', data.orders, 'no_orders');
    renderList('[data-bulk]', '[data-bulk-empty]', data.bulk, 'no_bulk');

    if (highlightId && !highlightShown) {
      var target = document.getElementById('order-' + highlightId);
      if (target) {
        highlightShown = true;
        target.scrollIntoView({ block: 'center' });
      }
    }
  }

  /** Beeps when a new order, bulk request or table alert appears (not on the first load). */
  function checkNew() {
    var ids = [];
    data.orders.forEach(function (o) { if (o.status === 'received') { ids.push('o' + o.id); } });
    data.bulk.forEach(function (o) { if (o.status === 'pending_approval') { ids.push('o' + o.id); } });
    data.alerts.forEach(function (a) { ids.push('a' + a.id); });
    var isNew = known !== null && ids.some(function (id) { return !known[id]; });
    known = {};
    ids.forEach(function (id) { known[id] = true; });
    if (isNew) {
      beep();
    }
  }

  var polling = false;
  async function poll() {
    if (polling) {
      return;
    }
    polling = true;
    try {
      var result = await api('/api/poll.php?view=owner');
      data = result.owner;
      render(result.time);
      checkNew();
    } catch (err) {
      // the offline banner (or the login page) says what is wrong
    } finally {
      polling = false;
    }
  }

  // ---------- actions ----------

  function findOrder(id) {
    var all = data ? data.orders.concat(data.bulk) : [];
    return all.filter(function (o) { return o.id === id; })[0] || null;
  }

  async function run(id, body) {
    working[id] = true;
    render();
    try {
      var result = await api('/api/owner_action.php', body);
      toast(result.message, 'success');
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      delete working[id];
      await poll();
    }
  }

  root.addEventListener('click', async function (event) {
    var button = event.target.closest('[data-action]');
    if (!button || button.disabled) {
      return;
    }
    var id = parseInt(button.getAttribute('data-id'), 10);
    var order = findOrder(id);
    if (!order) {
      return;
    }
    var action = button.getAttribute('data-action');

    if (action === 'accept') {
      await run(id, { action: 'accept', order_id: id });
    } else if (action === 'cancel' || action === 'reject') {
      var title = order.type !== 'bulk'
        ? text('cancel_title', { token: order.token })
        : text(action === 'reject' ? 'reject_title' : 'cancel_bulk_title', { name: order.customer });
      var reason = await ask({ title: title, choices: order.type === 'bulk' ? BULK_REASONS : CANCEL_REASONS });
      if (reason) {
        await run(id, { action: 'cancel', order_id: id, reason: reason });
      }
    } else if (action === 'approve') {
      var advance = await ask({
        title: text('approve_title', { name: order.customer }),
        amount: true,
        help: text('advance_help', { total: money(order.total_paise) })
      });
      if (advance !== null) {
        await run(id, { action: 'approve', order_id: id, advance_paise: advance });
      }
    }
  });

  // ---------- dialog: pick a reason (or type one), or enter the advance ----------

  var dialog = document.querySelector('[data-dialog]');
  var dialogForm = dialog.querySelector('[data-dialog-form]');
  var dialogChoices = dialog.querySelector('[data-dialog-choices]');
  var dialogField = dialog.querySelector('[data-dialog-field]');
  var dialogInput = dialog.querySelector('[data-dialog-input]');
  var dialogOk = dialog.querySelector('[data-dialog-ok]');
  var dialogState = null; // {resolve, amount}

  function finish(value) {
    if (!dialogState) {
      return;
    }
    var resolve = dialogState.resolve;
    dialogState = null;
    if (dialog.open) {
      dialog.close();
    }
    resolve(value);
  }

  function showField(label, help, amount) {
    dialog.querySelector('[data-dialog-label]').textContent = label;
    dialog.querySelector('[data-dialog-help]').textContent = help || '';
    dialogInput.value = amount ? '0' : '';
    dialogInput.inputMode = amount ? 'decimal' : 'text';
    dialogInput.maxLength = amount ? 10 : 200;
    dialogField.hidden = false;
    dialogOk.hidden = false;
    dialogInput.focus();
    if (amount) {
      dialogInput.select();
    }
  }

  /** Resolves with the chosen / typed reason, the advance in paise, or null when cancelled. */
  function ask(options) {
    return new Promise(function (resolve) {
      dialogState = { resolve: resolve, amount: !!options.amount };
      dialog.querySelector('[data-dialog-title]').textContent = options.title;
      dialogChoices.textContent = '';
      dialogField.hidden = true;
      dialogOk.hidden = true;
      dialogInput.removeAttribute('aria-invalid');

      (options.choices || []).forEach(function (choice) {
        var b = el('button', 'btn btn-secondary owner-choice', choice);
        b.type = 'button';
        b.setAttribute('data-choice', choice);
        dialogChoices.appendChild(b);
      });
      if (options.choices) {
        var other = el('button', 'btn btn-secondary owner-choice', text('reason_other'));
        other.type = 'button';
        other.setAttribute('data-choice-other', '');
        dialogChoices.appendChild(other);
      }
      dialogChoices.hidden = !options.choices;

      dialog.showModal();
      if (options.amount) {
        showField(text('advance_label'), options.help, true);
      }
    });
  }

  dialogChoices.addEventListener('click', function (event) {
    var b = event.target.closest('button');
    if (!b) {
      return;
    }
    if (b.hasAttribute('data-choice-other')) {
      showField(text('reason_other'), '', false);
    } else {
      finish(b.getAttribute('data-choice'));
    }
  });

  dialogForm.addEventListener('submit', function (event) {
    event.preventDefault();
    if (!dialogState) {
      return;
    }
    var value = dialogInput.value.trim();
    var result = dialogState.amount ? toPaise(value) : (value === '' ? null : value);
    if (result === null) {
      dialogInput.setAttribute('aria-invalid', 'true');
      dialogInput.focus();
      return;
    }
    finish(result);
  });

  dialog.querySelector('[data-dialog-back]').addEventListener('click', function () { finish(null); });
  dialog.addEventListener('close', function () { finish(null); }); // Esc / Android back

  // ---------- Web Push ----------

  var pushStateEl = document.querySelector('[data-push-state]');
  var enableButton = document.querySelector('[data-push-enable]');
  var testButton = document.querySelector('[data-push-test]');
  var muteRow = document.querySelector('[data-push-mute-row]');
  var muteBox = document.querySelector('[data-push-mute]');
  var subscription = null;

  var PUSH_TEXT = {
    unsupported: 'push.unsupported',
    not_configured: 'push.not_configured',
    denied: 'push.denied',
    failed: 'push.failed'
  };

  /** state: unsupported | not_configured | denied | off | failed | on */
  function showPush(state, muted) {
    enableButton.hidden = state !== 'off' && state !== 'failed';
    testButton.hidden = state !== 'on' || !!muted;
    muteRow.hidden = state !== 'on';
    muteBox.checked = !!muted;
    var message = state === 'on' ? (muted ? 'push.muted' : 'push.on') : PUSH_TEXT[state];
    pushStateEl.textContent = message ? text(message) : '';
    pushStateEl.hidden = !message;
  }

  function keyBytes(base64url) {
    var padded = base64url + '===='.slice((base64url.length % 4) || 4);
    var raw = window.atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) {
      bytes[i] = raw.charCodeAt(i);
    }
    return bytes;
  }

  function sameKey(buffer, base64url) {
    var a = new Uint8Array(buffer);
    var b = keyBytes(base64url);
    if (a.length !== b.length) {
      return false;
    }
    for (var i = 0; i < a.length; i++) {
      if (a[i] !== b[i]) {
        return false;
      }
    }
    return true;
  }

  /** The service worker shows notifications in this language (Cache Storage, read by sw.js). */
  function savePrefs() {
    if (!window.caches) {
      return;
    }
    window.caches.open(PREFS_CACHE).then(function (cache) {
      return cache.put(PREFS_URL, new Response(JSON.stringify({ lang: LANG }), { headers: { 'Content-Type': 'application/json' } }));
    }).catch(function () {});
  }

  async function sendSubscription() {
    try {
      var state = await api('/api/push_subscribe.php', { action: 'subscribe', subscription: subscription.toJSON() });
      showPush('on', state.muted);
    } catch (err) {
      showPush('failed');
      toast(err.message, 'error');
    }
  }

  async function subscribe(registration) {
    subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(VAPID) });
    await sendSubscription();
  }

  /** The active service worker; rejects after 15 s if it never starts (instead of "Loading…" forever). */
  function workerReady() {
    return new Promise(function (resolve, reject) {
      var timer = window.setTimeout(function () { reject(new Error('service worker not ready')); }, 15000);
      navigator.serviceWorker.ready.then(function (registration) {
        window.clearTimeout(timer);
        resolve(registration);
      });
    });
  }

  async function initPush() {
    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      showPush('unsupported');
      return;
    }
    try {
      await navigator.serviceWorker.register('/sw.js', { scope: '/owner/' });
    } catch (err) {
      showPush('unsupported');
      return;
    }
    savePrefs();
    if (!VAPID) {
      showPush('not_configured');
      return;
    }
    if (Notification.permission === 'denied') {
      showPush('denied');
      return;
    }

    try {
      var registration = await workerReady();
      subscription = await registration.pushManager.getSubscription();
      if (subscription && subscription.options && subscription.options.applicationServerKey
          && !sameKey(subscription.options.applicationServerKey, VAPID)) {
        // The server has new VAPID keys: the old subscription can no longer receive anything.
        var oldEndpoint = subscription.endpoint;
        await subscription.unsubscribe();
        api('/api/push_subscribe.php', { action: 'unsubscribe', endpoint: oldEndpoint }).catch(function () {});
        subscription = null;
        if (Notification.permission === 'granted') {
          await subscribe(registration);
          return;
        }
      }
      if (!subscription) {
        showPush('off');
        return;
      }
      await sendSubscription(); // keeps the server's copy current (the browser may renew it)
    } catch (err) {
      showPush('failed');
    }
  }

  enableButton.addEventListener('click', async function () {
    enableButton.disabled = true;
    try {
      var permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        showPush(permission === 'denied' ? 'denied' : 'off');
        return;
      }
      await subscribe(await workerReady());
    } catch (err) {
      showPush('failed');
    } finally {
      enableButton.disabled = false;
    }
  });

  muteBox.addEventListener('change', async function () {
    if (!subscription) {
      return;
    }
    muteBox.disabled = true;
    try {
      var state = await api('/api/push_subscribe.php', { action: 'mute', endpoint: subscription.endpoint, muted: muteBox.checked });
      showPush(state.subscribed ? 'on' : 'off', state.muted);
    } catch (err) {
      muteBox.checked = !muteBox.checked;
      toast(err.message, 'error');
    } finally {
      muteBox.disabled = false;
    }
  });

  testButton.addEventListener('click', async function () {
    if (!subscription) {
      return;
    }
    testButton.disabled = true;
    try {
      var result = await api('/api/push_subscribe.php', { action: 'test', endpoint: subscription.endpoint });
      toast(result.message, 'success');
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      testButton.disabled = false;
    }
  });

  // A tapped notification while this page is already open: show that order.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (event) {
      if (event.data && event.data.type === 'open') {
        highlightId = orderFromUrl(event.data.url);
        highlightShown = false;
        poll();
      }
    });
  }

  // Opened from the home screen: the "Add to Home screen" tip is not needed.
  if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
    document.querySelector('[data-install-hint]').hidden = true;
  }

  // ---------- start ----------

  initPush();
  poll();
  window.setInterval(poll, POLL_MS);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      poll(); // phones slow down timers in the background: catch up at once
    }
  });
})();
