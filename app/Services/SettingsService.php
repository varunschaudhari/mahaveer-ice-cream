<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;
use App\Settings;
use App\ValidationException;
use InvalidArgumentException;
use PDO;

/** Validates and saves the owner-editable settings (SPEC §14 plus shop details), audit logged. */
final class SettingsService
{
    /** Editable keys and their rules. Order here is not display order (see admin/settings.php). */
    public const FIELDS = [
        'shop_name_mr' => ['type' => 'text', 'max' => 100, 'required' => true],
        'shop_name_en' => ['type' => 'text', 'max' => 100, 'required' => true],
        'shop_address_mr' => ['type' => 'text', 'max' => 300, 'required' => true],
        'shop_address_en' => ['type' => 'text', 'max' => 300, 'required' => true],
        'shop_phone' => ['type' => 'phone'],
        'open_time' => ['type' => 'time'],
        'close_time' => ['type' => 'time'],
        'last_order_mins' => ['type' => 'int', 'min' => 0, 'max' => 180],
        'parcel_charge' => ['type' => 'money', 'max' => 10000],
        'slot_mins' => ['type' => 'int', 'min' => 5, 'max' => 60],
        'slot_capacity' => ['type' => 'int', 'min' => 1, 'max' => 100],
        'min_lead_mins' => ['type' => 'int', 'min' => 0, 'max' => 180],
        'noshow_after_mins' => ['type' => 'int', 'min' => 5, 'max' => 240],
        'noshow_block_count' => ['type' => 'int', 'min' => 1, 'max' => 20],
        'bulk_notice_days' => ['type' => 'int', 'min' => 0, 'max' => 30],
        'table_unsettled_mins' => ['type' => 'int', 'min' => 15, 'max' => 720],
        'bill_footer_mr' => ['type' => 'text', 'max' => 200],
        'bill_footer_en' => ['type' => 'text', 'max' => 200],
        'whatsapp_number' => ['type' => 'mobile'],
        'google_maps_url' => ['type' => 'url'],
    ];

    private readonly PDO $db;
    private readonly AuditService $audit;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Db::pdo();
        $this->audit = new AuditService($this->db);
    }

    /**
     * Validates every field, then saves the ones that changed (one audit entry per key).
     *
     * @return list<string> keys that changed
     */
    public function update(array $input, int $staffId): array
    {
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $key => $rule) {
            $raw = trim((string) ($input[$key] ?? ''));
            $result = $this->normalize($raw, $rule);
            if (isset($result['error'])) {
                $errors[$key] = $result['error'];
            } else {
                $values[$key] = $result['value'];
            }
        }
        $errors += $this->crossFieldErrors($values, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        Settings::clearCache();
        $current = Settings::all();
        $changed = array_keys(array_filter(
            $values,
            static fn (string $value, string $key): bool => ($current[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH
        ));

        Db::transaction(function (PDO $db) use ($changed, $values, $current, $staffId): void {
            $upsert = $db->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?'
            );
            foreach ($changed as $key) {
                $upsert->execute([$key, $values[$key], $values[$key]]);
                $this->audit->log($staffId, 'settings_change', 'setting', $key, [
                    'before' => $current[$key] ?? null,
                    'after' => $values[$key],
                ]);
            }
        });

        Settings::clearCache();
        return $changed;
    }

    /** @return array{value: string}|array{error: string|array} */
    private function normalize(string $raw, array $rule): array
    {
        if ($raw === '') {
            return ($rule['required'] ?? false) || in_array($rule['type'], ['time', 'int', 'money', 'phone'], true)
                ? ['error' => 'settings.error.required']
                : ['value' => ''];
        }

        switch ($rule['type']) {
            case 'text':
                if (mb_strlen($raw) > $rule['max']) {
                    return ['error' => ['settings.error.too_long', ['max' => $rule['max']]]];
                }
                if (preg_match('/[\x00-\x1F\x7F]/u', $raw)) {
                    return ['error' => 'settings.error.invalid'];
                }
                return ['value' => $raw];

            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)
                    ? ['value' => $raw]
                    : ['error' => 'settings.error.time'];

            case 'int':
                if (!preg_match('/^\d{1,6}$/', $raw) || (int) $raw < $rule['min'] || (int) $raw > $rule['max']) {
                    return ['error' => ['settings.error.range', ['min' => $rule['min'], 'max' => $rule['max']]]];
                }
                return ['value' => (string) (int) $raw];

            case 'money':
                try {
                    $paise = parse_rupees($raw);
                } catch (InvalidArgumentException) {
                    return ['error' => 'settings.error.money'];
                }
                if ($paise > $rule['max']) {
                    return ['error' => ['settings.error.money_max', ['max' => money($rule['max'])]]];
                }
                return ['value' => rupees_input($paise)];

            case 'phone':
                $digits = preg_replace('/\D/', '', $raw);
                return preg_match('/^[\d\s+()-]+$/', $raw) && strlen($digits) >= 10 && strlen($digits) <= 13
                    ? ['value' => $raw]
                    : ['error' => 'settings.error.phone'];

            case 'mobile':
                $digits = preg_replace('/\D/', '', $raw);
                if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                    $digits = substr($digits, 2);
                }
                return preg_match('/^[6-9]\d{9}$/', $digits)
                    ? ['value' => $digits]
                    : ['error' => 'settings.error.mobile'];

            case 'url':
                return mb_strlen($raw) <= 500 && str_starts_with($raw, 'https://') && filter_var($raw, FILTER_VALIDATE_URL)
                    ? ['value' => $raw]
                    : ['error' => 'settings.error.url'];
        }

        throw new InvalidArgumentException('Unknown setting type: ' . $rule['type']);
    }

    private function crossFieldErrors(array $values, array $errors): array
    {
        if (isset($errors['open_time']) || isset($errors['close_time'])) {
            return [];
        }
        $open = self::minutes($values['open_time']);
        $close = self::minutes($values['close_time']);
        if ($close <= $open) {
            return ['close_time' => 'settings.error.close_before_open'];
        }
        if (!isset($errors['last_order_mins']) && $close - (int) $values['last_order_mins'] <= $open) {
            return ['last_order_mins' => 'settings.error.last_order'];
        }
        return [];
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }
}
