<?php /** @var string $heading @var string $body @var ?string $token */ ?>
<section class="pub-card">
  <h1><?= e($heading) ?></h1>
  <p><?= e($body) ?></p>
  <?php if ($token): ?>
    <form method="post" action="/unsub/<?= e($token) ?>">
      <button class="btn pub-submit" type="submit">Unsubscribe</button>
    </form>
  <?php endif; ?>
</section>
