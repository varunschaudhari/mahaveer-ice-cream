<?php

declare(strict_types=1);

// Menu (SPEC §13): live from the database, so a price changed in admin shows at once. ₹0 and
// inactive items are hidden (MenuService::publicMenu); sold-out items show a badge.

use App\Services\MenuService;

require __DIR__ . '/boot.php';

$menus = new MenuService();
$menus->resetStockIfNewDay();
$menu = $menus->publicMenu();
$orderUrl = rtrim((string) config('APP_URL_ORDER', ''), '/');

view('site_header', ['page' => 'menu', 'path' => '/menu.php']);
?>
<div class="section-head">
  <h1><?= e(t('site.nav.menu')) ?></h1>
  <a class="btn btn-primary" href="<?= e($orderUrl . '/') ?>"><?= e(t('site.order_takeaway')) ?></a>
</div>
<p class="muted"><?= e(t('site.menu_note')) ?></p>
<?php
view('menu_list', ['menu' => $menu]);
view('site_footer');
