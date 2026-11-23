<?php
$actions = '<a class="btn" href="' . e(url('/lists/' . $list['id'] . '/import')) . '">' . e(__('Import CSV')) . '</a> '
    . '<a class="btn" href="' . e(url('/lists/' . $list['id'] . '/export')) . '">' . e(__('Export CSV')) . '</a>';
echo App\Core\View::partial('partials/pagehead', ['heading' => $list['name'], 'crumbs' => [['/lists', __('Lists')]], 'actions' => $actions]);
?>
<div class="grid-2">
  <form method="post" action="<?= e(url('/lists/' . $list['id'] . '/subscribers')) ?>" class="panel stack">
    <?= csrf_field() ?>
    <h2><?= e(__('Add subscriber')) ?></h2>
    <label><?= e(__('Email')) ?><input type="email" name="email" required></label>
    <div class="row2">
      <label><?= e(__('First name')) ?><input name="first_name"></label>
      <label><?= e(__('Last name')) ?><input name="last_name"></label>
    </div>
    <div><button class="btn btn-primary" type="submit"><?= e(__('Add')) ?></button></div>
  </form>
  <details class="panel">
    <summary><?= e(__('List settings')) ?></summary>
    <form method="post" action="<?= e(url('/lists/' . $list['id'])) ?>" class="stack">
      <?= csrf_field() ?>
      <label><?= e(__('Name')) ?><input name="name" value="<?= e($list['name']) ?>" required></label>
      <label><?= e(__('Description')) ?><input name="description" value="<?= e($list['description']) ?>"></label>
      <div><button class="btn" type="submit"><?= e(__('Save')) ?></button></div>
    </form>
    <form method="post" action="<?= e(url('/lists/' . $list['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete the list and all its subscribers?')) ?>" class="top-gap"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= e(__('Delete list')) ?></button></form>
  </details>
</div>

<form class="filters" method="get">
  <div class="tabs">
    <?php foreach (['' => __('All'), 'subscribed' => __('Subscribed'), 'unsubscribed' => __('Unsubscribed'), 'bounced' => __('Bounced')] as $k => $lbl): ?>
      <a href="?<?= e(http_build_query(['status' => $k, 'q' => $q])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($lbl) ?> <span class="muted"><?= nf($k ? ($counts[$k] ?? 0) : array_sum($counts)) ?></span></a>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search')) ?>">
  <button class="btn" type="submit"><?= e(__('Search')) ?></button>
</form>

<form method="post" action="<?= e(url('/lists/' . $list['id'] . '/subscribers/bulk')) ?>">
  <?= csrf_field() ?>
  <table class="table">
    <thead><tr><th class="cb"><input type="checkbox" data-select-all="ids[]"></th><th><?= e(__('Email')) ?></th><th><?= e(__('Name')) ?></th><th><?= e(__('Custom fields')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Added')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($subs as $s): $f = json_decode((string) $s['fields'], true) ?: []; ?>
      <tr>
        <td class="cb"><input type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>"></td>
        <td><?= e($s['email']) ?></td>
        <td><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?></td>
        <td class="small muted"><?php foreach ($f as $k => $val): ?><span class="kv"><?= e($k) ?>=<?= e($val) ?></span> <?php endforeach; ?></td>
        <td><?= status_badge($s['status']) ?></td>
        <td class="small"><?= e(fmt_dt($s['created_at'], 'd/m/Y')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$subs): ?><tr><td colspan="6" class="muted"><?= e(__('No subscribers.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="bulkbar">
    <select name="action">
      <option value="unsubscribe"><?= e(__('Unsubscribe selected')) ?></option>
      <option value="suppress"><?= e(__('Add selected to the suppression list')) ?></option>
      <option value="delete"><?= e(__('Delete selected')) ?></option>
    </select>
    <button class="btn" type="submit" data-confirm-btn="<?= e(__('Apply to the selected subscribers?')) ?>"><?= e(__('Apply')) ?></button>
  </div>
</form>
<?= $pg->links() ?>
