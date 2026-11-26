<?php
/**
 * Read-only menu (placeholder for M0; the website menu page reuses it in M8).
 * @var list<array> $menu from MenuService::publicMenu()
 */
?>
<?php if ($menu === []): ?>
<p class="muted"><?= e(t('menu.empty')) ?></p>
<?php else: ?>
<nav class="chip-row" aria-label="<?= e(t('menu.categories')) ?>">
  <?php foreach ($menu as $category): ?>
  <a class="chip" href="#cat-<?= (int) $category['id'] ?>"><?= e(lang_field($category, 'name')) ?></a>
  <?php endforeach; ?>
</nav>

<?php foreach ($menu as $category): ?>
<section class="menu-category" id="cat-<?= (int) $category['id'] ?>">
  <h2 class="menu-category__title"><?= e(lang_field($category, 'name')) ?></h2>
  <ul class="menu-list">
    <?php foreach ($category['items'] as $item): ?>
    <li class="menu-item<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <div class="menu-item__head">
        <span class="menu-item__name"><?= e(lang_field($item, 'name')) ?></span>
        <?php if (!$item['is_available']): ?>
        <span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span>
        <?php endif; ?>
      </div>
      <div class="menu-item__prices">
        <?php foreach ($item['variants'] as $variant): ?>
        <span class="price">
          <span class="price__label"><?= e(lang_field($variant, 'label')) ?></span>
          <span class="price__amount"><?= e(money($variant['price_paise'])) ?></span>
        </span>
        <?php endforeach; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>
