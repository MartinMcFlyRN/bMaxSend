<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authentication + role hierarchy.
 *
 * Impersonation: an admin can log in as a reseller or client, a reseller as
 * one of its clients. The real user's id is kept in the session so that the
 * audit log records who actually acted, and the user can go back.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int) Session::get('uid', 0);
            if ($id > 0) {
                $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
                if ($u && $u['status'] === 'active') {
                    self::$user = $u;
                } else {
                    Session::forget('uid');
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    /** Id of the person actually logged in (differs from id() while impersonating). */
    public static function realId(): ?int
    {
        $imp = Session::get('impersonator');
        return $imp ? (int) $imp : self::id();
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    public static function isImpersonating(): bool
    {
        return (bool) Session::get('impersonator');
    }

    public static function attempt(string $email, string $password): bool
    {
        $ip = client_ip();
        $max = (int) config('security.max_login_attempts', 10);
        $window = (int) config('security.login_window_minutes', 15);
        $recent = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE',
            [$ip, $window]
        );
        if ($recent >= $max) {
            throw new HttpException(429, __('Too many login attempts. Try again in a few minutes.'));
        }

        $u = DB::one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
        if (!$u || !password_verify($password, $u['password_hash']) || $u['status'] !== 'active') {
            DB::insert('login_attempts', ['ip' => $ip, 'email' => mb_substr($email, 0, 190), 'created_at' => now_utc()]);
            return false;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        }
        DB::query('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        self::login($u);
        return true;
    }

    public static function login(array $u): void
    {
        Session::regenerate();
        Session::set('uid', (int) $u['id']);
        Session::forget('impersonator');
        DB::update('users', ['last_login_at' => now_utc()], 'id = ?', [$u['id']]);
        self::$user = $u;
        self::$loaded = true;
        Audit::log('auth.login');
    }

    public static function logout(): void
    {
        Audit::log('auth.logout');
        Session::destroy();
        self::$user = null;
    }

    public static function impersonate(array $target): void
    {
        $real = self::realId();
        Audit::log('auth.impersonate', ['target' => (int) $target['id']]);
        Session::regenerate();
        if (!Session::get('impersonator')) {
            Session::set('impersonator', $real);
        }
        Session::set('uid', (int) $target['id']);
        self::$loaded = false;
    }

    public static function stopImpersonating(): void
    {
        $real = Session::get('impersonator');
        if ($real) {
            Session::regenerate();
            Session::set('uid', (int) $real);
            Session::forget('impersonator');
            self::$loaded = false;
            Audit::log('auth.impersonate_stop');
        }
    }

    // -----------------------------------------------------------------
    // Hierarchy helpers
    // -----------------------------------------------------------------

    /** Can $actor manage (edit, delete, impersonate) $target? */
    public static function canManage(array $actor, array $target): bool
    {
        if ((int) $actor['id'] === (int) $target['id']) {
            return false;
        }
        if ($actor['role'] === 'admin') {
            return true;
        }
        return $actor['role'] === 'reseller'
            && $target['role'] === 'client'
            && (int) $target['parent_id'] === (int) $actor['id'];
    }

    /** Ids of all users visible to $actor (itself + its descendants). */
    public static function scopeIds(array $actor): array
    {
        return match ($actor['role']) {
            'admin' => array_map('intval', DB::column('SELECT id FROM users')),
            'reseller' => array_merge([(int) $actor['id']],
                array_map('intval', DB::column("SELECT id FROM users WHERE parent_id = ? AND role = 'client'", [$actor['id']]))),
            default => [(int) $actor['id']],
        };
    }
}
