/*
 * Counter login: tap a name, then enter the 4-digit PIN; the form submits on the 4th digit.
 * Plain JavaScript for Chrome 109 (no newer APIs). Also works with a physical keyboard.
 */
(function () {
  'use strict';

  var form = document.getElementById('pin-form');
  if (!form) {
    return;
  }
  var panel = document.querySelector('[data-pin-panel]');
  var nameEl = form.querySelector('[data-pin-name]');
  var staffInput = form.querySelector('input[name="staff_id"]');
  var pinInput = form.querySelector('input[name="pin"]');
  var dots = form.querySelectorAll('.pin-dots span');
  var tiles = document.querySelectorAll('.staff-tile');
  var pin = '';
  var submitting = false;

  function render() {
    dots.forEach(function (dot, i) {
      dot.classList.toggle('is-filled', i < pin.length);
    });
  }

  function show(tile) {
    tiles.forEach(function (t) {
      t.classList.toggle('is-selected', t === tile);
    });
    staffInput.value = tile.getAttribute('data-staff-id');
    nameEl.textContent = tile.getAttribute('data-staff-name');
    panel.hidden = false;
  }

  function select(tile) {
    show(tile);
    var error = form.querySelector('.login-error');
    if (error) {
      error.remove();
    }
    pin = '';
    render();
  }

  function press(key) {
    if (submitting || !staffInput.value || staffInput.value === '0') {
      return;
    }
    if (key === 'back') {
      pin = pin.slice(0, -1);
    } else if (key === 'clear') {
      pin = '';
    } else if (pin.length < 4) {
      pin += key;
    }
    render();
    if (pin.length === 4) {
      submitting = true;
      pinInput.value = pin;
      form.classList.add('is-busy');
      form.submit();
    }
  }

  tiles.forEach(function (tile) {
    tile.addEventListener('click', function () {
      select(tile);
    });
  });

  form.querySelectorAll('[data-key]').forEach(function (button) {
    button.addEventListener('click', function () {
      press(button.getAttribute('data-key'));
    });
  });

  document.addEventListener('keydown', function (event) {
    if (/^[0-9]$/.test(event.key)) {
      press(event.key);
    } else if (event.key === 'Backspace') {
      press('back');
    } else if (event.key === 'Escape') {
      press('clear');
    }
  });

  // After a wrong PIN the server re-selects the same person; keep the error visible.
  var selected = form.getAttribute('data-selected');
  if (selected && selected !== '0') {
    var tile = document.querySelector('.staff-tile[data-staff-id="' + selected + '"]');
    if (tile) {
      show(tile);
    }
  }
})();
