<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        echo View::render('auth/login', ['title' => __('Sign in')], 'layouts/public');
    }

    public function login(): void
    {
        $email = (string) input('email', '');
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || $password === '' || !Auth::attempt($email, $password)) {
            flash('error', __('Invalid email or password.'));
            Session::keepOld(['email' => $email]);
            redirect('/login');
        }
        $to = (string) Session::get('intended', '/');
        Session::forget('intended');
        redirect(str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }

    public function stopImpersonating(): void
    {
        Auth::stopImpersonating();
        flash('success', __('You are back to your account.'));
        redirect('/users');
    }
}
