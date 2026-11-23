<?= App\Core\View::partial('partials/pagehead', ['heading' => __('Import subscribers'), 'crumbs' => [['/lists', __('Lists')], ['/lists/' . $list['id'], $list['name']]]]) ?>
<form method="post" action="<?= e(url('/lists/' . $list['id'] . '/import')) ?>" enctype="multipart/form-data" class="panel stack">
  <?= csrf_field() ?>
  <p><?= e(__('Upload a CSV file (separator , ; tab or |). The first row must contain the column names. Recognised columns: email, first_name (nome), last_name (cognome); every other column is saved as a custom field and can be used in the emails as {{column_name}}.')) ?></p>
  <pre class="code">email;nome;cognome;citta
mario.rossi@example.com;Mario;Rossi;Rimini</pre>
  <label><?= e(__('CSV file')) ?><input type="file" name="file" accept=".csv,.txt,text/csv"></label>
  <label><?= e(__('…or paste the data here')) ?><textarea name="paste" rows="8" placeholder="email,first_name,last_name"></textarea></label>
  <label class="check"><input type="checkbox" name="update_existing" value="1"> <?= e(__('Update the existing subscribers (names and custom fields)')) ?></label>
  <p class="muted small"><?= e(__('Make sure you have the consent of the recipients (GDPR). Unsubscribed and suppressed addresses are never emailed.')) ?></p>
  <div><button class="btn btn-primary" type="submit"><?= e(__('Import')) ?></button></div>
</form>
