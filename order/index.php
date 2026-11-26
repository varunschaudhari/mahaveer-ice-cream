<?php

declare(strict_types=1);

// M0 placeholder: read-only menu. Takeaway ordering (cart, checkout) replaces this in M3.

use App\Services\MenuService;

require __DIR__ . '/boot.php';

$menu = (new MenuService())->publicMenu();

view('header', ['title' => t('order.page_title')]);
?>
<h1><?= e(t('menu.title')) ?></h1>
<p class="notice"><?= e(t('order.coming_soon')) ?></p>
<?php
view('menu_list', ['menu' => $menu]);
view('footer');
