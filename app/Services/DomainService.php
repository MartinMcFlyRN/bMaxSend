<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Alias domains: a customer points e.g. news.customer.tld (CNAME) to the
 * bMaxSend tracking host. Links, the web version and the unsubscribe page in
 * the emails are then generated on that hostname.
 */
final class DomainService
{
    public static function isActiveAlias(string $host): bool
    {
        return (bool) DB::value("SELECT 1 FROM domains WHERE hostname = ? AND status = 'verified'", [$host]);
    }

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host)[0];
        $host = rtrim($host, '.');
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }
        return $host;
    }

    public static function validHostname(string $host): bool
    {
        return (bool) preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host);
    }

    /** Domains that $user can manage (owner is $user or one of its descendants). */
    public static function manageable(array $user): array
    {
        if ($user['role'] === 'admin') {
            return DB::all('SELECT d.*, u.name AS owner_name, u.role AS owner_role FROM domains d JOIN users u ON u.id = d.owner_id ORDER BY d.hostname');
        }
        $ids = \App\Core\Auth::scopeIds($user);
        return DB::all('SELECT d.*, u.name AS owner_name, u.role AS owner_role FROM domains d JOIN users u ON u.id = d.owner_id
                        WHERE d.owner_id IN (' . DB::in($ids) . ') ORDER BY d.hostname', $ids);
    }

    public static function canManage(array $user, array $domain): bool
    {
        return in_array((int) $domain['owner_id'], \App\Core\Auth::scopeIds($user), true);
    }

    /**
     * Verified domains a client can use in campaigns: its own, plus domains
     * shared by its reseller, plus domains shared by any admin.
     */
    public static function usableBy(array $client): array
    {
        return DB::all(
            "SELECT d.* FROM domains d JOIN users o ON o.id = d.owner_id
             WHERE d.status = 'verified' AND (
                d.owner_id = ?
                OR (d.shared = 1 AND d.owner_id = ?)
                OR (d.shared = 1 AND o.role = 'admin')
             ) ORDER BY d.hostname",
            [$client['id'], $client['parent_id'] ?? 0]
        );
    }

    public static function canUse(array $client, int $domainId): bool
    {
        foreach (self::usableBy($client) as $d) {
            if ((int) $d['id'] === $domainId) {
                return true;
            }
        }
        return false;
    }

    public static function cnameTarget(): string
    {
        return strtolower((string) (config('tracking.cname_target') ?: parse_url((string) config('app_url'), PHP_URL_HOST)));
    }

    public static function txtName(array $domain): string
    {
        return config('tracking.txt_prefix', '_bmaxsend') . '.' . $domain['hostname'];
    }

    /**
     * DNS check: the hostname must point to the tracking host (CNAME, or A/AAAA
     * records equal to the target's) and the TXT record must contain the token.
     * Returns [bool ok, string message].
     */
    public static function check(array $domain): array
    {
        $host = $domain['hostname'];
        $target = self::cnameTarget();
        $pointsOk = false;
        $notes = [];

        $cname = @dns_get_record($host, DNS_CNAME) ?: [];
        foreach ($cname as $r) {
            if (rtrim(strtolower($r['target'] ?? ''), '.') === $target) {
                $pointsOk = true;
            }
        }
        if (!$pointsOk) {
            $targetIps = self::resolveIps($target);
            $hostIps = self::resolveIps($host);
            if ($targetIps && $hostIps && !array_diff($hostIps, $targetIps)) {
                $pointsOk = true;
            } else {
                $notes[] = sprintf('%s does not point to %s (CNAME/A)', $host, $target);
            }
        }

        $txtOk = false;
        if (config('tracking.require_txt', true)) {
            foreach (@dns_get_record(self::txtName($domain), DNS_TXT) ?: [] as $r) {
                $txt = $r['txt'] ?? implode('', $r['entries'] ?? []);
                if (trim($txt) === 'bmaxsend-verify=' . $domain['verify_token']) {
                    $txtOk = true;
                }
            }
            if (!$txtOk) {
                $notes[] = sprintf('TXT %s not found or wrong', self::txtName($domain));
            }
        } else {
            $txtOk = true;
        }

        $ok = $pointsOk && $txtOk;
        return [$ok, $ok ? 'DNS OK' : implode('; ', $notes)];
    }

    private static function resolveIps(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        $ips = array_values(array_unique(array_filter($ips)));
        sort($ips);
        return $ips;
    }

    public static function runCheck(array $domain): array
    {
        [$ok, $msg] = self::check($domain);
        DB::update('domains', [
            'status' => $ok ? 'verified' : ($domain['status'] === 'verified' ? 'failed' : 'pending'),
            'verified_at' => $ok ? ($domain['verified_at'] ?: now_utc()) : $domain['verified_at'],
            'last_check_at' => now_utc(),
            'last_check_msg' => mb_substr($msg, 0, 500),
            'updated_at' => now_utc(),
        ], 'id = ?', [$domain['id']]);
        return [$ok, $msg];
    }

    /** Base URL used in a campaign's emails: its alias domain if verified, else the panel URL. */
    public static function baseUrlForCampaign(array $campaign): string
    {
        if (!empty($campaign['domain_id'])) {
            $d = DB::one("SELECT * FROM domains WHERE id = ? AND status = 'verified'", [$campaign['domain_id']]);
            if ($d) {
                return ($d['use_https'] ? 'https://' : 'http://') . $d['hostname'] . rtrim((string) config('base_path', ''), '/');
            }
        }
        return rtrim((string) config('app_url'), '/');
    }
}
