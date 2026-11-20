<?php /** The client portal's sign-in page: step 2, the code (20 Nov 2026). @var string $email @var ?string $error */ ?>
<section class="pub-card portal-signin">
  <p class="eyebrow">Eshcol Global · Client portal</p>
  <h1>Enter your code</h1>
  <?php if (!empty($error)): ?>
    <div class="flash error" role="alert"><div><?= e($error) ?></div></div>
  <?php else: ?>
    <p>If <b><?= e($email) ?></b> has access to the portal, a 6-digit code is on its way to it. It works once, for the next <?= \Eshcol\Ats\Services\ClientPortal::CODE_MINUTES ?> minutes. Please check your inbox (and the spam folder).</p>
  <?php endif; ?>
  <form method="post" action="/portal/code"><?= csrf() ?>
    <input type="hidden" name="email" value="<?= e($email) ?>">
    <div class="field"><label for="code">6-digit code</label>
      <input type="text" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" autofocus class="pub-input code-input" placeholder="000000"></div>
    <button class="btn pub-submit" type="submit">Sign in</button>
  </form>
  <p class="small muted" style="margin:14px 0 0">You will stay signed in on this device for <?= \Eshcol\Ats\Services\ClientPortal::SESSION_DAYS ?> days. Please don't use a shared computer.</p>
  <form method="post" action="/portal/link" style="margin:10px 0 0"><?= csrf() ?>
    <input type="hidden" name="email" value="<?= e($email) ?>">
    <button class="btn link small" type="submit">Send me a new code</button> <span class="small muted">· <a href="/portal">Use a different email</a></span>
  </form>
</section>
