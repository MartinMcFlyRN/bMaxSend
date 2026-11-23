<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\DB;
use App\Services\SmtpService;

/**
 * Admin: creates SMTP servers and assigns them to resellers (or directly to clients).
 * Reseller: sees its servers and assigns them to its clients.
 * Client: sees the servers it can use.
 */
final class SmtpController extends Controller
{
    public function index(): void
    {
        $me = $this->user();
        $servers = SmtpService::availableFor($me);
        $counts = [];
        foreach (DB::all("SELECT a.smtp_server_id, u.role, COUNT(*) n FROM smtp_assignments a JOIN users u ON u.id = a.user_id
                          GROUP BY a.smtp_server_id, u.role") as $r) {
            $counts[(int) $r['smtp_server_id']][$r['role']] = (int) $r['n'];
        }
        if ($me['role'] === 'reseller') {
            $counts = [];
            foreach (DB::all("SELECT a.smtp_server_id, COUNT(*) n FROM smtp_assignments a JOIN users u ON u.id = a.user_id
                              WHERE u.parent_id = ? GROUP BY a.smtp_server_id", [$me['id']]) as $r) {
                $counts[(int) $r['smtp_server_id']]['client'] = (int) $r['n'];
            }
        }
        $this->view('smtp/index', ['title' => __('SMTP servers'), 'servers' => $servers, 'counts' => $counts, 'me' => $me]);
    }

    public function create(): void
    {
        $this->view('smtp/form', ['title' => __('New SMTP server'), 's' => null]);
    }

    public function edit(string $id): void
    {
        $this->view('smtp/form', ['title' => __('Edit SMTP server'), 's' => $this->find($id)]);
    }

    public function store(): void
    {
        [$data, $errors] = $this->validated(null);
        if ($errors) {
            $this->fail($errors, '/smtp/create');
        }
        $data['created_at'] = $data['updated_at'] = now_utc();
        $id = DB::insert('smtp_servers', $data);
        Audit::log('smtp.create', ['id' => $id, 'host' => $data['host']]);
        flash('success', __('SMTP server created. Now assign it to resellers or clients.'));
        redirect('/smtp/' . $id . '/assign');
    }

    public function update(string $id): void
    {
        $s = $this->find($id);
        [$data, $errors] = $this->validated($s);
        if ($errors) {
            $this->fail($errors, '/smtp/' . $s['id'] . '/edit');
        }
        $data['updated_at'] = now_utc();
        DB::update('smtp_servers', $data, 'id = ?', [$s['id']]);
        Audit::log('smtp.update', ['id' => (int) $s['id']]);
        flash('success', __('SMTP server updated.'));
        redirect('/smtp/' . $s['id'] . '/edit');
    }

    public function delete(string $id): void
    {
        $s = $this->find($id);
        $busy = (int) DB::value("SELECT COUNT(*) FROM campaigns WHERE smtp_server_id = ? AND status IN ('scheduled','sending','paused')", [$s['id']]);
        if ($busy > 0) {
            flash('error', __('The server is used by :n active campaigns.', ['n' => $busy]));
            redirect('/smtp');
        }
        DB::query('DELETE FROM smtp_servers WHERE id = ?', [$s['id']]);
        Audit::log('smtp.delete', ['id' => (int) $s['id'], 'host' => $s['host']]);
        flash('success', __('SMTP server deleted.'));
        redirect('/smtp');
    }

    public function test(string $id): void
    {
        $s = $this->find($id);
        [$ok, $msg] = SmtpService::testConnection($s);
        if ($ok) {
            flash('success', __('Connection and authentication successful.'));
        } else {
            flash('error', __('Connection test failed:') . ' ' . $msg);
        }
        redirect('/smtp/' . $s['id'] . '/edit');
    }

    public function assignForm(string $id): void
    {
        $me = $this->user();
        $s = $this->findVisible($id);
        $users = SmtpService::assignableUsers($me, (int) $s['id']);
        if (!$users && $me['role'] === 'reseller') {
            flash('info', __('You have no clients yet.'));
        }
        $this->view('smtp/assign', [
            'title' => __('Assign :name', ['name' => $s['name']]),
            's' => $s, 'users' => $users, 'assigned' => SmtpService::assignedUserIds((int) $s['id']), 'me' => $me,
        ]);
    }

    public function assign(string $id): void
    {
        $me = $this->user();
        $s = $this->findVisible($id);
        $ids = array_map('intval', (array) ($_POST['users'] ?? []));
        SmtpService::syncAssignments($me, (int) $s['id'], $ids);
        Audit::log('smtp.assign', ['id' => (int) $s['id'], 'users' => $ids]);
        flash('success', __('Assignments saved.'));
        redirect('/smtp/' . $s['id'] . '/assign');
    }

    private function find(string $id): array
    {
        $s = DB::one('SELECT * FROM smtp_servers WHERE id = ?', [$this->intParam($id)]);
        if (!$s) {
            $this->abort(404);
        }
        return $s;
    }

    /** Server that the current user can see (admin: all; reseller: assigned to it). */
    private function findVisible(string $id): array
    {
        $s = $this->find($id);
        $me = $this->user();
        if ($me['role'] !== 'admin' && !SmtpService::isAssigned((int) $s['id'], (int) $me['id'])) {
            $this->abort(404);
        }
        return $s;
    }

    private function validated(?array $s): array
    {
        $errors = [];
        $data = [
            'name' => mb_substr((string) input('name', ''), 0, 150),
            'host' => mb_substr((string) input('host', ''), 0, 190),
            'port' => (int) input('port', 587),
            'encryption' => in_array(input('encryption'), ['none', 'tls', 'ssl'], true) ? (string) input('encryption') : 'tls',
            'auth' => input('auth') ? 1 : 0,
            'username' => mb_substr((string) input('username', ''), 0, 190) ?: null,
            'verify_peer' => input('verify_peer') ? 1 : 0,
            'helo_hostname' => mb_substr((string) input('helo_hostname', ''), 0, 190) ?: null,
            'return_path' => mb_substr((string) input('return_path', ''), 0, 190) ?: null,
            'allowed_from' => mb_substr((string) input('allowed_from', ''), 0, 500) ?: null,
            'rate_per_minute' => max(0, (int) input('rate_per_minute', 0)),
            'rate_per_hour' => max(0, (int) input('rate_per_hour', 0)),
            'active' => input('active') ? 1 : 0,
            'notes' => (string) input('notes', '') ?: null,
        ];
        $pw = (string) ($_POST['smtp_password'] ?? '');
        if ($pw !== '') {
            $data['password_enc'] = Crypto::encrypt($pw);
        } elseif (!$s) {
            $data['password_enc'] = null;
        }
        if ($s && input('clear_password')) {
            $data['password_enc'] = null;
        }
        if ($data['name'] === '') {
            $errors[] = __('Name is required.');
        }
        if ($data['host'] === '' || !preg_match('/^[a-z0-9.\-\[\]:]+$/i', $data['host'])) {
            $errors[] = __('Invalid host.');
        }
        if ($data['port'] < 1 || $data['port'] > 65535) {
            $errors[] = __('Invalid port.');
        }
        if ($data['return_path'] && !is_valid_email($data['return_path'])) {
            $errors[] = __('Invalid return path address.');
        }
        return [$data, $errors];
    }
}
