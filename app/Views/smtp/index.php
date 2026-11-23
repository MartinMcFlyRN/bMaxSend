<?php
$isAdmin = $me['role'] === 'admin';
$actions = $isAdmin ? '<a class="btn btn-primary" href="' . e(url('/smtp/create')) . '">' . e(__('New SMTP server')) . '</a>' : '';
echo App\Core\View::partial('partials/pagehead', ['heading' => __('SMTP servers'), 'actions' => $actions]);
?>
<p class="muted">
<?php if ($isAdmin): ?><?= e(__('Create the SMTP servers and assign them to resellers. Resellers can then assign them to their clients.')) ?>
<?php elseif ($me['role'] === 'reseller'): ?><?= e(__('These are the SMTP servers assigned to you by the administrator. Assign them to your clients.')) ?>
<?php else: ?><?= e(__('SMTP servers you can use for your campaigns.')) ?><?php endif; ?>
</p>
<table class="table">
  <thead><tr>
    <th><?= e(__('Name')) ?></th>
    <?php if ($isAdmin): ?><th><?= e(__('Host')) ?></th><?php endif; ?>
    <th><?= e(__('Limits')) ?></th><th><?= e(__('Status')) ?></th>
    <?php if ($me['role'] !== 'client'): ?><th><?= e(__('Assigned to')) ?></th><th></th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($servers as $s): $n = $counts[(int) $s['id']] ?? []; ?>
    <tr>
      <td><?php if ($isAdmin): ?><a href="<?= e(url('/smtp/' . $s['id'] . '/edit')) ?>"><?= e($s['name']) ?></a><?php else: ?><?= e($s['name']) ?><?php endif; ?></td>
      <?php if ($isAdmin): ?><td><code><?= e($s['host'] . ':' . $s['port']) ?></code> <span class="muted small"><?= e(strtoupper($s['encryption'])) ?></span></td><?php endif; ?>
      <td class="small">
        <?= $s['rate_per_minute'] ? e(__(':n/min', ['n' => nf($s['rate_per_minute'])])) : '' ?>
        <?= $s['rate_per_hour'] ? e(__(':n/hour', ['n' => nf($s['rate_per_hour'])])) : '' ?>
        <?= !$s['rate_per_minute'] && !$s['rate_per_hour'] ? e(__('unlimited')) : '' ?>
        <?php if ($s['allowed_from']): ?><div class="muted"><?= e(__('From')) ?>: <?= e($s['allowed_from']) ?></div><?php endif; ?>
      </td>
      <td><?= $s['active'] ? status_badge('active') : status_badge('suspended') ?></td>
      <?php if ($me['role'] !== 'client'): ?>
        <td class="small">
          <?php if ($isAdmin): ?><?= e(__(':r resellers, :c clients', ['r' => $n['reseller'] ?? 0, 'c' => $n['client'] ?? 0])) ?>
          <?php else: ?><?= e(__(':c clients', ['c' => $n['client'] ?? 0])) ?><?php endif; ?>
        </td>
        <td class="row-actions"><a class="btn btn-sm" href="<?= e(url('/smtp/' . $s['id'] . '/assign')) ?>"><?= e(__('Assign')) ?></a></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if (!$servers): ?><tr><td colspan="6" class="muted"><?= e(__('No SMTP servers.')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
