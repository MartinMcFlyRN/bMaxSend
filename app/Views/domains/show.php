<?= App\Core\View::partial('partials/pagehead', ['heading' => $d['hostname'], 'crumbs' => [['/domains', __('Domains')]]]) ?>
<div class="grid-2">
<section class="panel">
  <h2><?= e(__('Status')) ?>: <?= status_badge($d['status']) ?></h2>
  <p class="small"><?= e(__('Owner')) ?>: <?= e($owner['name'] ?? '') ?> · <?= e(__('Used by :n campaigns', ['n' => $campaigns])) ?></p>
  <?php if ($d['last_check_msg']): ?><p class="small muted"><?= e(fmt_dt($d['last_check_at'])) ?> — <?= e($d['last_check_msg']) ?></p><?php endif; ?>
  <div class="inline-forms">
    <form method="post" action="<?= e(url('/domains/' . $d['id'] . '/verify')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= e(__('Verify DNS')) ?></button></form>
    <?php if ($me['role'] === 'admin' && $d['status'] !== 'verified'): ?>
      <form method="post" action="<?= e(url('/domains/' . $d['id'] . '/force')) ?>" data-confirm="<?= e(__('Mark as verified without DNS check?')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= e(__('Force verification')) ?></button></form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/domains/' . $d['id'] . '/delete')) ?>" data-confirm="<?= e(__('Delete this domain?')) ?>"><?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= e(__('Delete')) ?></button></form>
  </div>
  <form method="post" action="<?= e(url('/domains/' . $d['id'])) ?>" class="stack top-gap">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="use_https" value="1"<?= checked($d['use_https']) ?>> <?= e(__('Use HTTPS in the links (a valid certificate for this hostname is required on the web server)')) ?></label>
    <?php if ($me['role'] !== 'client'): ?><label class="check"><input type="checkbox" name="shared" value="1"<?= checked($d['shared']) ?>> <?= e(__('Shared with the users below the owner')) ?></label><?php endif; ?>
    <div><button class="btn" type="submit"><?= e(__('Save')) ?></button></div>
  </form>
</section>
<section class="panel">
  <h2><?= e(__('DNS configuration')) ?></h2>
  <p><?= e(__('Create these records in the DNS zone of the domain:')) ?></p>
  <table class="table dns">
    <thead><tr><th><?= e(__('Type')) ?></th><th><?= e(__('Name')) ?></th><th><?= e(__('Value')) ?></th></tr></thead>
    <tbody>
      <tr><td>CNAME</td><td><code><?= e($d['hostname']) ?>.</code></td><td><code><?= e($target) ?>.</code></td></tr>
      <?php if (config('tracking.require_txt', true)): ?>
      <tr><td>TXT</td><td><code><?= e($txtName) ?>.</code></td><td><code>bmaxsend-verify=<?= e($d['verify_token']) ?></code></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <p class="muted small"><?= e(__('If a CNAME is not possible you can use A/AAAA records with the same IP addresses as :target.', ['target' => $target])) ?></p>
  <p class="muted small"><?= e(__('After verification the web server must answer for this hostname (catch-all virtual host) and, for HTTPS, have a certificate for it: see docs/nginx.conf.example.')) ?></p>
</section>
</div>
