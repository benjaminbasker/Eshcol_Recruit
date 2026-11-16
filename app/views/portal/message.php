<?php /** A short message page in the client portal. @var string $heading @var string $body @var ?array $me */ ?>
<section class="pub-card" style="max-width:560px;margin:0 auto">
  <h1><?= e($heading) ?></h1>
  <p><?= e($body) ?></p>
  <?php if (!empty($signin)): ?>
    <form method="post" action="/portal/link"><?= csrf() ?>
      <div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" required autocomplete="email" style="width:100%;font-size:16px;padding:11px 12px"></div>
      <button class="btn pub-submit" type="submit">Email me a sign-in link</button>
    </form>
  <?php elseif (!empty($me)): ?>
    <p><a class="btn" href="/portal">Your positions</a></p>
  <?php endif; ?>
</section>
