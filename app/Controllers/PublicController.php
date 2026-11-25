<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Lang;
use App\Core\View;
use App\Services\DomainService;
use App\Services\MessageBuilder;

/**
 * Endpoints reached from inside the emails. They are served both on the panel
 * host and on the verified alias domains, and need no login: the recipient
 * token (128 random bits) is the credential.
 */
final class PublicController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function health(): void
    {
        header('Content-Type: text/plain');
        echo 'ok';
    }

    public function webVersion(string $token): void
    {
        $r = $this->recipient($token);
        if (!$r) {
            $this->page(__('Message not available'), __('This message is not available or the link has expired.'), 404);
            return;
        }
        $c = DB::one('SELECT * FROM campaigns WHERE id = ?', [$r['campaign_id']]);
        $this->event($r, 'webview');
        $this->localeFor($c);
        $msg = (new MessageBuilder($c, $this->baseUrl($c)))->build($r, true);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: script-src 'none'; object-src 'none'; base-uri 'none'");
        echo $msg['html'] !== '' ? $msg['html'] : '<pre style="white-space:pre-wrap;font-family:sans-serif">' . e($msg['text']) . '</pre>';
    }

    public function open(string $token): void
    {
        $token = preg_replace('/\.gif$/', '', $token);
        $r = $this->recipient((string) $token);
        if ($r) {
            DB::query('UPDATE campaign_recipients SET open_count = open_count + 1, opened_at = COALESCE(opened_at, UTC_TIMESTAMP()) WHERE id = ?', [$r['id']]);
            $this->event($r, 'open');
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo base64_decode(self::GIF);
    }

    public function click(string $token, string $link): void
    {
        $r = $this->recipient($token);
        $l = ($r && ctype_digit($link))
            ? DB::one('SELECT * FROM campaign_links WHERE id = ? AND campaign_id = ?', [(int) $link, $r['campaign_id']])
            : null;
        if (!$l) {
            $this->page(__('Link not available'), __('This link is not valid or has expired.'), 404);
            return;
        }
        DB::query('UPDATE campaign_recipients SET click_count = click_count + 1, clicked_at = COALESCE(clicked_at, UTC_TIMESTAMP()),
                   opened_at = COALESCE(opened_at, UTC_TIMESTAMP()) WHERE id = ?', [$r['id']]);
        DB::query('UPDATE campaign_links SET clicks = clicks + 1 WHERE id = ?', [$l['id']]);
        $this->event($r, 'click', (int) $l['id']);
        header('Cache-Control: no-store');
        header('Location: ' . $l['url'], true, 302);
    }

    public function unsubscribeForm(string $token): void
    {
        $r = $this->recipient($token);
        if (!$r) {
            $this->page(__('Unsubscribe'), __('This link is not valid or has expired.'), 404);
            return;
        }
        $c = DB::one('SELECT * FROM campaigns WHERE id = ?', [$r['campaign_id']]);
        $this->localeFor($c);
        echo View::render('public/unsubscribe', [
            'title' => __('Unsubscribe'), 'r' => $r, 'c' => $c, 'done' => $r['unsubscribed_at'] !== null,
            'action' => $this->baseUrl($c) . '/u/' . rawurlencode($r['token']),
        ], 'layouts/public');
    }

    public function unsubscribe(string $token): void
    {
        $r = $this->recipient($token);
        $oneClick = ($_POST['List-Unsubscribe'] ?? '') === 'One-Click';
        if (!$r) {
            if ($oneClick) {
                http_response_code(200);
                return;
            }
            $this->page(__('Unsubscribe'), __('This link is not valid or has expired.'), 404);
            return;
        }
        $c = DB::one('SELECT * FROM campaigns WHERE id = ?', [$r['campaign_id']]);
        $all = !$oneClick && !empty($_POST['all']);

        DB::transaction(function () use ($r, $c, $all) {
            // Unsubscribe the address from the lists the campaign was sent to.
            DB::query(
                "UPDATE subscribers SET status = 2, unsubscribed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE email = ? AND status <> 2 AND list_id IN (SELECT list_id FROM campaign_lists WHERE campaign_id = ?)",
                [$r['email'], $c['id']]
            );
            if ($r['subscriber_id']) {
                DB::query('UPDATE subscribers SET status = 2, unsubscribed_at = COALESCE(unsubscribed_at, UTC_TIMESTAMP())
                           WHERE id = ? AND status <> 2', [$r['subscriber_id']]);
            }
            if ($all) {
                DB::query("INSERT IGNORE INTO suppressions (client_id, email, reason, created_at) VALUES (?, ?, 'unsubscribed', UTC_TIMESTAMP())",
                    [$c['client_id'], $r['email']]);
            }
            if ($r['unsubscribed_at'] === null) {
                DB::query('UPDATE campaign_recipients SET unsubscribed_at = UTC_TIMESTAMP() WHERE id = ?', [$r['id']]);
                $this->event($r, 'unsubscribe');
            }
        });

        if ($oneClick) {
            http_response_code(200);
            header('Content-Type: text/plain');
            echo 'OK';
            return;
        }
        $this->localeFor($c);
        $this->page(__('Unsubscribed'), __('The address :email has been removed. You will not receive these emails anymore.', ['email' => $r['email']]));
    }

    // -------------------------------------------------------------------------

    private function recipient(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return DB::one('SELECT * FROM campaign_recipients WHERE token = ?', [$token]);
    }

    private function event(array $r, string $type, ?int $linkId = null): void
    {
        DB::insert('tracking_events', [
            'campaign_id' => $r['campaign_id'], 'recipient_id' => $r['id'], 'type' => $type, 'link_id' => $linkId,
            'ip' => config('tracking.store_ip', true) ? client_ip() : null,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            'created_at' => now_utc(),
        ]);
    }

    private function baseUrl(array $c): string
    {
        // Keep the visitor on the host they used when it is the campaign's alias domain.
        return DomainService::baseUrlForCampaign($c);
    }

    private function localeFor(?array $c): void
    {
        if ($c) {
            $lang = DB::value('SELECT language FROM users WHERE id = ?', [$c['client_id']]);
            Lang::setLocale((string) ($lang ?: config('default_language', 'en')));
        }
    }

    private function page(string $title, string $message, int $status = 200): void
    {
        http_response_code($status);
        echo View::render('public/message', ['title' => $title, 'message' => $message], 'layouts/public');
    }
}
