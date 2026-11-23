<?= App\Core\View::partial('partials/pagehead', ['heading' => __('Lists')]) ?>
<?php if ($limit !== null): ?><p class="muted"><?= e(__('Subscribers used: :used of :max', ['used' => nf($used), 'max' => nf($limit)])) ?></p><?php endif; ?>
<form method="post" action="<?= e(url('/lists')) ?>" class="panel form-inline">
  <?= csrf_field() ?>
  <label><?= e(__('New list')) ?><input name="name" required placeholder="<?= e(__('List name')) ?>"></label>
  <label><?= e(__('Description')) ?><input name="description"></label>
  <button class="btn btn-primary" type="submit"><?= e(__('Create list')) ?></button>
</form>
<table class="table">
  <thead><tr><th><?= e(__('Name')) ?></th><th class="num"><?= e(__('Active')) ?></th><th class="num"><?= e(__('Total')) ?></th><th><?= e(__('Created')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($lists as $l): ?>
    <tr>
      <td><a href="<?= e(url('/lists/' . $l['id'])) ?>"><?= e($l['name']) ?></a><?php if ($l['description']): ?><div class="muted small"><?= e($l['description']) ?></div><?php endif; ?></td>
      <td class="num"><?= nf($l['active_count']) ?></td><td class="num"><?= nf($l['total_count']) ?></td>
      <td><?= e(fmt_dt($l['created_at'], 'd/m/Y')) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$lists): ?><tr><td colspan="4" class="muted"><?= e(__('No lists yet.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
