<?php
/** @var string $content */
/** @var array $user */
$path = \Eshcol\Ats\Http::path();
$isOn = fn (string $p) => $p === '/' ? $path === '/' : str_starts_with($path, $p);
$on = fn (string $p) => $isOn($p) ? ' class="on" aria-current="page"' : '';
$flashes = \Eshcol\Ats\Http::takeFlash();
$db = \Eshcol\Ats\App::get()->db;
$heldMail = (int) $db->col("SELECT COUNT(*) FROM email_queue WHERE status = 'held'");
$openRoles = (int) $db->col("SELECT COUNT(*) FROM roles r WHERE r.status = 'open' AND r.is_pool = 0 AND " . \Eshcol\Ats\Access::roleFilter($user ?? null, 'r.id'));
$unreadMsgs = (int) $db->col('SELECT COUNT(DISTINCT candidate_id) FROM candidate_messages WHERE read_at IS NULL');
$isOwner = ($user['role'] ?? '') === 'owner';
$isContractor = ($user['role'] ?? '') === 'contractor'; // sees only their own positions (B6, 14 Nov 2026)
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(\Eshcol\Ats\Http::csrfToken()) ?>">
<meta name="theme-color" content="#2C0A57">
<title><?= e(($title ?? '') ? $title . ' · ' : '') ?>Eshcol Recruit</title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('apple-touch-icon.png') ?>">
</head>
<body>
<?php require __DIR__ . '/../partials/icons.php'; ?>
<a href="#content" class="skip">Skip to content</a>
<div class="shell" id="shell">
  <aside class="side" id="side" aria-label="Main">
    <a class="brand" href="/" aria-label="Eshcol Recruit: My day"><span class="mark"><img src="<?= asset('eshcol-mark.png') ?>" width="28" height="28" alt=""></span><div><b>eshcol</b><span>recruit</span></div></a>
    <nav class="nav">
      <?php if (!$isContractor): ?><a href="/"<?= $on('/') ?>><?= icon('home') ?>My day</a><?php endif; ?>
      <a href="/roles"<?= $on('/roles') ?>><?= icon('briefcase') ?>Roles<?= $openRoles ? '<span class="count" title="Open roles" style="background:rgba(255,255,255,.14);color:#fff">' . $openRoles . '</span>' : '' ?></a>
      <?php if (!$isContractor): ?>
      <a href="/candidates"<?= $on('/candidates') ?>><?= icon('users') ?>Candidates</a>
      <a href="/clients"<?= $on('/clients') ?>><?= icon('building') ?>Clients</a>
      <?php endif; ?>
      <div class="label">Work</div>
      <a href="/upload"<?= $on('/upload') ?>><?= icon('upload') ?>Upload CVs</a>
      <?php if (!$isContractor): ?>
      <a href="/details-requests"<?= $on('/details-requests') ?>><?= icon('check') ?>Responses</a>
      <a href="/messages"<?= $on('/messages') ?>><?= icon('chat') ?>Messages<?= $unreadMsgs ? '<span class="count" title="Candidates with new messages">' . $unreadMsgs . '</span>' : '' ?></a>
      <a href="/emails"<?= $on('/emails') ?>><?= icon('mail') ?>Emails<?= $heldMail ? '<span class="count" title="Held for you to check">' . $heldMail . '</span>' : '' ?></a>
      <?php endif; ?>
      <?php if ($isOwner): ?>
        <a href="/placements"<?= $on('/placements') ?>><?= icon('check') ?>Placements</a>
        <?php if ($isOwner): $joinWait = (int) $db->col("SELECT COUNT(*) FROM joinings WHERE status = 'ready'"); ?><a href="/joinings"<?= $on('/joinings') ?>><?= icon('users') ?>Joinings<?= $joinWait ? ' <span class="count">' . $joinWait . '</span>' : '' ?></a><?php endif; ?>
        <a href="/reports"<?= $on('/reports') ?>><?= icon('list') ?>Reports</a>
        <a href="/settings"<?= $on('/settings') ?>><?= icon('sliders') ?>Settings</a>
      <?php endif; ?>
    </nav>
    <div class="who">
      <span class="avatar sm" style="--h:<?= avatar_hue($user['name'] ?? '') ?>"><?= e(initials($user['name'] ?? '')) ?></span>
      <div><a href="/account"><?= e($user['name'] ?? '') ?></a><small><?= e($user['title'] ?? '') ?: ($isOwner ? 'Owner' : ($isContractor ? 'Contractor' : 'Recruiter')) ?></small></div>
      <form method="post" action="/logout"><?= csrf() ?><button type="submit" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></button></form>
    </div>
  </aside>
  <div class="scrim" data-nav-close></div>
  <main class="main">
    <header class="top">
      <button class="btn ghost icon menu-btn" type="button" data-nav-open aria-label="Open menu"><?= icon('menu') ?></button>
      <button class="cmdk-trigger" type="button" data-cmdk-open aria-label="Search or jump to (Ctrl+K)">
        <?= icon('search') ?><span>Search candidates, roles, clients, or a reference</span><kbd>Ctrl K</kbd>
      </button>
      <span class="spacer"></span>
      <?php if ($isContractor): ?>
      <a class="btn gold" href="/upload"><?= icon('upload') ?><span class="hide-sm">Upload CVs</span></a>
      <?php else: ?>
      <details class="menu">
        <summary class="btn gold"><?= icon('plus') ?><span class="hide-sm">New</span></summary>
        <div class="menu-list">
          <a href="/upload"><?= icon('upload') ?><div>Upload CVs<small>PDF, Word or a scan; we read them</small></div></a>
          <a href="/roles/new"><?= icon('briefcase') ?><div>New role<small>Paste a JD and we fill it in</small></div></a>
          <a href="/clients/new"><?= icon('building') ?><div>New client</div></a>
        </div>
      </details>
      <?php endif; ?>
    </header>
    <div class="page" id="content" tabindex="-1">
      <?php foreach ($flashes as [$type, $msg]): ?>
        <div class="flash <?= e($type) ?>" role="status"><?= icon($type === 'error' ? 'alert' : ($type === 'ok' ? 'check' : 'bell'), 'sm') ?><div><?= e($msg) ?></div></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </main>
</div>
<div class="cmdk" id="cmdk" role="dialog" aria-modal="true" aria-label="Search">
  <div class="cmdk-box">
    <div class="cmdk-input"><?= icon('search') ?><input type="text" id="cmdk-q" placeholder="Type a name, skill, role, client, or EG-10042" autocomplete="off" aria-controls="cmdk-results"></div>
    <div class="cmdk-results" id="cmdk-results" role="listbox"></div>
    <div class="cmdk-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></div>
  </div>
</div>
<script src="<?= asset('app.js') ?>"></script>
</body>
</html>
