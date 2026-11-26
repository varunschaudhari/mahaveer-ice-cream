<?php

declare(strict_types=1);

// Counter Online tab (SPEC §9): takeaway orders in arrival order with Accept / Ready /
// Picked up (payment → bill) / Cancel. The list refreshes from api/poll.php every 5 s.

use App\Auth;

require __DIR__ . '/../boot.php';

$user = Auth::requireStaff();

$texts = [
    'token' => t('online.token'),
    'age' => t('online.age'),
    'just_now' => t('online.just_now'),
    'pickup' => t('online.pickup_slot'),
    'accept' => t('online.accept'),
    'ready' => t('online.ready'),
    'picked_up' => t('online.picked_up'),
    'cancel' => t('online.cancel'),
    'picked_up_done' => t('online.picked_up_done'),
    'cancel_title' => t('online.cancel_title'),
    'cancelled' => t('online.cancelled_done'),
    'empty' => t('online.empty'),
];
$cancelReasons = [t('cancel.reason.customer_called'), t('cancel.reason.out_of_stock'), t('cancel.reason.duplicate')];

view('counter_head', ['title' => t('counter.tab.online')]);
view('counter_frame_start', ['user' => $user, 'active' => 'online']);
?>
<section class="counter-panel" data-online
         data-texts="<?= e(json_encode($texts, JSON_UNESCAPED_UNICODE)) ?>"
         data-cancel-reasons="<?= e(json_encode($cancelReasons, JSON_UNESCAPED_UNICODE)) ?>">
  <p class="muted online-empty" data-online-empty><?= e(t('online.loading')) ?></p>
  <div class="online-grid" data-online-list></div>
</section>
<?php
view('counter_frame_end', ['scripts' => ['js/online.js']]);
