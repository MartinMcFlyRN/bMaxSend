<?php
$sent = max(0, $s['sent'] ?? 0);
$btns = '';
$act = fn(string $a, string $label, string $cls = 'btn', string $confirm = '') =>
    '<form method="post" class="inline" action="' . e(url('/campaigns/' . $c['id'] . '/' . $a)) . '"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
    . csrf_field() . '<button class="' . $cls . '" type="submit">' . e($label) . '</button></form> ';
if ($c['status'] === 'sending') $btns .= $act('pause', __('Pause'));
if ($c['status'] === 'paused') $btns .= $act('resume', __('Resume'), 'btn btn-primary');
if (in_array($c['status'], ['scheduled', 'sending', 'paused'], true)) $btns .= $act('cancel', __('Cancel sending'), 'btn btn-danger', __('Cancel the remaining sends? This cannot be undone.'));
$btns .= '<a class="btn" href="' . e(url('/campaigns/' . $c['id'] . '/report/export')) . '">' . e(__('Export CSV')) . '</a> ';
$btns .= $act('duplicate', __('Duplicate'));
echo App\Core\View::partial('partials/pagehead', ['heading' => $c['name'], 'crumbs' => [['/campaigns', __('Campaigns')]], 'actions' => $btns]);
?>
<div class="report-meta panel">
  <div><?= status_badge($c['status']) ?> <?php if ($c['last_error']): ?><span class="error-text"><?= e($c['last_error']) ?></span><?php endif; ?></div>
  <div class="small"><strong><?= e(__('Subject')) ?>:</strong> <?= e($c['subject']) ?></div>
  <div class="small"><strong><?= e(__('From')) ?>:</strong> <?= e($c['from_name']) ?> &lt;<?= e($c['from_email']) ?>&gt; · <strong>SMTP:</strong> <?= e($smtp['name'] ?? '—') ?> · <strong><?= e(__('Domain')) ?>:</strong> <?= e($domain['hostname'] ?? App\Core\App::panelHost()) ?></div>
  <div class="small"><strong><?= e(__('Lists')) ?>:</strong> <?= e(implode(', ', array_column($lists, 'name')) ?: '—') ?></div>
  <div class="small"><strong><?= e(__('Scheduled')) ?>:</strong> <?= e(fmt_dt($c['scheduled_at'])) ?> · <strong><?= e(__('Started')) ?>:</strong> <?= e(fmt_dt($c['started_at'])) ?> · <strong><?= e(__('Completed')) ?>:</strong> <?= e(fmt_dt($c['finished_at'])) ?></div>
  <div class="small"><a target="_blank" rel="noopener" href="<?= e(url('/campaigns/' . $c['id'] . '/preview')) ?>"><?= e(__('Open preview')) ?></a></div>
</div>
<?php if ($c['status'] === 'sending' && ($s['total'] ?? 0) > 0): ?>
  <div class="progress"><div style="width:<?= round(($s['sent'] + $s['failed']) / max(1, $s['total']) * 100) ?>%"></div></div>
<?php endif; ?>
<div class="cards">
  <div class="card stat"><div class="stat-value"><?= nf($s['total'] ?? 0) ?></div><div class="stat-label"><?= e(__('Recipients')) ?></div></div>
  <div class="card stat"><div class="stat-value"><?= nf($sent) ?></div><div class="stat-label"><?= e(__('Sent')) ?> <?= ($s['pending'] ?? 0) ? '· ' . nf($s['pending']) . ' ' . e(__('pending')) : '' ?></div></div>
  <div class="card stat"><div class="stat-value"><?= e(percent($s['opened'] ?? 0, $sent)) ?></div><div class="stat-label"><?= e(__('Unique opens')) ?> (<?= nf($s['opened'] ?? 0) ?>)</div></div>
  <div class="card stat"><div class="stat-value"><?= e(percent($s['clicked'] ?? 0, $sent)) ?></div><div class="stat-label"><?= e(__('Unique clicks')) ?> (<?= nf($s['clicked'] ?? 0) ?>)</div></div>
  <div class="card stat"><div class="stat-value"><?= nf($s['unsubscribed'] ?? 0) ?></div><div class="stat-label"><?= e(__('Unsubscribes')) ?></div></div>
  <div class="card stat"><div class="stat-value"><?= nf($s['failed'] ?? 0) ?></div><div class="stat-label"><?= e(__('Failed')) ?></div></div>
</div>
<p class="muted small"><?= e(__('Opens are approximate: some mail clients block images, others (e.g. Apple Mail Privacy Protection) load them automatically.')) ?></p>

<?php if ($links): ?>
<section class="panel">
  <h2><?= e(__('Links')) ?></h2>
  <table class="table">
    <thead><tr><th>URL</th><th class="num"><?= e(__('Unique clicks')) ?></th><th class="num"><?= e(__('Total clicks')) ?></th></tr></thead>
    <tbody><?php foreach ($links as $l): ?><tr><td class="url"><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($l['url']) ?></a></td><td class="num"><?= nf($l['unique_clicks']) ?></td><td class="num"><?= nf($l['clicks']) ?></td></tr><?php endforeach; ?></tbody>
  </table>
</section>
<?php endif; ?>

<section class="panel">
  <h2><?= e(__('Recipients')) ?></h2>
  <form class="filters" method="get">
    <div class="tabs">
      <?php foreach (['' => __('All'), 'sent' => __('Sent'), 'pending' => __('Pending'), 'failed' => __('Failed'), 'opened' => __('Opened'), 'clicked' => __('Clicked'), 'unsubscribed' => __('Unsubscribed')] as $k => $lbl): ?>
        <a href="?<?= e(http_build_query(['filter' => $k, 'q' => $q])) ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search')) ?>"><button class="btn" type="submit"><?= e(__('Search')) ?></button>
  </form>
  <table class="table">
    <thead><tr><th><?= e(__('Email')) ?></th><th><?= e(__('Status')) ?></th><th><?= e(__('Sent')) ?></th><th><?= e(__('Opened')) ?></th><th><?= e(__('Clicked')) ?></th><th><?= e(__('Error')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($recipients as $r): ?>
      <tr>
        <td><?= e($r['email']) ?><?php if ($r['unsubscribed_at']): ?> <?= status_badge('unsubscribed') ?><?php endif; ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td class="small"><?= e(fmt_dt($r['sent_at'])) ?></td>
        <td class="small"><?= e(fmt_dt($r['opened_at'])) ?><?= $r['open_count'] > 1 ? ' ×' . (int) $r['open_count'] : '' ?></td>
        <td class="small"><?= e(fmt_dt($r['clicked_at'])) ?><?= $r['click_count'] > 1 ? ' ×' . (int) $r['click_count'] : '' ?></td>
        <td class="small error-text"><?= e($r['error']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recipients): ?><tr><td colspan="6" class="muted"><?= e($c['status'] === 'scheduled' ? __('The recipient list is built when sending starts.') : __('No recipients.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= $pg->links() ?>
</section>
<?php if (in_array($c['status'], ['sending', 'scheduled'], true)): ?><meta http-equiv="refresh" content="30"><?php endif; ?>
