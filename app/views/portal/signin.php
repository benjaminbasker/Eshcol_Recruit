<?php /** The client portal's sign-in page. @var bool $sent @var bool $ended */ ?>
<section class="pub-card" style="max-width:520px;margin:0 auto">
  <p class="eyebrow">Eshcol Global · Client portal</p>
  <h1>Sign in</h1>
  <?php if ($sent): ?>
    <div class="flash ok" role="status"><div>If that address has access to the portal, a sign-in link is on its way. It works once, for the next <?= \Eshcol\Ats\Services\ClientPortal::LINK_MINUTES ?> minutes. Please check your inbox (and the spam folder).</div></div>
  <?php elseif ($ended): ?>
    <div class="flash ok" role="status"><div>You are signed out.</div></div>
  <?php endif; ?>
  <p>Enter your work email address and we will email you a link to sign in. There is no password to remember.</p>
  <form method="post" action="/portal/link"><?= csrf() ?>
    <div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" required autocomplete="email" style="width:100%;font-size:16px;padding:11px 12px"></div>
    <button class="btn pub-submit" type="submit">Email me a sign-in link</button>
  </form>
  <p class="small muted" style="margin:14px 0 0">No access yet? Ask your contact at Eshcol Global to add you.</p>
</section>
