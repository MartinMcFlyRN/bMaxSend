<?= App\Core\View::partial('partials/pagehead', ['heading' => __('Suppression list')]) ?>
<p class="muted"><?= e(__('Addresses in this list are never emailed by your campaigns, whatever list they belong to.')) ?></p>
<form method="post" action="<?= e(url('/suppressions')) ?>" class="panel stack">
  <?= csrf_field() ?>
  <label><?= e(__('Add addresses (one per line)')) ?><textarea name="emails" rows="4"></textarea></label>
  <div><button class="btn btn-primary" type="submit"><?= e(__('Add')) ?></button></div>
</form>
<form class="filters" method="get"><input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search')) ?>"><button class="btn" type="submit"><?= e(__('Search')) ?></button></form>
<table class="table">
  <thead><tr><th><?= e(__('Email')) ?></th><th><?= e(__('Reason')) ?></th><th><?= e(__('Added')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e($r['email']) ?></td><td><?= e(__(ucfirst($r['reason']))) ?></td><td><?= e(fmt_dt($r['created_at'])) ?></td>
      <td class="row-actions"><form method="post" action="<?= e(url('/suppressions/delete')) ?>" data-confirm="<?= e(__('Remove from the suppression list?')) ?>"><?= csrf_field() ?><input type="hidden" name="email" value="<?= e($r['email']) ?>"><button class="btn btn-sm" type="submit"><?= e(__('Remove')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="muted"><?= e(__('The list is empty.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pg->links() ?>
