<?php
$v = fn(string $k, $d = '') => old($k, $s[$k] ?? $d);
echo App\Core\View::partial('partials/pagehead', ['heading' => $title, 'crumbs' => [['/smtp', __('SMTP servers')]]]);
?>
<form method="post" action="<?= e(url($s ? '/smtp/' . $s['id'] : '/smtp')) ?>" class="panel form-grid" autocomplete="off">
  <?= csrf_field() ?>
  <label><?= e(__('Name')) ?><input name="name" value="<?= e($v('name')) ?>" required placeholder="Relay Pensareweb #1"></label>
  <label class="check-inline"><input type="checkbox" name="active" value="1"<?= checked($v('active', 1)) ?>> <?= e(__('Active')) ?></label>
  <label><?= e(__('Host')) ?><input name="host" value="<?= e($v('host')) ?>" required placeholder="smtp.example.com"></label>
  <label><?= e(__('Port')) ?><input type="number" name="port" value="<?= e($v('port', 587)) ?>" min="1" max="65535" required></label>
  <label><?= e(__('Encryption')) ?>
    <select name="encryption">
      <option value="tls"<?= selected($v('encryption', 'tls'), 'tls') ?>>STARTTLS (587)</option>
      <option value="ssl"<?= selected($v('encryption'), 'ssl') ?>>SSL/TLS (465)</option>
      <option value="none"<?= selected($v('encryption'), 'none') ?>><?= e(__('None')) ?> (25)</option>
    </select></label>
  <label class="check-inline"><input type="checkbox" name="verify_peer" value="1"<?= checked($v('verify_peer', 1)) ?>> <?= e(__('Verify TLS certificate')) ?></label>
  <label class="check-inline full"><input type="checkbox" name="auth" value="1"<?= checked($v('auth', 1)) ?>> <?= e(__('SMTP authentication')) ?></label>
  <label><?= e(__('Username')) ?><input name="username" value="<?= e($v('username')) ?>" autocomplete="off"></label>
  <label><?= e(__('Password')) ?>
    <input type="password" name="smtp_password" autocomplete="new-password" placeholder="<?= $s && $s['password_enc'] ? e(__('•••••• saved, leave empty to keep it')) : '' ?>">
    <?php if ($s && $s['password_enc']): ?><span class="check small"><input type="checkbox" name="clear_password" value="1"> <?= e(__('remove saved password')) ?></span><?php endif; ?>
  </label>
  <label><?= e(__('HELO/EHLO hostname')) ?><input name="helo_hostname" value="<?= e($v('helo_hostname')) ?>" placeholder="mta.example.com"></label>
  <label><?= e(__('Return-Path (bounce address)')) ?><input type="email" name="return_path" value="<?= e($v('return_path')) ?>" placeholder="bounce@example.com"></label>
  <label><?= e(__('Messages per minute')) ?> <small class="muted">0 = <?= e(__('unlimited')) ?></small><input type="number" min="0" name="rate_per_minute" value="<?= e($v('rate_per_minute', 0)) ?>"></label>
  <label><?= e(__('Messages per hour')) ?> <small class="muted">0 = <?= e(__('unlimited')) ?></small><input type="number" min="0" name="rate_per_hour" value="<?= e($v('rate_per_hour', 0)) ?>"></label>
  <label class="full"><?= e(__('Allowed sender domains')) ?> <small class="muted"><?= e(__('comma separated, empty = any')) ?></small><input name="allowed_from" value="<?= e($v('allowed_from')) ?>" placeholder="example.com, example.it"></label>
  <label class="full"><?= e(__('Notes')) ?><textarea name="notes" rows="3"><?= e($v('notes')) ?></textarea></label>
  <div class="full"><button class="btn btn-primary" type="submit"><?= e(__('Save')) ?></button></div>
</form>
<?php if ($s): ?>
<section class="panel">
  <div class="inline-forms">
    <form method="post" action="<?= e(url('/smtp/' . $s['id'] . '/test')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= e(__('Test connection')) ?></button></form>
    <a class="btn" href="<?= e(url('/smtp/' . $s['id'] . '/assign')) ?>"><?= e(__('Assign to resellers / clients')) ?></a>
    <form method="post" action="<?= e(url('/smtp/' . $s['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete this SMTP server?')) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= e(__('Delete')) ?></button></form>
  </div>
</section>
<?php endif; ?>
