<?php use App\Core\Auth; ?>
<?= App\Core\View::partial('partials/pagehead', ['heading' => __('Dashboard')]) ?>
<div class="cards">
  <?php foreach ($stats as $label => $value): ?>
    <div class="card stat"><div class="stat-value"><?= is_numeric($value) ? nf($value) : e($value) ?></div><div class="stat-label"><?= e($label) ?></div></div>
  <?php endforeach; ?>
</div>
<?php if (Auth::is('client')): ?>
  <div class="quick">
    <a class="btn btn-primary" href="<?= e(url('/campaigns/create')) ?>"><?= e(__('New campaign')) ?></a>
    <a class="btn" href="<?= e(url('/lists')) ?>"><?= e(__('Manage lists')) ?></a>
  </div>
<?php endif; ?>
<section class="panel">
  <h2><?= e(__('Recent campaigns')) ?></h2>
  <?php if (!$recent): ?>
    <p class="muted"><?= e(__('No campaigns yet.')) ?></p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th><?= e(__('Campaign')) ?></th><?php if (!Auth::is('client')): ?><th><?= e(__('Client')) ?></th><?php endif; ?><th><?= e(__('Status')) ?></th><th><?= e(__('Scheduled')) ?></th><th class="num"><?= e(__('Sent')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $c): ?>
      <tr>
        <td><?php if (Auth::is('client')): ?><a href="<?= e(url('/campaigns/' . $c['id'] . ($c['status'] === 'draft' ? '/edit' : '/report'))) ?>"><?= e($c['name']) ?></a><?php else: ?><?= e($c['name']) ?><?php endif; ?></td>
        <?php if (!Auth::is('client')): ?><td><?= e($c['client_name']) ?></td><?php endif; ?>
        <td><?= status_badge($c['status']) ?></td>
        <td><?= e(fmt_dt($c['scheduled_at'])) ?></td>
        <td class="num"><?= nf($c['sent_count']) ?> / <?= nf($c['total_recipients']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
