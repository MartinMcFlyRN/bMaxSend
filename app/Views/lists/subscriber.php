<?= App\Core\View::partial('partials/pagehead', ['heading' => $sub['email'], 'crumbs' => [['/lists', __('Lists')], ['/lists/' . $list['id'], $list['name']]]]) ?>
<form method="post" action="<?= e(url('/lists/' . $list['id'] . '/subscribers/' . $sub['id'])) ?>" class="panel form-grid">
  <?= csrf_field() ?>
  <label><?= e(__('Email')) ?> *<input type="email" name="email" value="<?= e($sub['email']) ?>" required></label>
  <label><?= e(__('Name')) ?><input name="name" value="<?= e($sub['name']) ?>"></label>
  <label><?= e(__('Status')) ?>
    <select name="status">
      <?php foreach ([0, 1, 2] as $st): ?><option value="<?= $st ?>"<?= selected($sub['status'], $st) ?>><?= $st ?> · <?= e(App\Services\ListFields::statusLabel($st)) ?></option><?php endforeach; ?>
    </select></label>
  <label><?= e(__('Track')) ?>
    <select name="track"><option value="0"<?= selected($sub['track'], 0) ?>>0 · <?= e(__('Disabled')) ?></option><option value="1"<?= selected($sub['track'], 1) ?>>1 · <?= e(__('Enabled')) ?></option></select></label>
  <?php if ($fields): ?><h3 class="full"><?= e(__('Custom fields')) ?></h3><?php endif; ?>
  <?php foreach ($fields as $f): ?>
    <label><?= e($f['label']) ?> <small class="muted">{{<?= e($f['field_key']) ?>}}</small><input name="fields[<?= e($f['field_key']) ?>]" value="<?= e($values[$f['field_key']] ?? '') ?>"></label>
  <?php endforeach; ?>
  <p class="full muted small"><?= e(__('Added')) ?>: <?= e(fmt_dt($sub['created_at'])) ?> · <?= e(__('Updated')) ?>: <?= e(fmt_dt($sub['updated_at'])) ?><?php if ($sub['unsubscribed_at']): ?> · <?= e(__('Unsubscribed')) ?>: <?= e(fmt_dt($sub['unsubscribed_at'])) ?><?php endif; ?></p>
  <div class="full"><button class="btn btn-primary" type="submit"><?= e(__('Save')) ?></button> <a class="btn" href="<?= e(url('/lists/' . $list['id'] . '#fields')) ?>"><?= e(__('Manage fields')) ?></a></div>
</form>
<form method="post" action="<?= e(url('/lists/' . $list['id'] . '/subscribers/' . $sub['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete this subscriber?')) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= e(__('Delete')) ?></button></form>
