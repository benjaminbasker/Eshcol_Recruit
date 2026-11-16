<?php /** A sign-in link was opened: one press signs in. @var array $contact @var string $token */ ?>
<section class="pub-card" style="max-width:520px;margin:0 auto">
  <p class="eyebrow">Eshcol Global · Client portal</p>
  <h1>Welcome, <?= e(trim((string) strtok((string) $contact['name'], ' ')) ?: 'there') ?></h1>
  <p>You are signing in to the client portal for <b><?= e($contact['client']) ?></b>.</p>
  <form method="post" action="/portal/in/<?= e($token) ?>"><?= csrf() ?>
    <button class="btn pub-submit" type="submit">Continue to the portal</button>
  </form>
  <p class="small muted" style="margin:14px 0 0">You will stay signed in on this device for <?= \Eshcol\Ats\Services\ClientPortal::SESSION_DAYS ?> days. Please don't use a shared computer.</p>
</section>
