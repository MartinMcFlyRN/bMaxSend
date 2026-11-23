<h1 class="error-code"><?= e($status) ?></h1>
<p><?= e($message) ?></p>
<?php if (session_status() === PHP_SESSION_ACTIVE): ?><p><a href="<?= e(url('/')) ?>"><?= e(__('Back to the dashboard')) ?></a></p><?php endif; ?>
