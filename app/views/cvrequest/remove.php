<?php /** @var string $token @var string $first */ ?>
<section class="pub-card">
  <p class="eyebrow">Remove my details</p>
  <h1><?= $first !== '' ? 'Delete your details, ' . e($first) . '?' : 'Delete your details?' ?></h1>
  <p>We will permanently delete your CV and the details we hold about you, and we will not contact you again. <b>This cannot be undone.</b></p>
  <form method="post" action="/u/<?= e($token) ?>/remove"><?= csrf() ?>
    <button class="btn pub-submit" type="submit">Delete my details permanently</button>
  </form>
  <p class="small muted" style="margin-top:16px">If you are in a hiring process with one of our clients right now, we will stop all contact at once and delete your details within 30 days, once that process is closed.</p>
  <p class="small muted">Changed your mind? <a href="/u/<?= e($token) ?>/keep">Keep me for the future</a> or <a href="/u/<?= e($token) ?>">share your updated CV</a>.</p>
</section>
