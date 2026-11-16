<p class="tag">First-time setup</p>
<h1>Set up Eshcol Recruit</h1>
<p class="muted small">This creates the database and your owner account. It only works once, and needs the setup code from your private config file.</p>
<?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/setup">
  <?= csrf() ?>
  <div class="field"><label for="token">Setup code <span class="hint">setup_token in eshcol-ats-config.php</span></label><input type="text" id="token" name="token" required autocomplete="off"></div>
  <div class="field"><label for="name">Your name</label><input type="text" id="name" name="name" value="<?= e($old['name'] ?? '') ?>" required></div>
  <div class="field"><label for="email">Your email</label><input type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" required autocomplete="username"></div>
  <div class="field"><label for="password">Password <span class="hint">at least 12 characters</span></label><input type="password" id="password" name="password" required minlength="12" autocomplete="new-password"></div>
  <div class="field"><label for="confirm">Password again</label><input type="password" id="confirm" name="confirm" required minlength="12" autocomplete="new-password"></div>
  <button class="btn" type="submit" style="width:100%">Set up</button>
</form>
