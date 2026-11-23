<?= App\Core\View::partial('partials/pagehead', ['heading' => __('Campaigns'),
    'actions' => '<a class="btn btn-primary" href="' . e(url('/campaigns/create')) . '">' . e(__('New campaign')) . '</a>']) ?>
<div class="filters"><div class="tabs">
  <?php foreach (['' => __('All'), 'draft' => __('Draft'), 'scheduled' => __('Scheduled'), 'sending' => __('Sending'), 'paused' => __('Paused'), 'sent' => __('Sent'), 'cancelled' => __('Cancelled')] as $k => $lbl): ?>
    <a href="<?= e(url('/campaigns', $k ? ['status' => $k] : [])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div></div>
<table class="table">
  <thead><tr><th><?= e(__('Campaign')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Scheduled')) ?></th><th class="num"><?= e(__('Sent')) ?></th><th class="num"><?= e(__('Opens')) ?></th><th class="num"><?= e(__('Clicks')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td><a href="<?= e(url('/campaigns/' . $c['id'] . (in_array($c['status'], ['draft', 'scheduled'], true) ? '/edit' : '/report'))) ?>"><?= e($c['name']) ?></a>
        <div class="muted small"><?= e($c['subject']) ?></div>
        <?php if ($c['last_error']): ?><div class="error-text small"><?= e($c['last_error']) ?></div><?php endif; ?></td>
      <td><?= status_badge($c['status']) ?></td>
      <td class="small"><?= e(fmt_dt($c['scheduled_at'])) ?></td>
      <td class="num"><?= nf($c['sent_count']) ?><?php if ($c['total_recipients']): ?> <span class="muted small">/ <?= nf($c['total_recipients']) ?></span><?php endif; ?></td>
      <td class="num"><?= $c['sent_count'] ? e(percent($c['opens'], $c['sent_count'])) : '—' ?></td>
      <td class="num"><?= $c['sent_count'] ? e(percent($c['clicks'], $c['sent_count'])) : '—' ?></td>
      <td class="row-actions">
        <?php if ($c['status'] !== 'draft'): ?><a class="btn btn-sm" href="<?= e(url('/campaigns/' . $c['id'] . '/report')) ?>"><?= e(__('Report')) ?></a><?php endif; ?>
        <form method="post" action="<?= e(url('/campaigns/' . $c['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= e(__('Duplicate')) ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= e(__('No campaigns yet.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pg->links() ?>
