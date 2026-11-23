<?= App\Core\View::partial('partials/pagehead', ['heading' => __('My profile')]) ?>
<form method="post" action="<?= e(url('/profile')) ?>" class="panel form-grid">
  <?= csrf_field() ?>
  <label><?= e(__('Name')) ?><input name="name" value="<?= e(old('name', $u['name'])) ?>" required></label>
  <label><?= e(__('Company')) ?><input name="company" value="<?= e(old('company', $u['company'])) ?>"></label>
  <label><?= e(__('Email')) ?><input value="<?= e($u['email']) ?>" disabled></label>
  <label><?= e(__('Language')) ?>
    <select name="language"><option value=""><?= e(__('Default')) ?></option>
      <?php foreach ($languages as $code => $name): ?><option value="<?= e($code) ?>"<?= selected($u['language'], $code) ?>><?= e($name) ?></option><?php endforeach; ?>
    </select></label>
  <label><?= e(__('Time zone')) ?>
    <select name="timezone"><option value=""><?= e(__('Default')) ?> (<?= e(config('timezone')) ?>)</option>
      <?php foreach ($timezones as $tz): ?><option<?= selected($u['timezone'], $tz) ?>><?= e($tz) ?></option><?php endforeach; ?>
    </select></label>
  <h3 class="full"><?= e(__('Change password')) ?></h3>
  <label><?= e(__('Current password')) ?><input type="password" name="current_password" autocomplete="current-password"></label>
  <span></span>
  <label><?= e(__('New password')) ?><input type="password" name="password" minlength="10" autocomplete="new-password"></label>
  <label><?= e(__('Confirm password')) ?><input type="password" name="password_confirm" minlength="10" autocomplete="new-password"></label>
  <div class="full"><button class="btn btn-primary" type="submit"><?= e(__('Save')) ?></button></div>
</form>
