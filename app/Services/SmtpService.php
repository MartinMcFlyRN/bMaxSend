<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class SmtpService
{
    /** SMTP servers a user can see / use. */
    public static function availableFor(array $user, bool $onlyActive = false): array
    {
        $act = $onlyActive ? ' AND s.active = 1' : '';
        if ($user['role'] === 'admin') {
            return DB::all("SELECT s.* FROM smtp_servers s WHERE 1=1 $act ORDER BY s.name");
        }
        return DB::all(
            "SELECT s.* FROM smtp_servers s JOIN smtp_assignments a ON a.smtp_server_id = s.id
             WHERE a.user_id = ? $act ORDER BY s.name",
            [$user['id']]
        );
    }

    public static function canUse(array $user, int $smtpId): bool
    {
        foreach (self::availableFor($user, true) as $s) {
            if ((int) $s['id'] === $smtpId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Users to whom $actor can assign the given server.
     * Admin: every reseller and every client. Reseller: its own clients, only
     * if the server has been assigned to the reseller.
     */
    public static function assignableUsers(array $actor, int $smtpId): array
    {
        if ($actor['role'] === 'admin') {
            return DB::all(
                "SELECT u.id, u.role, u.name, u.company, u.email, u.parent_id, p.name AS parent_name
                 FROM users u LEFT JOIN users p ON p.id = u.parent_id
                 WHERE u.role IN ('reseller','client')
                 ORDER BY FIELD(u.role,'reseller','client'), COALESCE(p.name, ''), u.name"
            );
        }
        if ($actor['role'] === 'reseller' && self::isAssigned($smtpId, (int) $actor['id'])) {
            return DB::all(
                "SELECT id, role, name, company, email, parent_id, NULL AS parent_name FROM users
                 WHERE role = 'client' AND parent_id = ? ORDER BY name",
                [$actor['id']]
            );
        }
        return [];
    }

    public static function isAssigned(int $smtpId, int $userId): bool
    {
        return (bool) DB::value('SELECT 1 FROM smtp_assignments WHERE smtp_server_id = ? AND user_id = ?', [$smtpId, $userId]);
    }

    public static function assignedUserIds(int $smtpId): array
    {
        return array_map('intval', DB::column('SELECT user_id FROM smtp_assignments WHERE smtp_server_id = ?', [$smtpId]));
    }

    /**
     * Replace the assignments of $smtpId within the users $actor is allowed to manage.
     * Removing a server from a reseller also removes it from that reseller's clients.
     */
    public static function syncAssignments(array $actor, int $smtpId, array $selectedIds): void
    {
        $candidates = array_map(fn($u) => (int) $u['id'], self::assignableUsers($actor, $smtpId));
        $selected = array_values(array_intersect(array_map('intval', $selectedIds), $candidates));
        $current = array_values(array_intersect(self::assignedUserIds($smtpId), $candidates));

        DB::transaction(function () use ($actor, $smtpId, $selected, $current) {
            foreach (array_diff($selected, $current) as $uid) {
                DB::query('INSERT IGNORE INTO smtp_assignments (smtp_server_id, user_id, assigned_by, created_at) VALUES (?,?,?,?)',
                    [$smtpId, $uid, $actor['id'], now_utc()]);
            }
            foreach (array_diff($current, $selected) as $uid) {
                self::revoke($smtpId, $uid);
            }
        });
    }

    public static function revoke(int $smtpId, int $userId): void
    {
        DB::query('DELETE FROM smtp_assignments WHERE smtp_server_id = ? AND user_id = ?', [$smtpId, $userId]);
        // cascade to the reseller's clients
        DB::query(
            "DELETE a FROM smtp_assignments a JOIN users u ON u.id = a.user_id
             WHERE a.smtp_server_id = ? AND u.parent_id = ? AND u.role = 'client'",
            [$smtpId, $userId]
        );
    }

    /** Is $fromEmail allowed by the server's allowed_from list? */
    public static function fromAllowed(array $smtp, string $fromEmail): bool
    {
        $list = array_filter(array_map(fn($d) => strtolower(trim($d)), explode(',', (string) $smtp['allowed_from'])));
        if (!$list) {
            return true;
        }
        $domain = strtolower(substr(strrchr($fromEmail, '@') ?: '', 1));
        foreach ($list as $d) {
            if ($domain === ltrim($d, '@') || str_ends_with($domain, '.' . ltrim($d, '@'))) {
                return true;
            }
        }
        return false;
    }

    public static function mailer(array $smtp): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->Host = $smtp['host'];
        $m->Port = (int) $smtp['port'];
        $m->Timeout = (int) config('worker.smtp_timeout', 30);
        $m->SMTPAutoTLS = $smtp['encryption'] === 'tls';
        $m->SMTPSecure = match ($smtp['encryption']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $m->SMTPAuth = (bool) $smtp['auth'];
        if ($m->SMTPAuth) {
            $m->Username = (string) $smtp['username'];
            $m->Password = Crypto::decrypt($smtp['password_enc']);
        }
        if (!(int) $smtp['verify_peer']) {
            $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        if (!empty($smtp['helo_hostname'])) {
            $m->Hostname = $smtp['helo_hostname'];
            $m->Helo = $smtp['helo_hostname'];
        }
        if (!empty($smtp['return_path'])) {
            $m->Sender = $smtp['return_path'];
        }
        $m->XMailer = 'bMaxSend';
        return $m;
    }

    /** Open a connection and authenticate. Returns [ok, message]. */
    public static function testConnection(array $smtp): array
    {
        $m = self::mailer($smtp);
        $log = [];
        $m->SMTPDebug = SMTP::DEBUG_CONNECTION;
        $m->Debugoutput = function ($str) use (&$log) {
            $log[] = trim($str);
        };
        try {
            if (!$m->smtpConnect()) {
                return [false, 'Connection failed' . "\n" . implode("\n", array_slice($log, -15))];
            }
            $m->smtpClose();
            return [true, 'OK'];
        } catch (\Throwable $e) {
            return [false, $e->getMessage() . "\n" . implode("\n", array_slice($log, -15))];
        }
    }

    /** Emails sent through $smtpId in the last $minutes minutes (all campaigns). */
    public static function sentSince(int $smtpId, int $minutes): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM campaign_recipients r JOIN campaigns c ON c.id = r.campaign_id
             WHERE c.smtp_server_id = ? AND r.sent_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE",
            [$smtpId, $minutes]
        );
    }

    /** How many messages may still be sent right now through this server (PHP_INT_MAX = unlimited). */
    public static function remainingCapacity(array $smtp): int
    {
        $cap = PHP_INT_MAX;
        if ((int) $smtp['rate_per_minute'] > 0) {
            $cap = min($cap, (int) $smtp['rate_per_minute'] - self::sentSince((int) $smtp['id'], 1));
        }
        if ((int) $smtp['rate_per_hour'] > 0) {
            $cap = min($cap, (int) $smtp['rate_per_hour'] - self::sentSince((int) $smtp['id'], 60));
        }
        return max(0, $cap);
    }
}
