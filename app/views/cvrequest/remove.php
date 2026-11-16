<?php /** @var string $token @var string $first */ ?>
<section class="pub-card">
  <p class="eyebrow">Remove my details</p>
  <h1><?= $first !== '' ? 'Thank you, ' . e($first) . '.' : 'Thank you.' ?></h1>
  <p>Please confirm that you would like us to delete your details. We will not contact you again.</p>
  <form method="post" action="/u/<?= e($token) ?>/remove"><?= csrf() ?>
    <button class="btn pub-submit" type="submit">Yes, remove my details</button>
  </form>
  <p class="small muted" style="margin-top:16px">Changed your mind? <a href="/u/<?= e($token) ?>/keep">Keep me for the future</a> or <a href="/u/<?= e($token) ?>">share your updated CV</a>.</p>
</section>
