<h1><?= e(__('Unsubscribe')) ?></h1>
<?php if ($done): ?>
  <p><?= e(__('The address :email is already unsubscribed.', ['email' => $r['email']])) ?></p>
<?php else: ?>
  <p><?= e(__('Do you want to stop receiving emails from :from at :email?', ['from' => $c['from_name'], 'email' => $r['email']])) ?></p>
  <form method="post" action="<?= e($action) ?>" class="stack">
    <label class="check"><input type="checkbox" name="all" value="1"> <?= e(__('Unsubscribe me from all communications of this sender')) ?></label>
    <button class="btn btn-primary btn-block" type="submit"><?= e(__('Confirm unsubscribe')) ?></button>
  </form>
<?php endif; ?>
