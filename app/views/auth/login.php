<p class="tag">Eshcol Recruit</p>
<h1>Sign in</h1>
<?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/login" autocomplete="on">
  <?= csrf() ?>
  <div class="field">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
  </div>
  <button class="btn" type="submit" style="width:100%">Continue</button>
</form>
<p class="muted small" style="margin:18px 0 0">You will be asked for a code from your authenticator app next.</p>
