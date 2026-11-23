<div class="brand brand-center"><span class="logo">b</span><?= e(config('app_name', 'bMaxSend')) ?></div>
<form method="post" action="<?= e(url('/login')) ?>" class="stack">
  <?= csrf_field() ?>
  <label><?= e(__('Email')) ?><input type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username"></label>
  <label><?= e(__('Password')) ?><input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary btn-block" type="submit"><?= e(__('Sign in')) ?></button>
</form>
