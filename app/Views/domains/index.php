<?= App\Core\View::partial('partials/pagehead', ['heading' => __('Domains')]) ?>
<p class="muted"><?= e(__('Alias domains are used for the web version of the emails, the tracked links and the unsubscribe page, so that recipients see the sender\'s own domain.')) ?></p>
<form method="post" action="<?= e(url('/domains')) ?>" class="panel form-inline">
  <?= csrf_field() ?>
  <label><?= e(__('Hostname')) ?><input name="hostname" required placeholder="news.example.com" value="<?= e(old('hostname')) ?>"></label>
  <?php if ($owners): ?>
    <label><?= e(__('Owner')) ?>
      <select name="owner_id"><?php foreach ($owners as $o): ?><option value="<?= (int) $o['id'] ?>"<?= selected($o['id'], $me['id']) ?>><?= e($o['name'] . ' (' . role_label($o['role']) . ')') ?></option><?php endforeach; ?></select></label>
    <label class="check"><input type="checkbox" name="shared" value="1"> <?= e(__('Shared with the users below the owner')) ?></label>
  <?php endif; ?>
  <label class="check"><input type="checkbox" name="use_https" value="1" checked> HTTPS</label>
  <button class="btn btn-primary" type="submit"><?= e(__('Add domain')) ?></button>
</form>
<table class="table">
  <thead><tr><th><?= e(__('Hostname')) ?></th><?php if ($owners): ?><th><?= e(__('Owner')) ?></th><th><?= e(__('Shared')) ?></th><?php endif; ?><th><?= e(__('Status')) ?></th><th><?= e(__('Last check')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($domains as $d): ?>
    <tr>
      <td><a href="<?= e(url('/domains/' . $d['id'])) ?>"><?= e($d['hostname']) ?></a></td>
      <?php if ($owners): ?><td><?= e($d['owner_name']) ?> <span class="muted small"><?= e(role_label($d['owner_role'])) ?></span></td><td><?= $d['shared'] ? '✓' : '' ?></td><?php endif; ?>
      <td><?= status_badge($d['status']) ?></td>
      <td class="small"><?= e(fmt_dt($d['last_check_at'])) ?> <span class="muted"><?= e($d['last_check_msg']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$domains): ?><tr><td colspan="5" class="muted"><?= e(__('No domains yet.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
<?php if ($shared): ?>
  <h2><?= e(__('Domains shared with you')) ?></h2>
  <ul><?php foreach ($shared as $d): ?><li><code><?= e($d['hostname']) ?></code></li><?php endforeach; ?></ul>
<?php endif; ?>
