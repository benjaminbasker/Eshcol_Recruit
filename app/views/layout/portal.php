<?php
/** The client portal's page frame (restyled 20 Nov 2026: always the Eshcol brand colours). @var string $content @var ?array $me the signed-in contact, or null */
$flashes = \Eshcol\Ats\Http::takeFlash();
$here = \Eshcol\Ats\Http::path();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#320A67">
<title><?= e($title ?? 'Client portal') ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('apple-touch-icon.png') ?>">
</head>
<body class="public portal">
<?php require __DIR__ . '/../partials/icons.php'; ?>
<?php if (!empty($me['preview_by'])): ?>
<div class="portal-preview" role="note"><b>Preview</b> You are seeing the portal exactly as <?= e($me['name']) ?> at <?= e($me['client']) ?> sees it. Nothing you press here is saved. This look ends in 2 hours, or <form method="post" action="/portal/out" style="display:inline"><?= csrf() ?><button type="submit">close it now</button></form>.</div>
<?php endif; ?>
<header class="portal-head">
  <div class="portal-head-in">
    <a class="portal-brand" href="<?= !empty($me) ? '/portal' : 'https://www.eshcolglobal.com/' ?>"><img src="<?= asset('logo.webp') ?>" width="122" height="60" alt="Eshcol Global: People, Property"></a>
    <?php if (!empty($me)): ?>
      <?php if (!empty($me['logo_path'])): ?><span class="portal-for"><span>for</span><img src="/portal/logo?v=<?= e(substr(md5((string) ($me['logo_updated_at'] ?? '')), 0, 8)) ?>" alt="<?= e($me['client']) ?>"></span><?php else: ?><span class="portal-for"><span>for</span><b><?= e($me['client']) ?></b></span><?php endif; ?>
      <nav class="portal-nav" aria-label="Client portal">
        <a href="/portal"<?= $here === '/portal' || str_starts_with($here, '/portal/roles') ? ' aria-current="page"' : '' ?>>Your positions</a>
        <a href="/portal/new"<?= $here === '/portal/new' ? ' aria-current="page"' : '' ?>>Ask for a new position</a>
        <span class="portal-me"><span class="avatar sm" style="--h:<?= avatar_hue($me['name'] ?? '') ?>"><?= e(initials($me['name'] ?? '')) ?></span><span class="hide-sm"><?= e($me['name']) ?></span></span>
        <form method="post" action="/portal/out"><?= csrf() ?><button type="submit" class="btn ghost small">Sign out</button></form>
      </nav>
    <?php endif; ?>
  </div>
</header>
<main class="pub-main portal-main">
<?php foreach ($flashes as [$type, $msg]): ?><div class="flash <?= e($type) ?>" role="status"><div><?= e($msg) ?></div></div><?php endforeach; ?>
<?= $content ?>
</main>
<footer class="pub-foot">Eshcol Global Services Private Limited · Chennai · Candidate profiles are shared in confidence · <a href="https://www.eshcolglobal.com/privacy/">Privacy</a></footer>
</body>
</html>
