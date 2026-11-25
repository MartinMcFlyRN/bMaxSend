<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Paginator;
use App\Services\CampaignQueue;
use App\Services\DomainService;
use App\Services\MessageBuilder;
use App\Services\Sender;
use App\Services\SmtpService;

final class CampaignController extends Controller
{
    private const EDITABLE = ['draft'];

    public function index(): void
    {
        $status = (string) input('status', '');
        $params = [$this->uid()];
        $w = 'c.client_id = ?';
        if (in_array($status, ['draft', 'scheduled', 'sending', 'paused', 'sent', 'cancelled'], true)) {
            $w .= ' AND c.status = ?';
            $params[] = $status;
        }
        $pg = new Paginator((int) DB::value("SELECT COUNT(*) FROM campaigns c WHERE $w", $params), 50);
        $rows = DB::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.opened_at IS NOT NULL) AS opens,
                    (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.clicked_at IS NOT NULL) AS clicks
             FROM campaigns c WHERE $w ORDER BY c.updated_at DESC LIMIT {$pg->perPage} OFFSET {$pg->offset}",
            $params
        );
        $this->view('campaigns/index', ['title' => __('Campaigns'), 'rows' => $rows, 'pg' => $pg, 'status' => $status]);
    }

    public function create(): void
    {
        $me = $this->user();
        $smtps = SmtpService::availableFor($me, true);
        if (!$smtps) {
            flash('error', __('No SMTP server is assigned to your account yet: contact your provider.'));
        }
        $this->form([
            'id' => null, 'name' => '', 'subject' => '', 'preheader' => '', 'from_name' => $me['company'] ?: $me['name'],
            'from_email' => '', 'reply_to' => '', 'html_body' => self::starterTemplate(), 'text_body' => '',
            'smtp_server_id' => $smtps[0]['id'] ?? null, 'domain_id' => null, 'track_opens' => 1, 'track_clicks' => 1,
            'status' => 'draft', 'scheduled_at' => null,
        ], []);
    }

    public function edit(string $id): void
    {
        $c = $this->find($id);
        if (!in_array($c['status'], self::EDITABLE, true) && $c['status'] !== 'scheduled') {
            redirect('/campaigns/' . $c['id'] . '/report');
        }
        $this->form($c, CampaignQueue::listIds((int) $c['id']));
    }

    private function form(array $c, array $selectedLists): void
    {
        $me = $this->user();
        $lists = DB::all("SELECT l.id, l.name, (SELECT COUNT(*) FROM subscribers s WHERE s.list_id = l.id AND s.status = 1) AS n
                          FROM lists l WHERE l.client_id = ? ORDER BY l.name", [$me['id']]);
        $this->view('campaigns/form', [
            'title' => $c['id'] ? __('Edit campaign') : __('New campaign'),
            'c' => $c, 'lists' => $lists, 'selectedLists' => $selectedLists,
            'smtps' => SmtpService::availableFor($me, true),
            'domains' => DomainService::usableBy($me),
            'estimate' => $c['id'] ? CampaignQueue::estimate($c) : null,
            'fieldTags' => DB::all('SELECT f.field_key, f.label, l.name AS list_name FROM list_fields f JOIN lists l ON l.id = f.list_id
                                    WHERE l.client_id = ? ORDER BY l.name, f.position', [$me['id']]),
            'me' => $me,
        ]);
    }

    public function store(): void
    {
        [$data, $lists, $errors] = $this->validated();
        if ($errors) {
            $this->fail($errors, '/campaigns/create');
        }
        $data += ['client_id' => $this->uid(), 'status' => 'draft', 'created_at' => now_utc(), 'updated_at' => now_utc()];
        $id = DB::transaction(function () use ($data, $lists) {
            $id = DB::insert('campaigns', $data);
            $this->syncLists($id, $lists);
            return $id;
        });
        Audit::log('campaign.create', ['id' => $id]);
        flash('success', __('Campaign saved.'));
        redirect('/campaigns/' . $id . '/edit');
    }

    public function update(string $id): void
    {
        $c = $this->find($id);
        if (!in_array($c['status'], self::EDITABLE, true)) {
            $this->fail([__('Only draft campaigns can be modified. Unschedule it first.')], '/campaigns/' . $c['id'] . '/edit');
        }
        [$data, $lists, $errors] = $this->validated();
        if ($errors) {
            $this->fail($errors, '/campaigns/' . $c['id'] . '/edit');
        }
        $data['updated_at'] = now_utc();
        DB::transaction(function () use ($c, $data, $lists) {
            DB::update('campaigns', $data, 'id = ?', [$c['id']]);
            $this->syncLists((int) $c['id'], $lists);
        });
        flash('success', __('Campaign saved.'));
        redirect('/campaigns/' . $c['id'] . '/edit');
    }

    public function preview(string $id): void
    {
        $c = $this->find($id);
        $me = $this->user();
        $builder = new MessageBuilder($c, DomainService::baseUrlForCampaign($c));
        $msg = $builder->build(['email' => $me['email'], 'name' => 'Mario Rossi', 'fields' => Sender::sampleFields($c), 'token' => 'preview'], true);
        header('Content-Security-Policy: sandbox allow-popups allow-popups-to-escape-sandbox; script-src \'none\'');
        if (input('format') === 'text') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Subject: {$msg['subject']}\n\n" . $msg['text'];
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $msg['html'] !== '' ? $msg['html'] : '<pre>' . e($msg['text']) . '</pre>';
    }

    public function testSend(string $id): void
    {
        $c = $this->find($id);
        $to = mb_strtolower((string) input('test_email', ''));
        $back = '/campaigns/' . $c['id'] . '/edit';
        if (!is_valid_email($to)) {
            $this->fail([__('Invalid email address.')], $back);
        }
        $smtp = $c['smtp_server_id'] ? DB::one('SELECT * FROM smtp_servers WHERE id = ?', [$c['smtp_server_id']]) : null;
        if (!$smtp || !SmtpService::canUse($this->user(), (int) $smtp['id'])) {
            $this->fail([__('Select an SMTP server first.')], $back);
        }
        [$ok, $err] = Sender::sendTest($c, $smtp, $to);
        Audit::log('campaign.test', ['id' => (int) $c['id'], 'to' => $to, 'ok' => $ok]);
        $ok ? flash('success', __('Test email sent to :email.', ['email' => $to])) : flash('error', __('Test failed:') . ' ' . $err);
        redirect($back);
    }

    public function schedule(string $id): void
    {
        $c = $this->find($id);
        $me = $this->user();
        $back = '/campaigns/' . $c['id'] . '/edit';
        if ($c['status'] !== 'draft') {
            $this->fail([__('Only draft campaigns can be scheduled.')], $back);
        }
        $errors = $this->readinessErrors($c);
        $when = input('send_now') ? now_utc() : local_to_utc((string) input('scheduled_at', ''));
        if (!$when) {
            $errors[] = __('Invalid date.');
        }
        $estimate = CampaignQueue::estimate($c);
        if ($estimate === 0) {
            $errors[] = __('The selected lists have no active subscribers.');
        }
        if ($me['monthly_limit'] !== null) {
            $left = (int) $me['monthly_limit'] - CampaignQueue::sentThisMonth((int) $me['id']);
            if ($estimate > $left) {
                flash('info', __('Warning: about :n recipients but only :left emails left this month. Sending will pause when the limit is reached.',
                    ['n' => $estimate, 'left' => max(0, $left)]));
            }
        }
        if ($errors) {
            $this->fail($errors, $back);
        }
        DB::update('campaigns', ['status' => 'scheduled', 'scheduled_at' => $when, 'last_error' => null, 'updated_at' => now_utc()], 'id = ?', [$c['id']]);
        Audit::log('campaign.schedule', ['id' => (int) $c['id'], 'at' => $when]);
        flash('success', __('Campaign scheduled for :date.', ['date' => fmt_dt($when)]));
        redirect('/campaigns');
    }

    public function unschedule(string $id): void
    {
        $c = $this->find($id);
        DB::query("UPDATE campaigns SET status = 'draft', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'scheduled'", [$c['id']]);
        flash('success', __('Campaign moved back to draft.'));
        redirect('/campaigns/' . $c['id'] . '/edit');
    }

    public function pause(string $id): void
    {
        $c = $this->find($id);
        DB::query("UPDATE campaigns SET status = 'paused', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'sending'", [$c['id']]);
        Audit::log('campaign.pause', ['id' => (int) $c['id']]);
        flash('success', __('Campaign paused.'));
        redirect('/campaigns/' . $c['id'] . '/report');
    }

    public function resume(string $id): void
    {
        $c = $this->find($id);
        if ($c['status'] === 'paused') {
            $errs = $this->readinessErrors($c);
            if ($errs) {
                $this->fail($errs, '/campaigns/' . $c['id'] . '/report');
            }
        }
        DB::query("UPDATE campaigns SET status = 'sending', last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'paused'", [$c['id']]);
        Audit::log('campaign.resume', ['id' => (int) $c['id']]);
        flash('success', __('Campaign resumed.'));
        redirect('/campaigns/' . $c['id'] . '/report');
    }

    public function cancel(string $id): void
    {
        $c = $this->find($id);
        DB::query("UPDATE campaigns SET status = 'cancelled', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                   WHERE id = ? AND status IN ('scheduled','sending','paused')", [$c['id']]);
        Audit::log('campaign.cancel', ['id' => (int) $c['id']]);
        flash('success', __('Campaign cancelled.'));
        redirect('/campaigns/' . $c['id'] . '/report');
    }

    public function duplicate(string $id): void
    {
        $c = $this->find($id);
        $copy = array_intersect_key($c, array_flip(['client_id', 'subject', 'preheader', 'from_name', 'from_email', 'reply_to',
            'html_body', 'text_body', 'smtp_server_id', 'domain_id', 'track_opens', 'track_clicks']));
        $copy['name'] = mb_substr($c['name'] . ' (' . __('copy') . ')', 0, 190);
        $copy += ['status' => 'draft', 'created_at' => now_utc(), 'updated_at' => now_utc()];
        $newId = DB::transaction(function () use ($copy, $c) {
            $newId = DB::insert('campaigns', $copy);
            $this->syncLists($newId, CampaignQueue::listIds((int) $c['id']));
            return $newId;
        });
        flash('success', __('Campaign duplicated.'));
        redirect('/campaigns/' . $newId . '/edit');
    }

    public function delete(string $id): void
    {
        $c = $this->find($id);
        if (in_array($c['status'], ['scheduled', 'sending', 'paused'], true)) {
            $this->fail([__('Cancel the campaign before deleting it.')], '/campaigns');
        }
        DB::query('DELETE FROM campaigns WHERE id = ?', [$c['id']]);
        Audit::log('campaign.delete', ['id' => (int) $c['id'], 'name' => $c['name']]);
        flash('success', __('Campaign deleted.'));
        redirect('/campaigns');
    }

    public function report(string $id): void
    {
        $c = $this->find($id);
        $cid = (int) $c['id'];
        $s = DB::one(
            "SELECT COUNT(*) total,
                    SUM(status = 'sent') sent, SUM(status = 'failed') failed, SUM(status = 'pending') pending,
                    SUM(opened_at IS NOT NULL) opened, COALESCE(SUM(open_count),0) opens,
                    SUM(clicked_at IS NOT NULL) clicked, COALESCE(SUM(click_count),0) clicks,
                    SUM(unsubscribed_at IS NOT NULL) unsubscribed
             FROM campaign_recipients WHERE campaign_id = ?",
            [$cid]
        ) ?: [];
        $s = array_map('intval', $s);
        $links = DB::all('SELECT l.url, l.clicks,
                                 (SELECT COUNT(DISTINCT e.recipient_id) FROM tracking_events e WHERE e.link_id = l.id) AS unique_clicks
                          FROM campaign_links l WHERE l.campaign_id = ? ORDER BY l.clicks DESC, l.id LIMIT 50', [$cid]);

        $filter = (string) input('filter', '');
        $w = 'campaign_id = ?';
        $params = [$cid];
        $w .= match ($filter) {
            'sent' => " AND status = 'sent'",
            'failed' => " AND status = 'failed'",
            'pending' => " AND status = 'pending'",
            'opened' => ' AND opened_at IS NOT NULL',
            'clicked' => ' AND clicked_at IS NOT NULL',
            'unsubscribed' => ' AND unsubscribed_at IS NOT NULL',
            default => '',
        };
        $q = (string) input('q', '');
        if ($q !== '') {
            $w .= ' AND email LIKE ?';
            $params[] = "%$q%";
        }
        $pg = new Paginator((int) DB::value("SELECT COUNT(*) FROM campaign_recipients WHERE $w", $params), 100);
        $recipients = DB::all("SELECT * FROM campaign_recipients WHERE $w ORDER BY id LIMIT {$pg->perPage} OFFSET {$pg->offset}", $params);

        $smtp = $c['smtp_server_id'] ? DB::one('SELECT name FROM smtp_servers WHERE id = ?', [$c['smtp_server_id']]) : null;
        $domain = $c['domain_id'] ? DB::one('SELECT hostname FROM domains WHERE id = ?', [$c['domain_id']]) : null;
        $this->view('campaigns/report', [
            'title' => __('Report') . ': ' . $c['name'], 'c' => $c, 's' => $s, 'links' => $links,
            'recipients' => $recipients, 'pg' => $pg, 'filter' => $filter, 'q' => $q, 'smtp' => $smtp, 'domain' => $domain,
            'lists' => DB::all('SELECT l.name FROM campaign_lists cl JOIN lists l ON l.id = cl.list_id WHERE cl.campaign_id = ?', [$cid]),
        ]);
    }

    public function exportReport(string $id): void
    {
        $c = $this->find($id);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="campaign-' . $c['id'] . '-report.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['email', 'name', 'status', 'sent_at', 'opened_at', 'opens', 'clicked_at', 'clicks', 'unsubscribed_at', 'error'], ';');
        $st = DB::query('SELECT * FROM campaign_recipients WHERE campaign_id = ? ORDER BY id', [$c['id']]);
        while ($r = $st->fetch()) {
            fputcsv($out, [$r['email'], $r['name'], $r['status'], $r['sent_at'], $r['opened_at'], $r['open_count'],
                $r['clicked_at'], $r['click_count'], $r['unsubscribed_at'], $r['error']], ';');
        }
        fclose($out);
        exit;
    }

    // --- helpers ----------------------------------------------------------------

    private function find(string $id): array
    {
        $c = DB::one('SELECT * FROM campaigns WHERE id = ? AND client_id = ?', [$this->intParam($id), $this->uid()]);
        if (!$c) {
            $this->abort(404);
        }
        return $c;
    }

    private function syncLists(int $campaignId, array $lists): void
    {
        DB::query('DELETE FROM campaign_lists WHERE campaign_id = ?', [$campaignId]);
        foreach ($lists as $l) {
            DB::query('INSERT INTO campaign_lists (campaign_id, list_id) VALUES (?, ?)', [$campaignId, $l]);
        }
    }

    /** @return array{0: array, 1: int[], 2: string[]} */
    private function validated(): array
    {
        $me = $this->user();
        $errors = [];
        $data = [
            'name' => mb_substr((string) input('name', ''), 0, 190),
            'subject' => mb_substr((string) input('subject', ''), 0, 255),
            'preheader' => mb_substr((string) input('preheader', ''), 0, 255) ?: null,
            'from_name' => mb_substr((string) input('from_name', ''), 0, 150),
            'from_email' => mb_strtolower(mb_substr((string) input('from_email', ''), 0, 190)),
            'reply_to' => mb_strtolower(mb_substr((string) input('reply_to', ''), 0, 190)) ?: null,
            'html_body' => (string) ($_POST['html_body'] ?? ''),
            'text_body' => (string) ($_POST['text_body'] ?? '') ?: null,
            'smtp_server_id' => (int) input('smtp_server_id', 0) ?: null,
            'domain_id' => (int) input('domain_id', 0) ?: null,
            'track_opens' => input('track_opens') ? 1 : 0,
            'track_clicks' => input('track_clicks') ? 1 : 0,
        ];
        if ($data['name'] === '') {
            $errors[] = __('Name is required.');
        }
        if ($data['from_email'] !== '' && !is_valid_email($data['from_email'])) {
            $errors[] = __('Invalid sender address.');
        }
        if ($data['reply_to'] && !is_valid_email($data['reply_to'])) {
            $errors[] = __('Invalid reply-to address.');
        }
        if ($data['smtp_server_id'] && !SmtpService::canUse($me, $data['smtp_server_id'])) {
            $errors[] = __('Invalid SMTP server.');
        }
        if ($data['domain_id'] && !DomainService::canUse($me, $data['domain_id'])) {
            $errors[] = __('Invalid domain.');
        }
        $lists = array_values(array_unique(array_map('intval', (array) ($_POST['lists'] ?? []))));
        if ($lists) {
            $own = array_map('intval', DB::column('SELECT id FROM lists WHERE client_id = ? AND id IN (' . DB::in($lists) . ')',
                array_merge([$me['id']], $lists)));
            $lists = array_values(array_intersect($lists, $own));
        }
        return [$data, $lists, $errors];
    }

    /** Checks needed before a campaign can be (re)started. */
    private function readinessErrors(array $c): array
    {
        $me = $this->user();
        $errors = [];
        if (trim((string) $c['subject']) === '') {
            $errors[] = __('The subject is empty.');
        }
        if (trim((string) $c['from_name']) === '' || !is_valid_email((string) $c['from_email'])) {
            $errors[] = __('Sender name and address are required.');
        }
        if (trim((string) $c['html_body']) === '' && trim((string) $c['text_body']) === '') {
            $errors[] = __('The message body is empty.');
        }
        if (!CampaignQueue::listIds((int) $c['id'])) {
            $errors[] = __('Select at least one list.');
        }
        $smtp = $c['smtp_server_id'] ? DB::one('SELECT * FROM smtp_servers WHERE id = ?', [$c['smtp_server_id']]) : null;
        if (!$smtp || !SmtpService::canUse($me, (int) $smtp['id'])) {
            $errors[] = __('Select an SMTP server first.');
        } elseif (!SmtpService::fromAllowed($smtp, (string) $c['from_email'])) {
            $errors[] = __('The sender domain is not allowed on this SMTP server (allowed: :list).', ['list' => $smtp['allowed_from']]);
        }
        if ($c['domain_id'] && !DomainService::canUse($me, (int) $c['domain_id'])) {
            $errors[] = __('The selected domain is not verified.');
        }
        return $errors;
    }

    /** Starter HTML template, in the user's language (Italian or English). */
    public static function starterTemplate(): string
    {
        $it = \App\Core\Lang::locale() === 'it';
        $t = $it
            ? ['Visualizza nel browser', 'Ciao {{name|cliente}},', 'Scrivi qui il contenuto della tua newsletter.', 'Scopri di più',
               'Hai ricevuto questa email perché sei iscritto alla nostra newsletter.', 'Disiscriviti', 'it']
            : ['View in browser', 'Hello {{name|there}},', 'Write the content of your newsletter here.', 'Learn more',
               'You received this email because you subscribed to our newsletter.', 'Unsubscribe', 'en'];
        return <<<HTML
<!DOCTYPE html>
<html lang="{$t[6]}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Newsletter</title>
</head>
<body style="margin:0;padding:0;background:#f2f4f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;">
  <tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#222;">
      <tr><td style="padding:12px 24px;font-size:12px;color:#888;text-align:right;">
        <a href="{{webversion_url}}" style="color:#888;">{$t[0]}</a>
      </td></tr>
      <tr><td style="padding:8px 24px 0;">
        <h1 style="margin:0 0 12px;font-size:24px;">{$t[1]}</h1>
        <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">{$t[2]}</p>
        <p style="margin:0 0 24px;"><a href="https://www.example.com" style="display:inline-block;background:#2f6fed;color:#fff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;">{$t[3]}</a></p>
      </td></tr>
      <tr><td style="padding:16px 24px;border-top:1px solid #eee;font-size:12px;color:#888;line-height:1.5;">
        {$t[4]}<br>
        <a href="{{unsubscribe_url}}" style="color:#888;">{$t[5]}</a>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }
}
