<?php
echo App\Core\View::partial('partials/pagehead', ['heading' => $title, 'crumbs' => [['/smtp', __('SMTP servers')]]]);
$resellers = array_filter($users, fn($u) => $u['role'] === 'reseller');
$clients = array_filter($users, fn($u) => $u['role'] === 'client');
$box = function (array $u) use ($assigned) {
    return '<label class="check"><input type="checkbox" name="users[]" value="' . (int) $u['id'] . '"' . checked(in_array((int) $u['id'], $assigned, true)) . '> '
        . e($u['name']) . ($u['company'] ? ' <span class="muted">· ' . e($u['company']) . '</span>' : '')
        . ' <span class="muted small">' . e($u['email']) . '</span></label>';
};
?>
<form method="post" action="<?= e(url('/smtp/' . $s['id'] . '/assign')) ?>" class="panel">
  <?= csrf_field() ?>
  <?php if ($me['role'] === 'admin'): ?>
    <h2><?= e(__('Resellers')) ?></h2>
    <p class="muted small"><?= e(__('Removing a server from a reseller also removes it from all of its clients.')) ?></p>
    <div class="checklist"><?php foreach ($resellers as $u) echo $box($u); ?><?php if (!$resellers): ?><span class="muted"><?= e(__('No resellers.')) ?></span><?php endif; ?></div>
    <h2><?= e(__('Clients')) ?></h2>
    <p class="muted small"><?= e(__('Admins can assign directly to any client. Clients of a reseller normally receive servers from their reseller.')) ?></p>
    <?php
    $groups = [];
    foreach ($clients as $u) { $groups[$u['parent_name'] ?? __('— direct —')][] = $u; }
    foreach ($groups as $g => $list): ?>
      <h4><?= e($g) ?></h4>
      <div class="checklist"><?php foreach ($list as $u) echo $box($u); ?></div>
    <?php endforeach; ?>
  <?php else: ?>
    <h2><?= e(__('My clients')) ?></h2>
    <div class="checklist"><?php foreach ($clients as $u) echo $box($u); ?><?php if (!$clients): ?><span class="muted"><?= e(__('No clients.')) ?></span><?php endif; ?></div>
  <?php endif; ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(__('Save assignments')) ?></button> <button type="button" class="btn" data-check-all><?= e(__('Select all')) ?></button></div>
</form>
