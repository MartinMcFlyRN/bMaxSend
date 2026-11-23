<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Lang;

final class ProfileController extends Controller
{
    public function edit(): void
    {
        $this->view('profile/edit', [
            'title' => __('My profile'),
            'u' => $this->user(),
            'languages' => Lang::available(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(): void
    {
        $u = $this->user();
        $data = [
            'name' => mb_substr((string) input('name'), 0, 150),
            'company' => mb_substr((string) input('company'), 0, 190) ?: null,
            'language' => array_key_exists((string) input('language'), Lang::available()) ? (string) input('language') : null,
            'timezone' => in_array(input('timezone'), \DateTimeZone::listIdentifiers(), true) ? (string) input('timezone') : null,
            'updated_at' => now_utc(),
        ];
        $errors = [];
        if ($data['name'] === '') {
            $errors[] = __('Name is required.');
        }
        $new = (string) ($_POST['password'] ?? '');
        if ($new !== '') {
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $u['password_hash'])) {
                $errors[] = __('Current password is wrong.');
            } elseif (mb_strlen($new) < 10) {
                $errors[] = __('The password must be at least 10 characters long.');
            } elseif ($new !== ($_POST['password_confirm'] ?? '')) {
                $errors[] = __('Passwords do not match.');
            } else {
                $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            }
        }
        if ($errors) {
            $this->fail($errors, '/profile');
        }
        DB::update('users', $data, 'id = ?', [$u['id']]);
        Audit::log('profile.update', isset($data['password_hash']) ? 'password changed' : null);
        flash('success', __('Profile updated.'));
        redirect('/profile');
    }
}
