<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Custom fields of a list. Every list has the fixed fields email, name,
 * status (0 disabled, 1 enabled, 2 unsubscribed) and track (0/1); any other
 * column found in an imported CSV becomes a custom field of that list.
 */
final class ListFields
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    public const STATUS_UNSUBSCRIBED = 2;

    /** Keys that cannot be used for custom fields (fixed columns and merge tags). */
    public const RESERVED = ['email', 'name', 'status', 'track', 'id', 'first_name', 'last_name', 'full_name',
        'unsubscribe_url', 'webversion_url', 'date', 'year'];

    /** Header aliases of the fixed columns (after normalisation). */
    public const FIXED_ALIASES = [
        'email' => ['email', 'e_mail', 'mail', 'email_address', 'indirizzo_email', 'indirizzo'],
        'name' => ['name', 'nome', 'nominativo', 'full_name', 'fullname', 'ragione_sociale'],
        'status' => ['status', 'stato', 'state', 'enabled', 'attivo'],
        'track' => ['track', 'tracking', 'traccia', 'tracciamento'],
    ];

    /** "Città di nascita" -> "citta_di_nascita" */
    public static function normalizeKey(string $label): string
    {
        $k = trim($label, " \t\"'\u{FEFF}");
        if (function_exists('transliterator_transliterate')) {
            $k = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $k);
        } else {
            $k = mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $k));
        }
        $k = preg_replace('/[^a-z0-9_]+/', '_', $k);
        $k = trim((string) $k, '_');
        if ($k !== '' && ctype_digit($k[0])) {
            $k = 'f_' . $k;
        }
        return substr($k, 0, 64);
    }

    /** Which fixed column a header is, or null for a custom field. */
    public static function fixedColumn(string $normalizedKey): ?string
    {
        foreach (self::FIXED_ALIASES as $col => $aliases) {
            if (in_array($normalizedKey, $aliases, true)) {
                return $col;
            }
        }
        return null;
    }

    public static function isReserved(string $key): bool
    {
        return in_array($key, self::RESERVED, true) || self::fixedColumn($key) !== null;
    }

    /** @return array<int, array{id:int, field_key:string, label:string, position:int}> */
    public static function all(int $listId): array
    {
        return DB::all('SELECT * FROM list_fields WHERE list_id = ? ORDER BY position, id', [$listId]);
    }

    /** @return string[] */
    public static function keys(int $listId): array
    {
        return array_column(self::all($listId), 'field_key');
    }

    /**
     * Create the missing fields. $fields = [key => label]. Returns the keys created.
     */
    public static function ensure(int $listId, array $fields): array
    {
        $existing = array_flip(self::keys($listId));
        $pos = (int) DB::value('SELECT COALESCE(MAX(position), 0) FROM list_fields WHERE list_id = ?', [$listId]);
        $created = [];
        foreach ($fields as $key => $label) {
            $key = (string) $key;
            if ($key === '' || isset($existing[$key]) || self::isReserved($key)) {
                continue;
            }
            DB::insert('list_fields', ['list_id' => $listId, 'field_key' => $key,
                'label' => mb_substr(trim((string) $label) ?: $key, 0, 150), 'position' => ++$pos, 'created_at' => now_utc()]);
            $existing[$key] = true;
            $created[] = $key;
        }
        return $created;
    }

    /** Rename a field (label and/or key). Renaming the key moves the values of every subscriber. */
    public static function update(int $listId, int $fieldId, string $newKey, string $label): ?string
    {
        $f = DB::one('SELECT * FROM list_fields WHERE id = ? AND list_id = ?', [$fieldId, $listId]);
        if (!$f) {
            return __('Field not found.');
        }
        $newKey = self::normalizeKey($newKey);
        if ($newKey === '') {
            return __('Invalid field name.');
        }
        if ($newKey !== $f['field_key']) {
            if (self::isReserved($newKey)) {
                return __('":key" is a reserved name.', ['key' => $newKey]);
            }
            if (DB::value('SELECT 1 FROM list_fields WHERE list_id = ? AND field_key = ?', [$listId, $newKey])) {
                return __('A field named ":key" already exists.', ['key' => $newKey]);
            }
            self::rewriteValues($listId, function (array $data) use ($f, $newKey) {
                if (array_key_exists($f['field_key'], $data)) {
                    $data[$newKey] = $data[$f['field_key']];
                    unset($data[$f['field_key']]);
                }
                return $data;
            });
        }
        DB::update('list_fields', ['field_key' => $newKey, 'label' => mb_substr(trim($label) ?: $newKey, 0, 150)], 'id = ?', [$fieldId]);
        return null;
    }

    /** Delete a field and its values. */
    public static function delete(int $listId, int $fieldId): void
    {
        $f = DB::one('SELECT * FROM list_fields WHERE id = ? AND list_id = ?', [$fieldId, $listId]);
        if (!$f) {
            return;
        }
        self::rewriteValues($listId, function (array $data) use ($f) {
            unset($data[$f['field_key']]);
            return $data;
        });
        DB::query('DELETE FROM list_fields WHERE id = ?', [$fieldId]);
    }

    private static function rewriteValues(int $listId, callable $fn): void
    {
        $last = 0;
        while (true) {
            $rows = DB::all('SELECT id, fields FROM subscribers WHERE list_id = ? AND id > ? AND fields IS NOT NULL ORDER BY id LIMIT 1000', [$listId, $last]);
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $data = json_decode((string) $r['fields'], true) ?: [];
                $new = $fn($data);
                if ($new !== $data) {
                    DB::update('subscribers', ['fields' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null], 'id = ?', [$r['id']]);
                }
            }
        }
    }

    /** Parse a status value; missing/empty/invalid = 0 (disabled). */
    public static function parseStatus(?string $v): int
    {
        $v = mb_strtolower(trim((string) $v));
        return match ($v) {
            '1', 'enabled', 'active', 'abilitato', 'attivo', 'si', 'sì', 'yes', 'true' => self::STATUS_ENABLED,
            '2', 'unsubscribed', 'disiscritto', 'unsubscribe' => self::STATUS_UNSUBSCRIBED,
            default => self::STATUS_DISABLED,
        };
    }

    /** Parse a track value; missing/empty/invalid = 0. */
    public static function parseTrack(?string $v): int
    {
        $v = mb_strtolower(trim((string) $v));
        return in_array($v, ['1', 'enabled', 'abilitato', 'si', 'sì', 'yes', 'true', 'on'], true) ? 1 : 0;
    }

    public static function statusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_ENABLED => __('Enabled'),
            self::STATUS_UNSUBSCRIBED => __('Unsubscribed'),
            default => __('Disabled'),
        };
    }
}
