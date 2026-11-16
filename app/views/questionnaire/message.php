<section class="pub-card">
  <h1><?= e($heading) ?></h1>
  <p><?= e($body) ?></p>
  <?php if (!empty($extra)): ?><p class="small muted"><?= e($extra) ?></p><?php endif; ?>
</section>
