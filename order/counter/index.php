<?php

declare(strict_types=1);

// Counter login: tap your name, then enter your 4-digit PIN (SPEC §2, §9).

use App\Auth;
use App\Csrf;
use App\Settings;

require __DIR__ . '/../boot.php';

if (!is_post() && Auth::user() !== null) {
    redirect('/counter/billing.php');
}

$error = null;
$selectedId = 0;
if (is_post()) {
    Csrf::check();
    $selectedId = (int) ($_POST['staff_id'] ?? 0);
    $result = Auth::loginWithPin($selectedId, (string) ($_POST['pin'] ?? ''));
    if ($result['ok']) {
        redirect('/counter/billing.php');
    }
    $error = match ($result['error']) {
        'auth.wrong_pin' => t('auth.wrong_pin', ['n' => $result['attempts_left']]),
        'auth.locked' => t('auth.locked', ['time' => format_time(substr($result['locked_until'], 11, 5))]),
        'auth.too_many' => t('auth.too_many'),
        default => t('auth.unknown_staff'),
    };
}

$staff = Auth::counterStaff();

view('counter_head', ['title' => t('counter.login_title')]);
?>
<main class="login-screen">
  <section class="login-staff">
    <div class="login-brand">
      <span class="login-brand__mark" aria-hidden="true"><img src="/assets/img/logo-mark.png" width="39" height="44" alt=""></span>
      <span>
        <span class="login-brand__name"><?= e(lang_field(Settings::all(), 'shop_name')) ?></span>
        <span class="login-brand__sub"><?= e(t('counter.title')) ?></span>
      </span>
    </div>
    <h1><?= e(t('counter.who')) ?></h1>
    <?php if ($staff === []): ?>
    <p class="notice"><?= e(t('counter.no_staff')) ?></p>
    <?php endif; ?>
    <div class="staff-grid">
      <?php foreach ($staff as $person): ?>
      <button type="button" class="counter-btn staff-tile<?= (int) $person['id'] === $selectedId ? ' is-selected' : '' ?>"
              data-staff-id="<?= (int) $person['id'] ?>" data-staff-name="<?= e($person['name']) ?>">
        <span class="counter-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($person['name'], 0, 1))) ?></span>
        <span class="staff-tile__name"><?= e($person['name']) ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="login-pin" data-pin-panel<?= $selectedId > 0 ? '' : ' hidden' ?>>
    <form method="post" action="/counter/" id="pin-form" data-selected="<?= $selectedId ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="staff_id" value="<?= $selectedId ?>">
      <input type="hidden" name="pin" value="">
      <p class="login-pin__name" data-pin-name></p>
      <p class="login-pin__hint"><?= e(t('counter.enter_pin')) ?></p>
      <p class="pin-dots" aria-hidden="true"><span></span><span></span><span></span><span></span></p>
      <?php if ($error !== null): ?>
      <p class="login-error" role="alert"><?= e($error) ?></p>
      <?php endif; ?>
      <div class="pin-pad">
        <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit): ?>
        <button type="button" class="counter-btn" data-key="<?= $digit ?>"><?= $digit ?></button>
        <?php endforeach; ?>
        <button type="button" class="counter-btn" data-key="clear" aria-label="<?= e(t('counter.clear')) ?>">C</button>
        <button type="button" class="counter-btn" data-key="0">0</button>
        <button type="button" class="counter-btn" data-key="back" aria-label="<?= e(t('counter.backspace')) ?>">⌫</button>
      </div>
    </form>
  </section>
  <section class="login-placeholder" aria-hidden="true">
    <span class="login-placeholder__icon"><?= icon('lock', 40) ?></span>
    <p><?= e(t('counter.who')) ?></p>
  </section>
</main>
<?php
view('counter_foot', ['scripts' => ['js/pinpad.js']]);
