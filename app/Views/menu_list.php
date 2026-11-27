<?php
/**
 * Read-only menu for the website menu page: sticky category chips, one card per category,
 * each item with its sizes and prices as pills.
 * @var list<array> $menu from MenuService::publicMenu()
 */
?>
<?php if ($menu === []): ?>
<p class="muted"><?= e(t('menu.empty')) ?></p>
<?php else: ?>
<nav class="menu-chips" aria-label="<?= e(t('menu.categories')) ?>">
  <div class="menu-chips__inner">
    <?php foreach ($menu as $category): ?>
    <a class="chip" href="#cat-<?= (int) $category['id'] ?>"><?= e(lang_field($category, 'name')) ?></a>
    <?php endforeach; ?>
  </div>
</nav>

<?php foreach ($menu as $category): ?>
<section class="menu-category" id="cat-<?= (int) $category['id'] ?>">
  <h2 class="menu-category__title">
    <?= e(lang_field($category, 'name')) ?>
    <span class="menu-category__count"><?= count($category['items']) ?></span>
  </h2>
  <ul class="menu-list">
    <?php foreach ($category['items'] as $item): $name = lang_field($item, 'name'); ?>
    <li class="menu-item<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <span class="menu-item__tile tone-<?= (int) $item['id'] % 6 ?>" aria-hidden="true"><?= e(mb_substr($name, 0, 1)) ?></span>
      <div class="menu-item__head">
        <span class="menu-item__name"><?= e($name) ?></span>
        <?php if (!$item['is_available']): ?>
        <span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span>
        <?php endif; ?>
      </div>
      <ul class="price-pills menu-item__prices">
        <?php foreach ($item['variants'] as $variant): ?>
        <li><span><?= e(lang_field($variant, 'label')) ?></span> <strong><?= e(money($variant['price_paise'])) ?></strong></li>
        <?php endforeach; ?>
      </ul>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>
