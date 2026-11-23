<?php
$isAdmin = $me['role'] === 'admin';
$role = $target['role'] ?? old('role', $defaultRole);
$v = fn(string $k, $d = '') => old($k, $target[$k] ?? $d);
echo App\Core\View::partial('partials/pagehead', ['heading' => $title, 'crumbs' => [['/users', $isAdmin ? __('Users') : __('My clients')]]]);
?>
<form method="post" action="<?= e(url($target ? '/users/' . $target['id'] : '/users')) ?>" class="panel form-grid" autocomplete="off">
  <?= csrf_field() ?>
  <?php if (!$target && $isAdmin): ?>
    <label><?= e(__('Role')) ?>
      <select name="role" data-toggle-role>
        <?php foreach (['client', 'reseller', 'admin'] as $r): ?><option value="<?= $r ?>"<?= selected($role, $r) ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
      </select></label>
  <?php else: ?>
    <label><?= e(__('Role')) ?><input value="<?= e(role_label($role)) ?>" disabled><input type="hidden" data-toggle-role value="<?= e($role) ?>"></label>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
    <label data-role-only="client"><?= e(__('Reseller')) ?>
      <select name="parent_id">
        <option value=""><?= e(__('— none, managed by the administrator —')) ?></option>
        <?php foreach ($resellers as $r): ?><option value="<?= (int) $r['id'] ?>"<?= selected($v('parent_id'), $r['id']) ?>><?= e($r['name'] . ($r['company'] ? ' · ' . $r['company'] : '')) ?></option><?php endforeach; ?>
      </select></label>
  <?php else: ?><span></span><?php endif; ?>
  <label><?= e(__('Name')) ?><input name="name" value="<?= e($v('name')) ?>" required></label>
  <label><?= e(__('Company')) ?><input name="company" value="<?= e($v('company')) ?>"></label>
  <label><?= e(__('Email (login)')) ?><input type="email" name="email" value="<?= e($v('email')) ?>" required></label>
  <label><?= e($target ? __('New password (leave empty to keep it)') : __('Password')) ?><input type="password" name="password" minlength="10" <?= $target ? '' : 'required' ?> autocomplete="new-password"></label>
  <label><?= e(__('Status')) ?>
    <select name="status"><option value="active"<?= selected($v('status', 'active'), 'active') ?>><?= e(__('Active')) ?></option><option value="suspended"<?= selected($v('status'), 'suspended') ?>><?= e(__('Suspended')) ?></option></select></label>
  <label><?= e(__('Language')) ?>
    <select name="language"><option value=""><?= e(__('Default')) ?></option>
      <?php foreach ($languages as $code => $name): ?><option value="<?= e($code) ?>"<?= selected($v('language'), $code) ?>><?= e($name) ?></option><?php endforeach; ?>
    </select></label>

  <h3 class="full"><?= e(__('Limits')) ?> <small class="muted"><?= e(__('empty = unlimited')) ?></small></h3>
  <?php if ($isAdmin): ?>
    <label data-role-only="reseller"><?= e(__('Max clients')) ?><input type="number" min="0" name="max_clients" value="<?= e($v('max_clients')) ?>"></label>
  <?php endif; ?>
  <label data-role-only="client"><?= e(__('Max subscribers')) ?><input type="number" min="0" name="max_subscribers" value="<?= e($v('max_subscribers')) ?>"></label>
  <label data-role-only="client"><?= e(__('Emails per month')) ?><input type="number" min="0" name="monthly_limit" value="<?= e($v('monthly_limit')) ?>"></label>
  <label class="full"><?= e(__('Notes')) ?><textarea name="notes" rows="3"><?= e($v('notes')) ?></textarea></label>
  <div class="full"><button class="btn btn-primary" type="submit"><?= e(__('Save')) ?></button></div>
</form>

<?php if ($target): ?>
<section class="panel danger-zone">
  <h2><?= e(__('Actions')) ?></h2>
  <div class="inline-forms">
    <?php if ($target['status'] === 'active'): ?>
    <form method="post" action="<?= e(url('/users/' . $target['id'] . '/impersonate')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= e(__('Log in as this user')) ?></button></form>
    <?php endif; ?>
    <?php if (in_array($target['role'], ['reseller', 'client'], true)): ?>
      <a class="btn" href="<?= e(url('/smtp')) ?>"><?= e(__('Manage SMTP assignments')) ?></a>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/users/' . $target['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete this user and all of its data?')) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= e(__('Delete')) ?></button></form>
  </div>
  <p class="muted small"><?= e(__('Created')) ?>: <?= e(fmt_dt($target['created_at'])) ?> · <?= e(__('Last login')) ?>: <?= e(fmt_dt($target['last_login_at'])) ?></p>
</section>
<?php endif; ?>
