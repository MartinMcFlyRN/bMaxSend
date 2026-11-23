<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Session;

$me = Auth::user();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$active = fn(string $prefix) => ($prefix === '/' ? $path === url('/') : str_starts_with((string) $path, url($prefix))) ? ' class="active"' : '';
$nav = [['/', __('Dashboard'), 'home']];
if ($me['role'] === 'admin') {
    $nav[] = ['/users', __('Users'), 'users'];
} elseif ($me['role'] === 'reseller') {
    $nav[] = ['/users', __('My clients'), 'users'];
} else {
    $nav[] = ['/campaigns', __('Campaigns'), 'send'];
    $nav[] = ['/lists', __('Lists'), 'list'];
    $nav[] = ['/suppressions', __('Suppression list'), 'block'];
}
$nav[] = ['/smtp', __('SMTP servers'), 'server'];
$nav[] = ['/domains', __('Domains'), 'globe'];
?><!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> · <?= e(config('app_name', 'bMaxSend')) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="<?= e(url('/')) ?>"><span class="logo">b</span><?= e(config('app_name', 'bMaxSend')) ?></a>
    <div class="who">
      <strong><?= e($me['name']) ?></strong>
      <span class="role role-<?= e($me['role']) ?>"><?= e(role_label($me['role'])) ?></span>
    </div>
    <nav>
      <?php foreach ($nav as [$href, $label, $icon]): ?>
        <a href="<?= e(url($href)) ?>"<?= $active($href) ?>><i class="ico ico-<?= e($icon) ?>"></i><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <a href="<?= e(url('/profile')) ?>"<?= $active('/profile') ?>><?= e(__('My profile')) ?></a>
      <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="linklike" type="submit"><?= e(__('Sign out')) ?></button></form>
    </div>
  </aside>
  <main class="main">
    <?php if (Auth::isImpersonating()): ?>
      <div class="impersonation">
        <?= e(__('You are working as :name (:role).', ['name' => $me['name'], 'role' => role_label($me['role'])])) ?>
        <form method="post" action="<?= e(url('/impersonate/stop')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm"><?= e(__('Back to my account')) ?></button></form>
      </div>
    <?php endif; ?>
    <?php foreach (Session::flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>"><?= nl2br(e($f['message'])) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
