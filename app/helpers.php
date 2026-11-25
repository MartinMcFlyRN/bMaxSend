<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Session;

/** Read a configuration value using dot notation, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    $value = App::$config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** HTML escape. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Translate a UI string. English text is the key; translations live in lang/<code>.php.
 * Placeholders use the :name syntax: __('Hello :name', ['name' => 'Manuel']).
 */
function __(string $text, array $params = []): string
{
    return Lang::get($text, $params);
}

/** Build an absolute path inside the panel. */
function url(string $path = '', array $query = []): string
{
    $base = rtrim((string) config('base_path', ''), '/');
    $path = '/' . ltrim($path, '/');
    $qs = $query ? ('?' . http_build_query($query)) : '';
    return $base . $path . $qs;
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path, $query)));
    exit;
}

function back(): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = parse_url($ref, PHP_URL_HOST);
    if ($ref !== '' && $host === ($_SERVER['HTTP_HOST'] ?? null)) {
        header('Location: ' . $ref);
        exit;
    }
    redirect('/');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

/** Value of a field from the previous (failed) submission, or the given default. */
function old(string $key, mixed $default = ''): mixed
{
    $old = Session::get('_old', []);
    return $old[$key] ?? $default;
}

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/** Timezone used to display dates to the current user. */
function user_timezone(): string
{
    $u = \App\Core\Auth::user();
    $tz = $u['timezone'] ?? null;
    return $tz ?: (string) config('timezone', 'UTC');
}

/** Format a UTC datetime from the DB in the user's timezone. */
function fmt_dt(?string $utc, string $format = 'd/m/Y H:i'): string
{
    if (!$utc) {
        return '—';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(user_timezone()))->format($format);
}

/** Convert a local datetime string (user timezone) to a UTC DB datetime. */
function local_to_utc(string $local): ?string
{
    try {
        $dt = new DateTimeImmutable($local, new DateTimeZone(user_timezone()));
    } catch (Exception) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function utc_to_local_input(?string $utc): string
{
    return $utc ? fmt_dt($utc, 'Y-m-d\TH:i') : '';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function percent(int|float $part, int|float $total): string
{
    if ($total <= 0) {
        return '0%';
    }
    return number_format($part / $total * 100, 1, ',', '.') . '%';
}

function nf(int|float|null $n): string
{
    return number_format((float) ($n ?? 0), 0, ',', '.');
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => __('Administrator'),
        'reseller' => __('Reseller'),
        'client' => __('Client'),
        default => $role,
    };
}

function status_badge(string $status): string
{
    $labels = [
        'draft' => __('Draft'), 'scheduled' => __('Scheduled'), 'sending' => __('Sending'),
        'paused' => __('Paused'), 'sent' => __('Sent'), 'cancelled' => __('Cancelled'),
        'active' => __('Active'), 'suspended' => __('Suspended'),
        'pending' => __('Pending'), 'verified' => __('Verified'), 'failed' => __('Failed'),
        'subscribed' => __('Subscribed'), 'unsubscribed' => __('Unsubscribed'),
        'bounced' => __('Bounced'), 'complained' => __('Complained'),
    ];
    return '<span class="badge badge-' . e($status) . '">' . e($labels[$status] ?? $status) . '</span>';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $cond): string
{
    return $cond ? ' checked' : '';
}

/** Badge for a subscriber status: 0 disabled, 1 enabled, 2 unsubscribed. */
function sub_status_badge(int|string $status): string
{
    $status = (int) $status;
    $cls = [0 => 'paused', 1 => 'active', 2 => 'unsubscribed'][$status] ?? 'paused';
    return '<span class="badge badge-' . $cls . '" title="' . $status . '">' . e(\App\Services\ListFields::statusLabel($status)) . '</span>';
}
