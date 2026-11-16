<?php
/** The client portal's page frame. @var string $content @var ?array $me the signed-in contact, or null */
$flashes = \Eshcol\Ats\Http::takeFlash();
$here = \Eshcol\Ats\Http::path();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($title ?? 'Client portal') ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('apple-touch-icon.png') ?>">
</head>
<body class="public portal">
<header class="pub-head portal-head">
  <a href="<?= !empty($me) ? '/portal' : 'https://www.eshcolglobal.com/' ?>"><img src="<?= asset('logo.webp') ?>" width="136" height="67" alt="Eshcol Global: People, Property"></a>
  <?php if (!empty($me)): ?>
    <nav class="portal-nav" aria-label="Client portal">
      <a href="/portal"<?= $here === '/portal' ? ' aria-current="page"' : '' ?>>Your positions</a>
      <a href="/portal/new"<?= $here === '/portal/new' ? ' aria-current="page"' : '' ?>>Ask for a new position</a>
      <form method="post" action="/portal/out"><?= csrf() ?><button type="submit" class="btn link small">Sign out</button></form>
    </nav>
  <?php endif; ?>
</header>
<main class="pub-main portal-main">
<?php if (!empty($me)): ?><p class="portal-who small muted"><?= e($me['client']) ?> · signed in as <?= e($me['name']) ?></p><?php endif; ?>
<?php foreach ($flashes as [$type, $msg]): ?><div class="flash <?= e($type) ?>" role="status"><div><?= e($msg) ?></div></div><?php endforeach; ?>
<?= $content ?>
</main>
<footer class="pub-foot">Eshcol Global Services Private Limited · Chennai · Candidate profiles are shared in confidence · <a href="https://www.eshcolglobal.com/privacy/">Privacy</a></footer>
</body>
</html>
