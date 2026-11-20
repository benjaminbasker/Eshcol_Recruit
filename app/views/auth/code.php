<p class="tag">Two-step sign-in</p>
<h1>Enter your code</h1>
<p class="muted">Open your authenticator app and type the 6-digit code shown for Eshcol Recruit.</p>
<?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/login/code">
  <?= csrf() ?>
  <div class="field">
    <label for="code">6-digit code</label>
    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code" style="font-size:22px;letter-spacing:6px;text-align:center">
  </div>
  <label style="display:flex;gap:8px;align-items:flex-start;font-weight:normal;margin:0 0 14px"><input type="checkbox" name="trust" value="1" checked style="margin-top:3px"> <span>Trust this device for <?= \Eshcol\Ats\Auth::TRUST_DAYS ?> days<br><span class="small muted">You'll stay signed in on this device for <?= \Eshcol\Ats\Auth::TRUST_DAYS ?> days, even after closing the browser, then sign in again with your password and code. Don't tick this on a shared computer.</span></span></label>
  <button class="btn" type="submit" style="width:100%">Sign in</button>
</form>
<p class="small" style="margin:18px 0 0"><a href="/login">Start again</a></p>
