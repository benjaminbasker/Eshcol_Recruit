<?php /** The "No longer interested" link from WhatsApp (28 Nov 2026). @var string $token @var string $first @var string $role */ ?>
<section class="pub-card">
  <p class="eyebrow">Your application</p>
  <h1><?= $first !== '' ? 'Thank you, ' . e($first) . '.' : 'Thank you.' ?></h1>
  <p>Please confirm that you are no longer interested in <b><?= e($role) ?></b>. We will close your application and won't contact you about this role again.</p>
  <form method="post" action="/n/<?= e($token) ?>"><?= csrf() ?>
    <button class="btn pub-submit" type="submit">Yes, I'm no longer interested</button>
  </form>
  <p class="small muted" style="margin-top:16px">Changed your mind? Just close this page, or reply to our WhatsApp message.</p>
</section>
