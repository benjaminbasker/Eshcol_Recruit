<p class="tag">One-time setup</p>
<h1>Protect your account</h1>
<p class="muted small">This app holds candidates' CVs and salaries, so signing in needs your password and a code from your phone.</p>
<ol class="small" style="padding-left:18px">
  <li>Install <b>Microsoft Authenticator</b> or <b>Google Authenticator</b> on your phone.</li>
  <li>In the app, add an account and scan this code.</li>
  <li>Type the 6-digit code it shows below.</li>
</ol>
<img class="qr" src="<?= e($qr) ?>" alt="QR code for setting up the authenticator app">
<p class="small muted" style="text-align:center">Can't scan? Enter this key instead:<br><span class="secret"><?= e($secret) ?></span></p>
<?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/login/setup">
  <?= csrf() ?>
  <div class="field">
    <label for="code">6-digit code from the app</label>
    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code" style="font-size:22px;letter-spacing:6px;text-align:center">
  </div>
  <button class="btn" type="submit" style="width:100%">Turn on and sign in</button>
</form>
