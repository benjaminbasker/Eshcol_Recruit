<?php /** @var string $token @var string $first */ ?>
<section class="pub-card">
  <p class="eyebrow">Your application</p>
  <h1><?= $first !== '' ? 'Thank you, ' . e($first) . '.' : 'Thank you.' ?></h1>
  <p>Please confirm that you are no longer interested in this role. We will close your application and stop sending reminders about it.</p>
  <form method="post" action="/q/<?= e($token) ?>/no"><?= csrf() ?>
    <button class="btn pub-submit" type="submit">Yes, I'm no longer interested</button>
  </form>
  <p class="small muted" style="margin-top:16px">Changed your mind? <a href="/q/<?= e($token) ?>">Complete your application instead</a>.</p>
</section>
