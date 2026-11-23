<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = []): void
    {
        echo View::render($template, $data);
    }

    protected function user(): array
    {
        $u = Auth::user();
        if (!$u) {
            throw new HttpException(403);
        }
        return $u;
    }

    protected function uid(): int
    {
        return (int) $this->user()['id'];
    }

    protected function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }

    /** Store errors + old input and go back to the form. */
    protected function fail(array $errors, string $to): never
    {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        Session::keepOld($_POST);
        redirect($to);
    }

    protected function intParam(string $id): int
    {
        if (!ctype_digit($id)) {
            $this->abort(404);
        }
        return (int) $id;
    }
}
