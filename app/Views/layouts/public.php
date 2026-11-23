<?php use App\Core\Lang; use App\Core\Session; ?><!DOCTYPE html>
<html lang="<?= e(Lang::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? '') ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="public">
<div class="public-box">
  <?php if (session_status() === PHP_SESSION_ACTIVE): foreach (Session::flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; endif; ?>
  <?= $content ?>
</div>
</body>
</html>
