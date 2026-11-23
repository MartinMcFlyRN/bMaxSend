<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Lang;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The delivery engine, driven by bin/worker.php (cron, every minute).
 *
 *  1. scheduled campaigns whose time has come are switched to "sending" and
 *     their recipient queue is built;
 *  2. pending recipients are sent in batches, honouring the SMTP server's
 *     per-minute / per-hour limits and the client's monthly quota.
 */
final class Sender
{
    private int $deadline;
    /** @var array<int, PHPMailer> open connections by smtp id */
    private array $mailers = [];
    private int $maxAttempts;

    public function __construct(int $maxRuntime = 55, private ?\Closure $out = null)
    {
        $this->deadline = time() + $maxRuntime;
        $this->maxAttempts = (int) config('worker.max_attempts', 3);
    }

    private function say(string $msg): void
    {
        if ($this->out) {
            ($this->out)($msg);
        }
    }

    public function run(): void
    {
        $idleLoops = 0;
        while (time() < $this->deadline) {
            $this->activateDue();
            $sent = $this->sendRound();
            if ($sent === 0) {
                // Nothing (or rate limited): wait a little unless there is nothing at all to do.
                $pending = (int) DB::value("SELECT COUNT(*) FROM campaigns WHERE status = 'sending'");
                if ($pending === 0 || ++$idleLoops > 20) {
                    break;
                }
                sleep(3);
            } else {
                $idleLoops = 0;
            }
        }
        $this->closeAll();
    }

    /** Start scheduled campaigns that are due and build missing queues. */
    public function activateDue(): void
    {
        $due = DB::all("SELECT id FROM campaigns WHERE status = 'scheduled' AND scheduled_at <= UTC_TIMESTAMP() ORDER BY scheduled_at");
        foreach ($due as $row) {
            DB::query("UPDATE campaigns SET status = 'sending', started_at = NULL, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'scheduled'", [$row['id']]);
        }
        foreach (DB::all("SELECT * FROM campaigns WHERE status = 'sending' AND started_at IS NULL") as $c) {
            $n = CampaignQueue::prepare($c);
            $this->say("campaign #{$c['id']}: queue built, $n recipients");
            App::log('worker', "campaign #{$c['id']} started, $n recipients");
        }
    }

    /** One pass over all sending campaigns. Returns the number of messages sent. */
    private function sendRound(): int
    {
        $total = 0;
        $campaigns = DB::all("SELECT * FROM campaigns WHERE status = 'sending' AND started_at IS NOT NULL ORDER BY scheduled_at, id");
        foreach ($campaigns as $c) {
            if (time() >= $this->deadline) {
                break;
            }
            $total += $this->sendBatch($c);
        }
        return $total;
    }

    private function pauseWithError(array $c, string $error): void
    {
        DB::update('campaigns', ['status' => 'paused', 'last_error' => mb_substr($error, 0, 500), 'updated_at' => now_utc()], 'id = ?', [$c['id']]);
        $this->say("campaign #{$c['id']} paused: $error");
        App::log('worker', "campaign #{$c['id']} paused: $error");
    }

    private function sendBatch(array $c): int
    {
        $cid = (int) $c['id'];
        $client = DB::one('SELECT * FROM users WHERE id = ?', [$c['client_id']]);
        if (!$client || $client['status'] !== 'active') {
            $this->pauseWithError($c, 'Client account is suspended');
            return 0;
        }
        $smtp = $c['smtp_server_id'] ? DB::one('SELECT * FROM smtp_servers WHERE id = ?', [$c['smtp_server_id']]) : null;
        if (!$smtp || !$smtp['active']) {
            $this->pauseWithError($c, 'SMTP server missing or disabled');
            return 0;
        }
        if (!SmtpService::isAssigned((int) $smtp['id'], (int) $client['id'])) {
            $this->pauseWithError($c, 'SMTP server is no longer assigned to this client');
            return 0;
        }

        $limit = (int) config('worker.batch_size', 200);
        $limit = min($limit, SmtpService::remainingCapacity($smtp));
        if ($client['monthly_limit'] !== null) {
            $left = (int) $client['monthly_limit'] - CampaignQueue::sentThisMonth((int) $client['id']);
            if ($left <= 0) {
                $this->pauseWithError($c, 'Monthly sending limit reached');
                return 0;
            }
            $limit = min($limit, $left);
        }
        if ($limit <= 0) {
            return 0; // rate limited, try later
        }

        $recipients = DB::all(
            "SELECT * FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending' AND attempts < ?
             ORDER BY attempts, id LIMIT $limit",
            [$cid, $this->maxAttempts]
        );
        if (!$recipients) {
            $this->finish($c);
            return 0;
        }

        Lang::setLocale($client['language'] ?: (string) config('default_language', 'en'));
        $builder = new MessageBuilder($c, DomainService::baseUrlForCampaign($c));
        $sent = 0;
        foreach ($recipients as $i => $r) {
            if (time() >= $this->deadline) {
                break;
            }
            if ($i > 0 && $i % 25 === 0) {
                $status = DB::value('SELECT status FROM campaigns WHERE id = ?', [$cid]);
                if ($status !== 'sending') {
                    break; // paused or cancelled meanwhile
                }
            }
            [$ok, $err, $permanent] = $this->deliver($smtp, $c, $builder, $r);
            if ($ok) {
                DB::update('campaign_recipients', ['status' => 'sent', 'sent_at' => now_utc(), 'error' => null,
                    'attempts' => (int) $r['attempts'] + 1], 'id = ?', [$r['id']]);
                $sent++;
            } else {
                $attempts = (int) $r['attempts'] + 1;
                $final = $permanent || $attempts >= $this->maxAttempts;
                DB::update('campaign_recipients', ['status' => $final ? 'failed' : 'pending', 'attempts' => $attempts,
                    'error' => mb_substr($err, 0, 500)], 'id = ?', [$r['id']]);
                if ($permanent) {
                    $this->markBounced($c, $r);
                }
                if (!$permanent && $this->isConnectionError($err)) {
                    $this->close((int) $smtp['id']);
                    break; // let the next round reconnect
                }
            }
        }
        CampaignQueue::refreshCounters($cid);
        $this->say("campaign #$cid: sent $sent");
        return $sent;
    }

    private function finish(array $c): void
    {
        $left = (int) DB::value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending' AND attempts < ?",
            [$c['id'], $this->maxAttempts]);
        if ($left > 0) {
            return;
        }
        // Rows still "pending" but out of attempts are failures.
        DB::query("UPDATE campaign_recipients SET status = 'failed' WHERE campaign_id = ? AND status = 'pending'", [$c['id']]);
        CampaignQueue::refreshCounters((int) $c['id']);
        DB::query("UPDATE campaigns SET status = 'sent', finished_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'sending'", [$c['id']]);
        $this->say("campaign #{$c['id']}: completed");
        App::log('worker', "campaign #{$c['id']} completed");
    }

    private function markBounced(array $c, array $r): void
    {
        if ($r['subscriber_id']) {
            DB::query("UPDATE subscribers SET status = 'bounced', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$r['subscriber_id']]);
        }
        DB::query("INSERT IGNORE INTO suppressions (client_id, email, reason, created_at) VALUES (?, ?, 'bounced', UTC_TIMESTAMP())",
            [$c['client_id'], $r['email']]);
    }

    private function isConnectionError(string $err): bool
    {
        return (bool) preg_match('/connect|timed out|connection|EOF|SMTP Error: Could not/i', $err);
    }

    private function mailer(array $smtp): PHPMailer
    {
        $id = (int) $smtp['id'];
        if (!isset($this->mailers[$id])) {
            $m = SmtpService::mailer($smtp);
            $m->SMTPKeepAlive = true;
            $this->mailers[$id] = $m;
        }
        return $this->mailers[$id];
    }

    private function close(int $smtpId): void
    {
        if (isset($this->mailers[$smtpId])) {
            try {
                $this->mailers[$smtpId]->smtpClose();
            } catch (\Throwable) {
            }
            unset($this->mailers[$smtpId]);
        }
    }

    private function closeAll(): void
    {
        foreach (array_keys($this->mailers) as $id) {
            $this->close($id);
        }
    }

    /** @return array{0:bool,1:string,2:bool} ok, error, permanent failure */
    private function deliver(array $smtp, array $c, MessageBuilder $builder, array $r): array
    {
        $msg = $builder->build($r);
        $m = $this->mailer($smtp);
        try {
            self::compose($m, $c, $r['email'], $msg, (string) $r['token']);
            $m->send();
            return [true, '', false];
        } catch (\Throwable $e) {
            $code = 0;
            $smtpObj = $m->getSMTPInstance();
            if ($smtpObj) {
                $code = (int) ($smtpObj->getError()['smtp_code'] ?? 0);
            }
            $permanent = $code >= 500 && $code < 600 && in_array($code, [550, 551, 553, 554], true)
                && stripos($e->getMessage(), 'recipient') !== false;
            if ($smtpObj && $smtpObj->connected()) {
                try {
                    $smtpObj->reset();
                } catch (\Throwable) {
                }
            }
            return [false, trim(($code ? "[$code] " : '') . $e->getMessage()), $permanent];
        }
    }

    public static function compose(PHPMailer $m, array $c, string $to, array $msg, string $token): void
    {
        $m->clearAllRecipients();
        $m->clearReplyTos();
        $m->clearCustomHeaders();
        $m->clearAttachments();
        $m->setFrom($c['from_email'], (string) $c['from_name'], false);
        if (!empty($c['reply_to'])) {
            $m->addReplyTo($c['reply_to']);
        }
        $m->addAddress($to);
        $m->Subject = $msg['subject'];
        if ($msg['html'] !== '') {
            $m->isHTML(true);
            $m->Body = $msg['html'];
            $m->AltBody = $msg['text'];
        } else {
            $m->isHTML(false);
            $m->Body = $msg['text'];
            $m->AltBody = '';
        }
        $domain = substr(strrchr($c['from_email'], '@') ?: '@bmaxsend.local', 1);
        $m->MessageID = '<' . $token . '.' . bin2hex(random_bytes(4)) . '@' . $domain . '>';
        foreach ($msg['headers'] as $k => $v) {
            $m->addCustomHeader($k, $v);
        }
    }

    /** Send a single test message (no tracking). Returns [ok, error]. */
    public static function sendTest(array $c, array $smtp, string $to): array
    {
        $builder = new MessageBuilder($c, DomainService::baseUrlForCampaign($c));
        $r = ['email' => $to, 'first_name' => 'Mario', 'last_name' => 'Rossi', 'fields' => [], 'token' => 'test-' . random_token(8)];
        $msg = $builder->build($r);
        $msg['subject'] = '[TEST] ' . $msg['subject'];
        $m = SmtpService::mailer($smtp);
        try {
            self::compose($m, $c, $to, $msg, (string) $r['token']);
            $m->send();
            return [true, ''];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
