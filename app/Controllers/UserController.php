<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Paginator;

/**
 * Admin: manages admins, resellers and clients.
 * Reseller: manages its own clients only.
 */
final class UserController extends Controller
{
    public function index(): void
    {
        $me = $this->user();
        $where = ['1=1'];
        $params = [];
        $role = (string) input('role', '');
        if ($me['role'] === 'reseller') {
            $where[] = "u.role = 'client' AND u.parent_id = ?";
            $params[] = $me['id'];
        } elseif (in_array($role, ['admin', 'reseller', 'client'], true)) {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }
        if ($me['role'] === 'admin' && ctype_digit((string) input('parent', ''))) {
            $where[] = 'u.parent_id = ?';
            $params[] = (int) input('parent');
        }
        $q = (string) input('q', '');
        if ($q !== '') {
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.company LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        $w = implode(' AND ', $where);
        $pg = new Paginator((int) DB::value("SELECT COUNT(*) FROM users u WHERE $w", $params), 50);
        $users = DB::all(
            "SELECT u.*, p.name AS parent_name,
                    (SELECT COUNT(*) FROM users c WHERE c.parent_id = u.id AND c.role = 'client') AS clients_count
             FROM users u LEFT JOIN users p ON p.id = u.parent_id
             WHERE $w ORDER BY FIELD(u.role,'admin','reseller','client'), u.name
             LIMIT {$pg->perPage} OFFSET {$pg->offset}",
            $params
        );
        $this->view('users/index', [
            'title' => $me['role'] === 'reseller' ? __('My clients') : __('Users'),
            'users' => $users, 'pg' => $pg, 'role' => $role, 'q' => $q, 'me' => $me,
        ]);
    }

    public function create(): void
    {
        $me = $this->user();
        if ($me['role'] === 'reseller' && $me['max_clients'] !== null) {
            $n = (int) DB::value("SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'client'", [$me['id']]);
            if ($n >= (int) $me['max_clients']) {
                flash('error', __('You have reached the maximum number of clients (:n).', ['n' => $me['max_clients']]));
                redirect('/users');
            }
        }
        $this->form(null);
    }

    public function edit(string $id): void
    {
        $this->form($this->findManageable($this->intParam($id)));
    }

    private function form(?array $target): void
    {
        $me = $this->user();
        $this->view('users/form', [
            'title' => $target ? __('Edit user') : __('New user'),
            'target' => $target,
            'me' => $me,
            'resellers' => $me['role'] === 'admin' ? DB::all("SELECT id, name, company FROM users WHERE role = 'reseller' ORDER BY name") : [],
            'languages' => Lang::available(),
            'defaultRole' => (string) input('role', $me['role'] === 'reseller' ? 'client' : 'client'),
        ]);
    }

    public function store(): void
    {
        $me = $this->user();
        [$data, $errors] = $this->validated(null);
        if ($me['role'] === 'reseller' && $me['max_clients'] !== null) {
            $n = (int) DB::value("SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'client'", [$me['id']]);
            if ($n >= (int) $me['max_clients']) {
                $errors[] = __('You have reached the maximum number of clients (:n).', ['n' => $me['max_clients']]);
            }
        }
        if ($errors) {
            $this->fail($errors, '/users/create');
        }
        $data['created_at'] = $data['updated_at'] = now_utc();
        $id = DB::insert('users', $data);
        Audit::log('user.create', ['id' => $id, 'role' => $data['role'], 'email' => $data['email']]);
        flash('success', __('User created.'));
        redirect('/users/' . $id . '/edit');
    }

    public function update(string $id): void
    {
        $target = $this->findManageable($this->intParam($id));
        [$data, $errors] = $this->validated($target);
        if ($errors) {
            $this->fail($errors, '/users/' . $target['id'] . '/edit');
        }
        $data['updated_at'] = now_utc();
        DB::transaction(function () use ($target, $data) {
            DB::update('users', $data, 'id = ?', [$target['id']]);
            // A client moved to another reseller keeps only the SMTP servers the new reseller owns.
            if ($target['role'] === 'client' && array_key_exists('parent_id', $data) && (int) $data['parent_id'] !== (int) $target['parent_id'] && $data['parent_id']) {
                DB::query('DELETE a FROM smtp_assignments a
                           LEFT JOIN smtp_assignments p ON p.smtp_server_id = a.smtp_server_id AND p.user_id = ?
                           WHERE a.user_id = ? AND p.user_id IS NULL',
                    [$data['parent_id'], $target['id']]);
            }
        });
        Audit::log('user.update', ['id' => (int) $target['id']]);
        flash('success', __('User updated.'));
        redirect('/users/' . $target['id'] . '/edit');
    }

    public function delete(string $id): void
    {
        $target = $this->findManageable($this->intParam($id));
        if ($target['role'] === 'reseller') {
            $n = (int) DB::value("SELECT COUNT(*) FROM users WHERE parent_id = ? AND role = 'client'", [$target['id']]);
            if ($n > 0) {
                flash('error', __('This reseller still has :n clients: move or delete them first.', ['n' => $n]));
                redirect('/users/' . $target['id'] . '/edit');
            }
        }
        if ($target['role'] === 'admin' && (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin'") <= 1) {
            flash('error', __('You cannot delete the last administrator.'));
            redirect('/users');
        }
        $sending = (int) DB::value("SELECT COUNT(*) FROM campaigns WHERE client_id = ? AND status IN ('sending','scheduled')", [$target['id']]);
        if ($sending > 0) {
            flash('error', __('The user has scheduled or running campaigns: cancel them first.'));
            redirect('/users/' . $target['id'] . '/edit');
        }
        DB::query('DELETE FROM users WHERE id = ?', [$target['id']]);
        Audit::log('user.delete', ['id' => (int) $target['id'], 'email' => $target['email']]);
        flash('success', __('User deleted.'));
        redirect('/users');
    }

    public function impersonate(string $id): void
    {
        $target = $this->findManageable($this->intParam($id));
        if ($target['status'] !== 'active') {
            flash('error', __('The account is suspended.'));
            redirect('/users');
        }
        Auth::impersonate($target);
        flash('info', __('You are now working as :name.', ['name' => $target['name']]));
        redirect('/');
    }

    private function findManageable(int $id): array
    {
        $target = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$target || !Auth::canManage($this->user(), $target)) {
            $this->abort(404);
        }
        return $target;
    }

    /** @return array{0: array, 1: string[]} */
    private function validated(?array $target): array
    {
        $me = $this->user();
        $errors = [];
        $email = mb_strtolower((string) input('email', ''));
        $data = [
            'name' => mb_substr((string) input('name', ''), 0, 150),
            'company' => mb_substr((string) input('company', ''), 0, 190) ?: null,
            'email' => $email,
            'status' => input('status') === 'suspended' ? 'suspended' : 'active',
            'language' => array_key_exists((string) input('language'), Lang::available()) ? (string) input('language') : null,
            'notes' => (string) input('notes', '') ?: null,
        ];

        // Role and parent
        if ($target) {
            $role = $target['role'];
        } elseif ($me['role'] === 'admin') {
            $role = in_array(input('role'), ['admin', 'reseller', 'client'], true) ? (string) input('role') : 'client';
        } else {
            $role = 'client';
        }
        if (!$target) {
            $data['role'] = $role;
        }
        if ($role === 'client') {
            if ($me['role'] === 'admin') {
                $parent = (int) input('parent_id', 0);
                if ($parent > 0 && !DB::value("SELECT 1 FROM users WHERE id = ? AND role = 'reseller'", [$parent])) {
                    $errors[] = __('Invalid reseller.');
                }
                $data['parent_id'] = $parent > 0 ? $parent : null;
            } elseif (!$target) {
                $data['parent_id'] = (int) $me['id'];
            }
            $data['max_subscribers'] = $this->nullableInt('max_subscribers');
            $data['monthly_limit'] = $this->nullableInt('monthly_limit');
        } elseif (!$target) {
            $data['parent_id'] = null;
        }
        if ($role === 'reseller' && $me['role'] === 'admin') {
            $data['max_clients'] = $this->nullableInt('max_clients');
        }

        if ($data['name'] === '') {
            $errors[] = __('Name is required.');
        }
        if (!is_valid_email($email)) {
            $errors[] = __('Invalid email address.');
        } elseif (DB::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $target['id'] ?? 0])) {
            $errors[] = __('This email address is already in use.');
        }

        $pw = (string) ($_POST['password'] ?? '');
        if (!$target || $pw !== '') {
            if (mb_strlen($pw) < 10) {
                $errors[] = __('The password must be at least 10 characters long.');
            } else {
                $data['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            }
        }
        return [$data, $errors];
    }

    private function nullableInt(string $key): ?int
    {
        $v = trim((string) input($key, ''));
        return $v === '' ? null : max(0, (int) $v);
    }
}
