<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Builds the per-recipient send queue of a campaign from its lists. */
final class CampaignQueue
{
    /** Number of distinct deliverable addresses in the campaign's lists right now. */
    public static function estimate(array $campaign): int
    {
        $lists = self::listIds((int) $campaign['id']);
        if (!$lists) {
            return 0;
        }
        return (int) DB::value(
            'SELECT COUNT(DISTINCT s.email) FROM subscribers s
             WHERE s.list_id IN (' . DB::in($lists) . ") AND s.status = 'subscribed'
               AND NOT EXISTS (SELECT 1 FROM suppressions x WHERE x.client_id = ? AND x.email = s.email)",
            array_merge($lists, [$campaign['client_id']])
        );
    }

    public static function listIds(int $campaignId): array
    {
        return array_map('intval', DB::column('SELECT list_id FROM campaign_lists WHERE campaign_id = ?', [$campaignId]));
    }

    /**
     * Snapshot the recipients. Idempotent: duplicates are ignored thanks to the
     * (campaign_id, email) unique key, so a crashed run can simply be repeated.
     */
    public static function prepare(array $campaign): int
    {
        $cid = (int) $campaign['id'];
        MessageBuilder::registerLinks($cid, (string) $campaign['html_body']);

        $lists = self::listIds($cid);
        $lastId = 0;
        $chunk = 1000;
        while ($lists) {
            $rows = DB::all(
                'SELECT s.id, s.email, s.first_name, s.last_name, s.fields FROM subscribers s
                 WHERE s.id > ? AND s.list_id IN (' . DB::in($lists) . ") AND s.status = 'subscribed'
                   AND NOT EXISTS (SELECT 1 FROM suppressions x WHERE x.client_id = ? AND x.email = s.email)
                 ORDER BY s.id LIMIT $chunk",
                array_merge([$lastId], $lists, [$campaign['client_id']])
            );
            if (!$rows) {
                break;
            }
            $values = [];
            $params = [];
            foreach ($rows as $r) {
                $values[] = '(?,?,?,?,?,?,?)';
                array_push($params, $cid, $r['id'], mb_strtolower($r['email']), $r['first_name'], $r['last_name'], $r['fields'], random_token());
                $lastId = (int) $r['id'];
            }
            DB::query('INSERT IGNORE INTO campaign_recipients (campaign_id, subscriber_id, email, first_name, last_name, fields, token) VALUES '
                . implode(',', $values), $params);
        }

        $total = (int) DB::value('SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ?', [$cid]);
        DB::update('campaigns', ['total_recipients' => $total, 'started_at' => now_utc(), 'updated_at' => now_utc()], 'id = ?', [$cid]);
        return $total;
    }

    public static function refreshCounters(int $campaignId): void
    {
        DB::query(
            "UPDATE campaigns c SET
                sent_count = (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent'),
                failed_count = (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'failed'),
                updated_at = UTC_TIMESTAMP()
             WHERE c.id = ?",
            [$campaignId]
        );
    }

    /** Emails sent by a client in the current calendar month (UTC). */
    public static function sentThisMonth(int $clientId): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM campaign_recipients r JOIN campaigns c ON c.id = r.campaign_id
             WHERE c.client_id = ? AND r.status = 'sent' AND r.sent_at >= ?",
            [$clientId, gmdate('Y-m-01 00:00:00')]
        );
    }
}
