<?php
$isAdmin = $me['role'] === 'admin';
$actions = '<a class="btn btn-primary" href="' . e(url('/users/create', $role ? ['role' => $role] : [])) . '">' . e($isAdmin ? __('New user') : __('New client')) . '</a>';
echo App\Core\View::partial('partials/pagehead', ['heading' => $title, 'actions' => $actions]);
?>
<form class="filters" method="get">
  <?php if ($isAdmin): ?>
  <div class="tabs">
    <?php foreach (['' => __('All'), 'admin' => __('Administrators'), 'reseller' => __('Resellers'), 'client' => __('Clients')] as $k => $lbl): ?>
      <a href="<?= e(url('/users', $k ? ['role' => $k] : [])) ?>" class="<?= $role === $k ? 'active' : '' ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="role" value="<?= e($role) ?>">
  <?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search name, email, company')) ?>">
  <button class="btn" type="submit"><?= e(__('Search')) ?></button>
</form>
<table class="table">
  <thead><tr>
    <th><?= e(__('Name')) ?></th><th><?= e(__('Email')) ?></th>
    <?php if ($isAdmin): ?><th><?= e(__('Role')) ?></th><th><?= e(__('Reseller')) ?></th><?php endif; ?>
    <th><?= e(__('Status')) ?></th><th><?= e(__('Last login')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><a href="<?= e(url('/users/' . $u['id'] . '/edit')) ?>"><?= e($u['name']) ?></a><?php if ($u['company']): ?><div class="muted small"><?= e($u['company']) ?></div><?php endif; ?></td>
      <td><?= e($u['email']) ?></td>
      <?php if ($isAdmin): ?>
        <td><span class="role role-<?= e($u['role']) ?>"><?= e(role_label($u['role'])) ?></span>
          <?php if ($u['role'] === 'reseller'): ?><a class="small" href="<?= e(url('/users', ['role' => 'client', 'parent' => $u['id']])) ?>"><?= e(__(':n clients', ['n' => $u['clients_count']])) ?></a><?php endif; ?></td>
        <td><?= e($u['parent_name'] ?? ($u['role'] === 'client' ? __('— direct —') : '')) ?></td>
      <?php endif; ?>
      <td><?= status_badge($u['status']) ?></td>
      <td><?= e(fmt_dt($u['last_login_at'])) ?></td>
      <td class="row-actions">
        <?php if ((int) $u['id'] !== (int) $me['id'] && $u['status'] === 'active'): ?>
        <form method="post" action="<?= e(url('/users/' . $u['id'] . '/impersonate')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit" title="<?= e(__('Log in as this user')) ?>"><?= e(__('Log in as')) ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="7" class="muted"><?= e(__('No users found.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pg->links() ?>
