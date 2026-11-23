<div class="pagehead">
  <div>
    <?php if (!empty($crumbs)): ?><div class="crumbs"><?php foreach ($crumbs as [$href, $label]): ?><a href="<?= e(url($href)) ?>"><?= e($label) ?></a> / <?php endforeach; ?></div><?php endif; ?>
    <h1><?= e($heading) ?></h1>
  </div>
  <div class="actions"><?= $actions ?? '' ?></div>
</div>
