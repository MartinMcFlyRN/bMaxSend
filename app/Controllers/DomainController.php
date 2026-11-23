<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Services\DomainService;

/**
 * Alias domains for the web version and the links in the emails.
 * Admin: all domains. Reseller: its own and its clients'. Client: its own.
 */
final class DomainController extends Controller
{
    public function index(): void
    {
        $me = $this->user();
        $owners = [];
        if ($me['role'] !== 'client') {
            $ids = Auth::scopeIds($me);
            $owners = DB::all('SELECT id, name, role, company FROM users WHERE id IN (' . DB::in($ids) . ") ORDER BY FIELD(role,'admin','reseller','client'), name", $ids);
        }
        $usable = $me['role'] === 'client' ? DomainService::usableBy($me) : [];
        $this->view('domains/index', [
            'title' => __('Domains'),
            'domains' => DomainService::manageable($me),
            'shared' => array_filter($usable, fn($d) => (int) $d['owner_id'] !== (int) $me['id']),
            'owners' => $owners, 'me' => $me,
            'target' => DomainService::cnameTarget(),
        ]);
    }

    public function store(): void
    {
        $me = $this->user();
        $host = DomainService::normalize((string) input('hostname', ''));
        $owner = (int) $me['id'];
        if ($me['role'] !== 'client' && ctype_digit((string) input('owner_id', ''))) {
            $owner = (int) input('owner_id');
            if (!in_array($owner, Auth::scopeIds($me), true)) {
                $this->abort(403);
            }
        }
        $errors = [];
        if (!DomainService::validHostname($host)) {
            $errors[] = __('Invalid hostname. Use a subdomain such as news.example.com');
        } elseif ($host === \App\Core\App::panelHost()) {
            $errors[] = __('This is the hostname of the panel.');
        } elseif (DB::value('SELECT 1 FROM domains WHERE hostname = ?', [$host])) {
            $errors[] = __('This domain is already registered.');
        }
        if ($errors) {
            $this->fail($errors, '/domains');
        }
        $id = DB::insert('domains', [
            'owner_id' => $owner,
            'hostname' => $host,
            'shared' => $me['role'] !== 'client' && input('shared') ? 1 : 0,
            'use_https' => input('use_https', '1') ? 1 : 0,
            'verify_token' => random_token(),
            'status' => 'pending',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        Audit::log('domain.create', ['id' => $id, 'hostname' => $host]);
        flash('success', __('Domain added. Configure the DNS records shown below, then click Verify.'));
        redirect('/domains/' . $id);
    }

    public function show(string $id): void
    {
        $d = $this->find($id);
        $owner = DB::one('SELECT id, name, role FROM users WHERE id = ?', [$d['owner_id']]);
        $this->view('domains/show', [
            'title' => $d['hostname'], 'd' => $d, 'owner' => $owner, 'me' => $this->user(),
            'target' => DomainService::cnameTarget(), 'txtName' => DomainService::txtName($d),
            'campaigns' => (int) DB::value('SELECT COUNT(*) FROM campaigns WHERE domain_id = ?', [$d['id']]),
        ]);
    }

    public function update(string $id): void
    {
        $d = $this->find($id);
        $me = $this->user();
        $data = ['use_https' => input('use_https') ? 1 : 0, 'updated_at' => now_utc()];
        if ($me['role'] !== 'client') {
            $data['shared'] = input('shared') ? 1 : 0;
        }
        DB::update('domains', $data, 'id = ?', [$d['id']]);
        flash('success', __('Domain updated.'));
        redirect('/domains/' . $d['id']);
    }

    public function verify(string $id): void
    {
        $d = $this->find($id);
        [$ok, $msg] = DomainService::runCheck($d);
        Audit::log('domain.verify', ['id' => (int) $d['id'], 'ok' => $ok]);
        $ok ? flash('success', __('Domain verified.')) : flash('error', __('Verification failed:') . ' ' . $msg);
        redirect('/domains/' . $d['id']);
    }

    public function forceVerify(string $id): void
    {
        $d = $this->find($id);
        DB::update('domains', ['status' => 'verified', 'verified_at' => now_utc(), 'last_check_msg' => 'Forced by administrator',
            'updated_at' => now_utc()], 'id = ?', [$d['id']]);
        Audit::log('domain.force_verify', ['id' => (int) $d['id']]);
        flash('success', __('Domain marked as verified.'));
        redirect('/domains/' . $d['id']);
    }

    public function delete(string $id): void
    {
        $d = $this->find($id);
        $busy = (int) DB::value("SELECT COUNT(*) FROM campaigns WHERE domain_id = ? AND status IN ('scheduled','sending','paused')", [$d['id']]);
        if ($busy > 0) {
            flash('error', __('The domain is used by :n active campaigns.', ['n' => $busy]));
            redirect('/domains/' . $d['id']);
        }
        DB::query('DELETE FROM domains WHERE id = ?', [$d['id']]);
        Audit::log('domain.delete', ['id' => (int) $d['id'], 'hostname' => $d['hostname']]);
        flash('success', __('Domain deleted. Links in emails already sent on this domain will stop working.'));
        redirect('/domains');
    }

    private function find(string $id): array
    {
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [$this->intParam($id)]);
        if (!$d || !DomainService::canManage($this->user(), $d)) {
            $this->abort(404);
        }
        return $d;
    }
}
