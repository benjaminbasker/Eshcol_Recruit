<div class="page-head"><div><p class="eyebrow"><?= e($user['email']) ?></p><h1>Your account</h1></div></div>
<?php $me = $me ?? \Eshcol\Ats\App::get()->db->one('SELECT name, title, mobile, email FROM users WHERE id = ?', [$user['id']]); ?>
<?php $myTheme = user_theme($user); ?>
<form method="post" action="/account/theme" class="card" id="colours" style="max-width:860px">
  <?= csrf() ?>
  <h2>Colours</h2>
  <p class="small">Choose how Eshcol Recruit looks for you. Only you see your choice: everyone else keeps their own. Stage tags and green / amber / red keep the same meaning in every theme, and what clients and candidates see always stays in the Eshcol brand colours.</p>
  <div class="theme-pick" role="radiogroup" aria-label="Colour theme">
    <?php foreach (themes() as $key => [$label, $desc, $sw]): ?>
      <label class="theme-opt">
        <input type="radio" name="theme" value="<?= e($key) ?>"<?= $key === $myTheme ? ' checked' : '' ?>>
        <span class="theme-sw" aria-hidden="true"><?php foreach ($sw as $c): ?><i style="background:<?= e($c) ?>"></i><?php endforeach; ?></span>
        <b><?= e($label) ?><?= $key === 'eshcol' ? ' <span class="chip">Default</span>' : '' ?></b>
        <small><?= e($desc) ?></small>
      </label>
    <?php endforeach; ?>
  </div>
  <button class="btn" type="submit" style="margin-top:14px">Use these colours</button>
</form>
<form method="post" action="/account/profile" class="card" style="max-width:520px">
  <?= csrf() ?>
  <h2>Your signature</h2>
  <p class="small">Used at the end of emails to clients and candidates, on the cover page of every CV you send, and in call letters.</p>
  <div class="field"><label for="p_name">Name</label><input type="text" id="p_name" name="name" value="<?= e($me['name']) ?>" required></div>
  <div class="field"><label for="p_title">Title</label><input type="text" id="p_title" name="title" value="<?= e((string) $me['title']) ?>" placeholder="e.g. Managing Director"></div>
  <div class="field"><label for="p_mobile">Mobile</label><input type="text" id="p_mobile" name="mobile" value="<?= e((string) $me['mobile']) ?>" placeholder="+91 98765 43210"></div>
  <div class="sig-preview small"><?php foreach (signature_lines($me) as $i => $l): ?><?= $i === 0 ? '<b>' . e($l) . '</b>' : e($l) ?><br><?php endforeach; ?></div>
  <button class="btn" type="submit">Save</button>
</form>
<form method="post" action="/account/email" class="card" style="max-width:520px">
  <?= csrf() ?>
  <h2>Sign-in email</h2>
  <p class="small">You sign in with <b><?= e($user['email']) ?></b>. Test emails from Settings › Email go here too.</p>
  <?php if (!empty($emailError)): ?><div class="flash error" role="alert"><?= e($emailError) ?></div><?php endif; ?>
  <div class="field"><label for="new_email">New email address</label><input type="email" id="new_email" name="email" required autocomplete="email"></div>
  <div class="field"><label for="current_e">Current password</label><input type="password" id="current_e" name="current" required autocomplete="current-password"></div>
  <button class="btn" type="submit">Change email</button>
  <p class="hint" style="margin-top:8px">Your password and authenticator app stay the same.</p>
</form>
<form method="post" action="/account/password" class="card" style="max-width:520px">
  <?= csrf() ?>
  <h2>Change password</h2>
  <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <div class="field"><label for="current">Current password</label><input type="password" id="current" name="current" required autocomplete="current-password"></div>
  <div class="field"><label for="new">New password <span class="hint">at least 12 characters</span></label><input type="password" id="new" name="new" required minlength="12" autocomplete="new-password"></div>
  <div class="field"><label for="confirm">New password again</label><input type="password" id="confirm" name="confirm" required minlength="12" autocomplete="new-password"></div>
  <button class="btn" type="submit">Change password</button>
</form>
<form method="post" action="/account/devices/forget" class="card" style="max-width:520px">
  <?= csrf() ?>
  <h2>Trusted devices</h2>
  <p class="small muted" style="margin-top:0">Browsers where you ticked "Trust this device" at sign-in. A sign-in there lasts <?= \Eshcol\Ats\Auth::TRUST_DAYS ?> days from when you ticked the box, with no password or code; after that, both again. Forget them below if a device is lost.</p>
  <?php if (empty($devices)): ?>
    <p class="small" style="margin:0">None right now.</p>
  <?php else: ?>
    <ul class="small" style="margin:0 0 12px;padding-left:18px">
      <?php foreach ($devices as $d): ?>
        <li><b><?= e($d['label'] ?: 'A browser') ?></b>: trusted <?= e(nice_date($d['created_at'], true)) ?>, until <?= e(nice_date($d['expires_at'])) ?><?= $d['last_used_at'] ? ', last used ' . e(nice_date($d['last_used_at'], true)) : '' ?></li>
      <?php endforeach; ?>
    </ul>
    <button class="btn ghost" type="submit" data-confirm-click="Ask for the code again on all these devices?">Forget all trusted devices</button>
  <?php endif; ?>
</form>
