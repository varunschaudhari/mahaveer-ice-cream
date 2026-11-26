/*
 * Counter Bulk tab: Record payment (advance; amount + cash/UPI), Ready, Picked up (balance via the
 * Cash / UPI / Mixed dialog → bill printed). The page reloads after each action. Chrome 109.
 */
(function () {
  'use strict';

  var C = window.Counter;
  var root = document.querySelector('[data-bulk-counter]');
  if (!root) {
    return;
  }
  var TEXT = JSON.parse(root.getAttribute('data-texts'));

  function text(key, params) {
    var value = TEXT[key] || key;
    Object.keys(params || {}).forEach(function (name) {
      value = value.split('{' + name + '}').join(String(params[name]));
    });
    return value;
  }

  function reloadSoon() {
    window.setTimeout(function () { window.location.reload(); }, 900);
  }

  async function act(body, done) {
    try {
      var result = await C.api('/api/bulk_action.php', body);
      if (done) {
        C.toast(done(result), 'success');
      }
      reloadSoon();
      return result;
    } catch (err) {
      C.toast(err.message, 'error');
      return null;
    }
  }

  root.addEventListener('click', async function (event) {
    var button = event.target.closest('button');
    if (!button || button.disabled) {
      return;
    }

    if (button.hasAttribute('data-bulk-pay')) {
      var due = parseInt(button.getAttribute('data-due'), 10);
      var rupees = await C.numpad({
        title: text('amount_title', { amount: C.money(due) }),
        prefix: '₹',
        maxLength: 6,
        minLength: 1,
        initial: String(Math.floor(due / 100))
      });
      if (!rupees) {
        return;
      }
      var method = await C.choose({ title: text('method_title'), options: [text('cash'), text('upi')] });
      if (!method) {
        return;
      }
      button.disabled = true;
      await act({
        action: 'payment',
        order_id: parseInt(button.getAttribute('data-bulk-pay'), 10),
        method: method === text('cash') ? 'cash' : 'upi',
        amount_paise: parseInt(rupees, 10) * 100
      }, function () { return text('paid', { amount: C.money(parseInt(rupees, 10) * 100) }); });
      button.disabled = false;
    } else if (button.hasAttribute('data-bulk-ready')) {
      button.disabled = true;
      await act({ action: 'ready', order_id: parseInt(button.getAttribute('data-bulk-ready'), 10) }, function () { return text('ready'); });
    } else if (button.hasAttribute('data-bulk-complete')) {
      var id = parseInt(button.getAttribute('data-bulk-complete'), 10);
      var balance = parseInt(button.getAttribute('data-balance'), 10);
      var complete = async function (payMethod, cashPaise) {
        var result = await act({ action: 'complete', order_id: id, method: payMethod, cash_paise: cashPaise }, function (r) {
          return text('completed', { bill: r.bill_no });
        });
        if (result) {
          C.print(result.print_url);
        }
        return !!result;
      };
      if (balance === 0) {
        if (window.confirm(text('confirm_free'))) {
          await complete('cash', 0); // fully paid in advance: nothing to collect, bill only
        }
      } else {
        C.pay(balance, complete);
      }
    }
  });
})();
