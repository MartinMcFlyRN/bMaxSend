<?php
$v = fn(string $k, $d = '') => old($k, $c[$k] ?? $d);
$editable = $c['status'] === 'draft';
$sel = old('lists', $selectedLists);
$sel = array_map('intval', (array) $sel);
echo App\Core\View::partial('partials/pagehead', ['heading' => $c['id'] ? $c['name'] : __('New campaign'), 'crumbs' => [['/campaigns', __('Campaigns')]],
    'actions' => $c['id'] ? status_badge($c['status']) : '']);
?>
<?php if ($c['status'] === 'scheduled'): ?>
  <div class="alert alert-info">
    <?= e(__('Scheduled for :date. Unschedule it to make changes.', ['date' => fmt_dt($c['scheduled_at'])])) ?>
    <form method="post" action="<?= e(url('/campaigns/' . $c['id'] . '/unschedule')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= e(__('Unschedule')) ?></button></form>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url($c['id'] ? '/campaigns/' . $c['id'] : '/campaigns')) ?>" id="campaign-form">
  <?= csrf_field() ?>
  <fieldset <?= $editable ? '' : 'disabled' ?>>
  <div class="panel form-grid">
    <label class="full"><?= e(__('Campaign name (internal)')) ?><input name="name" value="<?= e($v('name')) ?>" required></label>
    <label class="full"><?= e(__('Subject')) ?><input name="subject" value="<?= e($v('subject')) ?>" placeholder="<?= e(__('e.g. News of the month for {{first_name}}')) ?>"></label>
    <label class="full"><?= e(__('Preheader')) ?> <small class="muted"><?= e(__('preview text shown by mail clients after the subject')) ?></small><input name="preheader" value="<?= e($v('preheader')) ?>"></label>
    <label><?= e(__('Sender name')) ?><input name="from_name" value="<?= e($v('from_name')) ?>"></label>
    <label><?= e(__('Sender email')) ?><input type="email" name="from_email" value="<?= e($v('from_email')) ?>" placeholder="news@example.com"></label>
    <label><?= e(__('Reply-to')) ?><input type="email" name="reply_to" value="<?= e($v('reply_to')) ?>"></label>
    <label><?= e(__('SMTP server')) ?>
      <select name="smtp_server_id"><option value=""><?= e(__('— select —')) ?></option>
        <?php foreach ($smtps as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($v('smtp_server_id'), $s['id']) ?>><?= e($s['name']) ?><?= $s['allowed_from'] ? ' (' . e($s['allowed_from']) . ')' : '' ?></option><?php endforeach; ?>
      </select></label>
    <label><?= e(__('Domain for links and web version')) ?>
      <select name="domain_id"><option value=""><?= e(__('Default')) ?> (<?= e(App\Core\App::panelHost()) ?>)</option>
        <?php foreach ($domains as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($v('domain_id'), $d['id']) ?>><?= e($d['hostname']) ?></option><?php endforeach; ?>
      </select>
      <?php if (!$domains): ?><a class="small" href="<?= e(url('/domains')) ?>"><?= e(__('Add an alias domain')) ?></a><?php endif; ?></label>
    <div class="checks">
      <label class="check"><input type="checkbox" name="track_opens" value="1"<?= checked($v('track_opens', 1)) ?>> <?= e(__('Track opens')) ?></label>
      <label class="check"><input type="checkbox" name="track_clicks" value="1"<?= checked($v('track_clicks', 1)) ?>> <?= e(__('Track clicks')) ?></label>
    </div>
    <div class="full">
      <div class="label"><?= e(__('Lists')) ?></div>
      <div class="checklist">
        <?php foreach ($lists as $l): ?>
          <label class="check"><input type="checkbox" name="lists[]" value="<?= (int) $l['id'] ?>"<?= checked(in_array((int) $l['id'], $sel, true)) ?>> <?= e($l['name']) ?> <span class="muted small"><?= nf($l['n']) ?></span></label>
        <?php endforeach; ?>
        <?php if (!$lists): ?><span class="muted"><?= e(__('No lists yet.')) ?> <a href="<?= e(url('/lists')) ?>"><?= e(__('Create a list')) ?></a></span><?php endif; ?>
      </div>
      <?php if ($estimate !== null): ?><p class="muted small"><?= e(__('Estimated recipients (deduplicated, without unsubscribed and suppressed): :n', ['n' => nf($estimate)])) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="panel editor">
    <div class="editor-head">
      <h2><?= e(__('Content')) ?></h2>
      <div class="tabs small" data-editor-tabs>
        <a href="#" data-tab="html" class="active">HTML</a><a href="#" data-tab="text"><?= e(__('Text version')) ?></a><a href="#" data-tab="help"><?= e(__('Merge tags')) ?></a>
      </div>
    </div>
    <div class="editor-body">
      <div data-pane="html" class="editor-split">
        <textarea name="html_body" id="html_body" spellcheck="false"><?= e($v('html_body')) ?></textarea>
        <iframe id="live-preview" sandbox="" title="preview"></iframe>
      </div>
      <div data-pane="text" hidden>
        <textarea name="text_body" rows="20" placeholder="<?= e(__('Leave empty to generate it automatically from the HTML')) ?>"><?= e($v('text_body')) ?></textarea>
      </div>
      <div data-pane="help" hidden class="help">
        <table class="table small">
          <tr><td><code>{{first_name}}</code> <code>{{last_name}}</code> <code>{{full_name}}</code> <code>{{email}}</code></td><td><?= e(__('Subscriber data')) ?></td></tr>
          <tr><td><code>{{first_name|cliente}}</code></td><td><?= e(__('Value with a fallback when empty')) ?></td></tr>
          <tr><td><code>{{citta}}</code></td><td><?= e(__('Any custom field imported from CSV (column name)')) ?></td></tr>
          <tr><td><code>{{unsubscribe_url}}</code></td><td><?= e(__('Unsubscribe link (added automatically in the footer if missing)')) ?></td></tr>
          <tr><td><code>{{webversion_url}}</code></td><td><?= e(__('Link to the web version of the email')) ?></td></tr>
          <tr><td><code>{{date}}</code> <code>{{year}}</code></td><td><?= e(__('Sending date')) ?></td></tr>
        </table>
      </div>
    </div>
  </div>
  </fieldset>
  <?php if ($editable): ?>
    <div class="form-actions sticky"><button class="btn btn-primary" type="submit"><?= e(__('Save draft')) ?></button>
      <?php if ($c['id']): ?><a class="btn" target="_blank" rel="noopener" href="<?= e(url('/campaigns/' . $c['id'] . '/preview')) ?>"><?= e(__('Open preview')) ?></a><?php endif; ?></div>
  <?php endif; ?>
</form>

<?php if ($c['id']): ?>
<div class="grid-2">
  <form method="post" action="<?= e(url('/campaigns/' . $c['id'] . '/test')) ?>" class="panel stack">
    <?= csrf_field() ?>
    <h2><?= e(__('Send a test')) ?></h2>
    <p class="muted small"><?= e(__('Save the draft first: the test uses the saved version.')) ?></p>
    <label><?= e(__('Email')) ?><input type="email" name="test_email" value="<?= e($me['email']) ?>" required></label>
    <div><button class="btn" type="submit"><?= e(__('Send test')) ?></button></div>
  </form>
  <?php if ($editable): ?>
  <form method="post" action="<?= e(url('/campaigns/' . $c['id'] . '/schedule')) ?>" class="panel stack" data-confirm="<?= e(__('Schedule the sending of this campaign?')) ?>">
    <?= csrf_field() ?>
    <h2><?= e(__('Schedule')) ?></h2>
    <label><?= e(__('Send on')) ?> <small class="muted">(<?= e(user_timezone()) ?>)</small><input type="datetime-local" name="scheduled_at" value="<?= e(utc_to_local_input($c['scheduled_at']) ?: fmt_dt(gmdate('Y-m-d H:i:s', time() + 3600), 'Y-m-d\TH:i')) ?>"></label>
    <label class="check"><input type="checkbox" name="send_now" value="1"> <?= e(__('Send now')) ?></label>
    <div><button class="btn btn-primary" type="submit"><?= e(__('Schedule sending')) ?></button></div>
  </form>
  <?php endif; ?>
</div>
<section class="panel danger-zone">
  <div class="inline-forms">
    <form method="post" action="<?= e(url('/campaigns/' . $c['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= e(__('Duplicate')) ?></button></form>
    <?php if ($editable): ?>
    <form method="post" action="<?= e(url('/campaigns/' . $c['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete this campaign?')) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= e(__('Delete')) ?></button></form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
