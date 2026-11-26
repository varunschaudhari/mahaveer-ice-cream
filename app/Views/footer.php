<?php

use App\Settings;

$shop = Settings::all();
$phone = $shop['shop_phone'] ?? '';
?>
</main>
<footer class="site-footer">
  <div class="container stack-sm">
    <p><?= e(lang_field($shop, 'shop_address')) ?></p>
    <p>
      <?= e(t('shop.hours', [
          'open' => format_time($shop['open_time'] ?? '10:00'),
          'close' => format_time($shop['close_time'] ?? '22:30'),
      ])) ?>
    </p>
    <?php if ($phone !== ''): ?>
    <p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></p>
    <?php endif; ?>
  </div>
</footer>
</body>
</html>
