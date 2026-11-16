<?php /** @var string[] $pending @var bool $owner */ ?>
<p class="eyebrow">Eshcol Recruit</p>
<h1>Updates waiting</h1>
<p>A new version of the app has been installed. It needs <?= count($pending) === 1 ? 'one small change' : count($pending) . ' small changes' ?> to the database before it can be used:</p>
<ul class="small"><?php foreach ($pending as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
<?php if ($owner): ?>
  <?php if (!empty($_SESSION['offer_without_copy'])): ?>
    <p style="color:#B23A48"><b>Nothing was applied:</b> the safety copy of the database could not be made just now. Try again in a minute. If it keeps failing and last night's backup is recent, you can apply without a fresh copy.</p>
  <?php endif; ?>
  <form method="post" action="/settings/migrate"><?= csrf() ?>
    <button class="btn" type="submit">Apply updates</button>
    <?php if (!empty($_SESSION['offer_without_copy'])): ?><button class="btn ghost" type="submit" name="without_copy" value="1">Apply without a copy</button><?php endif; ?>
  </form>
  <p class="hint" style="margin-top:12px">Takes a few seconds. A copy of the database is saved first, so an update can always be undone. Nothing is deleted.</p>
<?php else: ?>
  <p>Please ask the owner to sign in and apply them. It takes a few seconds.</p>
<?php endif; ?>
<form method="post" action="/logout" style="margin-top:16px"><?= csrf() ?><button class="btn ghost small" type="submit">Sign out</button></form>
