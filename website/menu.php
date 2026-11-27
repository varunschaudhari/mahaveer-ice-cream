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
<section class="page-hero">
  <div>
    <h1><?= e(t('site.nav.menu')) ?></h1>
    <p class="lead"><?= e(t('site.menu_note')) ?></p>
  </div>
  <a class="btn btn-primary btn-lg" href="<?= e($orderUrl . '/') ?>"><?= icon('bag') ?><?= e(t('site.order_takeaway')) ?></a>
</section>
<?php
view('menu_list', ['menu' => $menu]);
view('site_footer');
