<?php

declare(strict_types=1);

// M0 placeholder: read-only menu. The real home page (hero, best sellers, order buttons) comes in M8.

use App\Services\MenuService;
use App\Settings;

require __DIR__ . '/boot.php';

$menu = (new MenuService())->publicMenu();

view('header', ['title' => lang_field(Settings::all(), 'shop_name') . ' – ' . t('site.page_title')]);
?>
<p class="tagline"><?= e(t('shop.tagline')) ?> · <?= e(t('shop.years')) ?></p>
<h1><?= e(t('menu.title')) ?></h1>
<?php
view('menu_list', ['menu' => $menu]);
view('footer');
