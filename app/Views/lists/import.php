<?= App\Core\View::partial('partials/pagehead', ['heading' => __('Import subscribers'), 'crumbs' => [['/lists', __('Lists')], ['/lists/' . $list['id'], $list['name']]]]) ?>
<form method="post" action="<?= e(url('/lists/' . $list['id'] . '/import')) ?>" enctype="multipart/form-data" class="panel stack">
  <?= csrf_field() ?>
  <p><?= e(__('Upload a CSV file (separator , ; tab or |). The first row must contain the column names.')) ?></p>
  <table class="table small">
    <thead><tr><th><?= e(__('Column')) ?></th><th><?= e(__('Value')) ?></th><th><?= e(__('If missing or empty')) ?></th></tr></thead>
    <tbody>
      <tr><td><code>email</code> *</td><td><?= e(__('email address (required)')) ?></td><td><?= e(__('row discarded')) ?></td></tr>
      <tr><td><code>nome</code> / <code>name</code></td><td><?= e(__('optional')) ?></td><td>—</td></tr>
      <tr><td><code>stato</code> / <code>status</code></td><td>1 = <?= e(__('enabled')) ?>, 0 = <?= e(__('disabled')) ?>, 2 = <?= e(__('unsubscribed')) ?></td><td><strong>0</strong></td></tr>
      <tr><td><code>track</code></td><td>1 = <?= e(__('enabled')) ?>, 0 = <?= e(__('disabled')) ?></td><td><strong>0</strong></td></tr>
      <tr><td><em><?= e(__('any other column')) ?></em></td><td><?= e(__('becomes a custom field of the list, created automatically, usable as {{column_name}}')) ?></td><td>—</td></tr>
    </tbody>
  </table>
  <pre class="code">email;nome;stato;track;citta;codice_cliente
mario.rossi@example.com;Mario Rossi;1;1;Rimini;C0012</pre>
  <?php if ($fields): ?><p class="small muted"><?= e(__('Existing custom fields of this list:')) ?> <?php foreach ($fields as $f): ?><code><?= e($f['field_key']) ?></code> <?php endforeach; ?></p><?php endif; ?>
  <label><?= e(__('CSV file')) ?><input type="file" name="file" accept=".csv,.txt,text/csv"></label>
  <label><?= e(__('…or paste the data here')) ?><textarea name="paste" rows="8" placeholder="email;nome;stato;track"></textarea></label>
  <label class="check"><input type="checkbox" name="update_existing" value="1"> <?= e(__('Update the subscribers already in the list (only the columns present in the file; unsubscribed addresses stay unsubscribed)')) ?></label>
  <p class="muted small"><?= e(__('Make sure you have the consent of the recipients (GDPR). Only subscribers with status 1 receive the campaigns; suppressed addresses are never emailed.')) ?></p>
  <div><button class="btn btn-primary" type="submit"><?= e(__('Import')) ?></button></div>
</form>
