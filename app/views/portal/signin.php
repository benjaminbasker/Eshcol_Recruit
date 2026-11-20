<?php /** The client portal's sign-in page: step 1, your email (20 Nov 2026: a code, not a link). @var bool $ended @var ?string $error */ ?>
<section class="pub-card portal-signin">
  <p class="eyebrow">Eshcol Global · Client portal</p>
  <h1>Sign in</h1>
  <?php if (!empty($ended)): ?>
    <div class="flash ok" role="status"><div>You are signed out.</div></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="flash error" role="alert"><div><?= e($error) ?></div></div>
  <?php endif; ?>
  <p>Enter your work email address. We will email you a 6-digit code to sign in with: there is no password to remember.</p>
  <form method="post" action="/portal/link"><?= csrf() ?>
    <div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" required autocomplete="email" autofocus class="pub-input"></div>
    <button class="btn pub-submit" type="submit">Email me a code</button>
  </form>
  <p class="small muted" style="margin:14px 0 0">No access yet? Ask your contact at Eshcol Global to add you.</p>
</section>
