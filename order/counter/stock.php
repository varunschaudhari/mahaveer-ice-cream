<?php

declare(strict_types=1);

// Counter Stock tab: mark items sold out for today. Everything is available again tomorrow.

use App\Auth;
use App\Services\MenuService;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();
$menuService = new MenuService();
$menuService->resetStockIfNewDay();
$menu = $menuService->counterMenu();

view('counter_head', ['title' => t('counter.tab.stock')]);
view('counter_frame_start', ['user' => $user, 'active' => 'stock']);
?>
<section class="counter-panel stack" data-stock
         data-on="<?= e(t('stock.available')) ?>" data-off="<?= e(t('menu.sold_out')) ?>">
  <p class="muted"><?= e(t('stock.help')) ?></p>
  <?php foreach ($menu as $category): ?>
  <h2><?= e(lang_field($category, 'name')) ?></h2>
  <div class="stock-grid">
    <?php foreach ($category['items'] as $item): $on = (bool) $item['is_available']; ?>
    <button type="button" class="counter-btn stock-toggle<?= $on ? ' is-on' : ' is-off' ?>"
            data-item="<?= (int) $item['id'] ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
      <span class="stock-toggle__name"><?= e(lang_field($item, 'name')) ?></span>
      <span class="stock-toggle__state"><?= e($on ? t('stock.available') : t('menu.sold_out')) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php
view('counter_frame_end', ['scripts' => ['js/stock.js']]);
