<?php
/**
 * Customer menu (takeaway and table pages): sticky category bar, items as cards with one
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
  <ul class="item-grid">
    <?php foreach ($category['items'] as $item): $name = lang_field($item, 'name'); ?>
    <li class="item-card<?= $item['is_available'] ? '' : ' is-sold-out' ?>">
      <div class="item-card__media tone-<?= (int) $item['id'] % 6 ?>" aria-hidden="true">
        <?php if (!empty($item['photo'])): ?>
        <img src="/media.php?f=<?= e(rawurlencode($item['photo'])) ?>" alt="" loading="lazy" width="72" height="72">
        <?php else: ?>
        <span><?= e(mb_substr($name, 0, 1)) ?></span>
        <?php endif; ?>
      </div>
      <div class="item-card__body">
        <h3 class="item-card__name">
          <?= e($name) ?>
          <?php if (!$item['is_available']): ?><span class="badge badge-muted"><?= e(t('menu.sold_out')) ?></span><?php endif; ?>
        </h3>
        <div class="item-card__variants">
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
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>
