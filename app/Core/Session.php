<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = str_starts_with((string) config('app_url'), 'https://');
        session_name((string) config('session_name', 'bmaxsend'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();

        // Idle timeout
        $idle = (int) config('session_idle_minutes', 120) * 60;
        $last = $_SESSION['_last'] ?? time();
        if ($idle > 0 && time() - $last > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = time();

        // Flash data lives for one request
        $_SESSION['_flash_now'] = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        $_SESSION['_old_now'] = $_SESSION['_old_next'] ?? [];
        $_SESSION['_old_next'] = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if ($key === '_old') {
            return $_SESSION['_old_now'] ?? $default;
        }
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function flashes(): array
    {
        return $_SESSION['_flash_now'] ?? [];
    }

    /** Keep the submitted form values for the next request (on validation errors). */
    public static function keepOld(array $data): void
    {
        unset($data['password'], $data['password_confirm'], $data['smtp_password'], $data['_csrf']);
        $_SESSION['_old_next'] = $data;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
