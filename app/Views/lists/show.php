<?php
$actions = '<a class="btn" href="' . e(url('/lists/' . $list['id'] . '/import')) . '">' . e(__('Import CSV')) . '</a> '
    . '<a class="btn" href="' . e(url('/lists/' . $list['id'] . '/export')) . '">' . e(__('Export CSV')) . '</a>';
echo App\Core\View::partial('partials/pagehead', ['heading' => $list['name'], 'crumbs' => [['/lists', __('Lists')]], 'actions' => $actions]);
?>
<div class="grid-2">
  <form method="post" action="<?= e(url('/lists/' . $list['id'] . '/subscribers')) ?>" class="panel stack">
    <?= csrf_field() ?>
    <h2><?= e(__('Add subscriber')) ?></h2>
    <div class="row2">
      <label><?= e(__('Email')) ?> *<input type="email" name="email" required></label>
      <label><?= e(__('Name')) ?><input name="name"></label>
    </div>
    <div class="row2">
      <label><?= e(__('Status')) ?>
        <select name="status">
          <option value="0" selected>0 · <?= e(__('Disabled')) ?></option>
          <option value="1">1 · <?= e(__('Enabled')) ?></option>
          <option value="2">2 · <?= e(__('Unsubscribed')) ?></option>
        </select></label>
      <label><?= e(__('Track')) ?>
        <select name="track"><option value="0" selected>0 · <?= e(__('Disabled')) ?></option><option value="1">1 · <?= e(__('Enabled')) ?></option></select></label>
    </div>
    <?php if ($fields): ?>
      <div class="row2">
        <?php foreach ($fields as $f): ?><label><?= e($f['label']) ?><input name="fields[<?= e($f['field_key']) ?>]"></label><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div><button class="btn btn-primary" type="submit"><?= e(__('Add')) ?></button></div>
  </form>

  <section class="panel" id="fields">
    <h2><?= e(__('Custom fields')) ?></h2>
    <p class="muted small"><?= e(__('Fixed fields: email (required), nome, stato (1 enabled, 0 disabled, 2 unsubscribed), track (1/0). The other columns of an imported CSV are created here automatically. Use them in the emails as {{key}}.')) ?></p>
    <table class="table small">
      <thead><tr><th><?= e(__('Key')) ?> / {{tag}}</th><th><?= e(__('Label')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($fields as $f): ?>
        <tr>
          <td colspan="2">
            <form method="post" action="<?= e(url('/lists/' . $list['id'] . '/fields/' . $f['id'])) ?>" class="field-row">
              <?= csrf_field() ?>
              <input name="field_key" value="<?= e($f['field_key']) ?>" pattern="[A-Za-z0-9_ ]+" title="a-z 0-9 _" required>
              <input name="label" value="<?= e($f['label']) ?>">
              <button class="btn btn-sm" type="submit"><?= e(__('Save')) ?></button>
            </form>
          </td>
          <td class="row-actions">
            <form method="post" action="<?= e(url('/lists/' . $list['id'] . '/fields/' . $f['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete the field and its values for every subscriber?')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit">×</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$fields): ?><tr><td colspan="3" class="muted"><?= e(__('No custom fields yet.')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
    <form method="post" action="<?= e(url('/lists/' . $list['id'] . '/fields')) ?>" class="field-row">
      <?= csrf_field() ?>
      <input name="label" placeholder="<?= e(__('New field, e.g. City')) ?>" required>
      <button class="btn btn-sm" type="submit"><?= e(__('Add field')) ?></button>
    </form>

    <details class="top-gap">
      <summary><?= e(__('List settings')) ?></summary>
      <form method="post" action="<?= e(url('/lists/' . $list['id'])) ?>" class="stack">
        <?= csrf_field() ?>
        <label><?= e(__('Name')) ?><input name="name" value="<?= e($list['name']) ?>" required></label>
        <label><?= e(__('Description')) ?><input name="description" value="<?= e($list['description']) ?>"></label>
        <div><button class="btn" type="submit"><?= e(__('Save')) ?></button></div>
      </form>
      <form method="post" action="<?= e(url('/lists/' . $list['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete the list and all its subscribers?')) ?>" class="top-gap"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= e(__('Delete list')) ?></button></form>
    </details>
  </section>
</div>

<form class="filters" method="get">
  <div class="tabs">
    <?php foreach (['' => __('All'), '1' => __('Enabled'), '0' => __('Disabled'), '2' => __('Unsubscribed')] as $k => $lbl): ?>
      <a href="?<?= e(http_build_query(['status' => $k, 'q' => $q])) ?>" class="<?= $status === (string) $k ? 'active' : '' ?>"><?= e($lbl) ?> <span class="muted"><?= nf($k === '' ? array_sum($counts) : ($counts[(int) $k] ?? 0)) ?></span></a>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search')) ?>">
  <button class="btn" type="submit"><?= e(__('Search')) ?></button>
</form>

<form method="post" action="<?= e(url('/lists/' . $list['id'] . '/subscribers/bulk')) ?>">
  <?= csrf_field() ?>
  <div class="table-scroll">
  <table class="table">
    <thead><tr>
      <th class="cb"><input type="checkbox" data-select-all="ids[]"></th>
      <th><?= e(__('Email')) ?></th><th><?= e(__('Name')) ?></th>
      <th><?= e(__('Status')) ?></th><th><?= e(__('Track')) ?></th>
      <?php foreach ($fields as $f): ?><th><?= e($f['label']) ?></th><?php endforeach; ?>
      <th><?= e(__('Added')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($subs as $s): $vals = json_decode((string) $s['fields'], true) ?: []; ?>
      <tr>
        <td class="cb"><input type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>"></td>
        <td><a href="<?= e(url('/lists/' . $list['id'] . '/subscribers/' . $s['id'])) ?>"><?= e($s['email']) ?></a></td>
        <td><?= e($s['name']) ?></td>
        <td><?= sub_status_badge($s['status']) ?></td>
        <td><?= (int) $s['track'] === 1 ? '✓' : '<span class="muted">—</span>' ?></td>
        <?php foreach ($fields as $f): ?><td class="small"><?= e($vals[$f['field_key']] ?? '') ?></td><?php endforeach; ?>
        <td class="small"><?= e(fmt_dt($s['created_at'], 'd/m/Y')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$subs): ?><tr><td colspan="<?= 6 + count($fields) ?>" class="muted"><?= e(__('No subscribers.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <div class="bulkbar">
    <select name="action">
      <option value="enable"><?= e(__('Enable selected (status 1)')) ?></option>
      <option value="disable"><?= e(__('Disable selected (status 0)')) ?></option>
      <option value="unsubscribe"><?= e(__('Unsubscribe selected (status 2)')) ?></option>
      <option value="track_on"><?= e(__('Enable tracking')) ?></option>
      <option value="track_off"><?= e(__('Disable tracking')) ?></option>
      <option value="suppress"><?= e(__('Add selected to the suppression list')) ?></option>
      <option value="delete"><?= e(__('Delete selected')) ?></option>
    </select>
    <button class="btn" type="submit" data-confirm-btn="<?= e(__('Apply to the selected subscribers?')) ?>"><?= e(__('Apply')) ?></button>
  </div>
</form>
<?= $pg->links() ?>
