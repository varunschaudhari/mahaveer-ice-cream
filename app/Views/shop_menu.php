<?php
/**
 * Customer menu (takeaway and table pages): sticky category bar, items as compact rows with one
 * "Add" pill per size. Var: $menu (MenuService::publicMenu). shop.js reads the .add-btn data-*.
 * @var list<array> $menu
 */
?>
<?php if ($menu === []): ?>
<p class="muted"><?= e(t('menu.empty')) ?></p>
<?php else: ?>
<nav class="cat-nav" aria-label="<?= e(t('menu.categories')) ?>" data-cat-nav>
  <div class="cat-nav__inner">
    <?php foreach ($menu as $category): ?>
    <a class="cat-chip" href="#cat-<?= (int) $category['id'] ?>"><?= e(lang_field($category, 'name')) ?></a>
    <?php endforeach; ?>
  </div>
</nav>

<?php foreach ($menu as $category): ?>
<section class="menu-section" id="cat-<?= (int) $category['id'] ?>">
  <h2 class="menu-section__title">
    <?= e(lang_field($category, 'name')) ?>
    <span class="menu-section__count"><?= count($category['items']) ?></span>
  </h2>
  <ul class="item-list">
    <?php foreach ($category['items'] as $item): $name = lang_field($item, 'name'); ?>
    <li class="item-row<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <div class="item-row__main">
        <?php if (!empty($item['photo'])): ?>
        <img class="item-row__photo" src="/media.php?f=<?= e(rawurlencode($item['photo'])) ?>" alt="" loading="lazy" width="48" height="48">
        <?php endif; ?>
        <h3 class="item-row__name">
          <?= e($name) ?>
          <?php if (!$item['is_available']): ?><span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
        </h3>
      </div>
      <div class="item-row__variants">
          <?php foreach ($item['variants'] as $variant): $label = lang_field($variant, 'label'); ?>
          <button type="button" class="add-btn"
                  data-variant="<?= (int) $variant['id'] ?>" data-name="<?= e($name) ?>"
                  data-label="<?= e($label) ?>" data-price="<?= (int) $variant['price_paise'] ?>"
                  aria-label="<?= e($name . ' ' . $label . ' ' . money($variant['price_paise'])) ?>"
                  <?= $item['is_available'] ? '' : 'disabled' ?>>
            <span class="add-btn__label"><?= e($label) ?></span>
            <span class="add-btn__price"><?= e(money($variant['price_paise'])) ?></span>
            <span class="add-btn__plus" aria-hidden="true">+</span>
            <span class="add-btn__qty" data-qty hidden></span>
          </button>
          <?php endforeach; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>
