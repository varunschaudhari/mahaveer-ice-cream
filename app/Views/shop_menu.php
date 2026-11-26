<?php
/**
 * Customer menu with Add buttons (takeaway page and table page). Var: $menu (MenuService::publicMenu).
 * @var list<array> $menu
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
  <ul class="shop-list">
    <?php foreach ($category['items'] as $item): $name = lang_field($item, 'name'); ?>
    <li class="shop-item<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <div class="shop-item__name">
        <?= e($name) ?>
        <?php if (!$item['is_available']): ?><span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
      </div>
      <div class="shop-item__variants">
        <?php foreach ($item['variants'] as $variant): ?>
        <button type="button" class="btn btn-secondary add-btn"
                data-variant="<?= (int) $variant['id'] ?>" data-name="<?= e($name) ?>"
                data-label="<?= e(lang_field($variant, 'label')) ?>" data-price="<?= (int) $variant['price_paise'] ?>"
                <?= $item['is_available'] ? '' : 'disabled' ?>>
          <?= e(lang_field($variant, 'label')) ?> <?= e(money($variant['price_paise'])) ?> <span aria-hidden="true">+</span>
        </button>
        <?php endforeach; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>
