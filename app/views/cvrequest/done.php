<?php /** @var string $heading @var string $body @var bool $linkedin */ ?>
<section class="pub-card">
  <h1><?= e($heading) ?></h1>
  <p><?= e($body) ?></p>
  <p class="small muted">You can close this page now.</p>
  <?php if (!empty($linkedin)) { include __DIR__ . '/_linkedin.php'; } ?>
</section>
