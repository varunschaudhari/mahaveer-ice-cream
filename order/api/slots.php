<?php

declare(strict_types=1);

// Today's pickup slots for the checkout (public): [{start, end, label, full}], open flag.

use App\Services\SlotService;

require __DIR__ . '/../boot.php';
session_write_close();

$today = (new SlotService())->today(now());
json_response(['ok' => true, 'data' => [
    'open' => $today['open'],
    'slots' => array_map(static fn (array $slot): array => [
        'start' => $slot['start'],
        'label' => format_time($slot['start']) . ' – ' . format_time($slot['end']),
        'full' => $slot['full'],
    ], $today['slots']),
]]);
