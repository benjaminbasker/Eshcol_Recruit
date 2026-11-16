<?php /** @var string $content */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Eshcol Recruit</title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('apple-touch-icon.png') ?>">
</head>
<body>
<div class="auth">
  <div class="box">
    <img src="<?= asset('logo.webp') ?>" alt="Eshcol Global" style="height:46px;width:auto;display:block;margin:0 0 20px">
    <?php foreach (\Eshcol\Ats\Http::takeFlash() as [$type, $msg]): ?>
      <div class="flash <?= e($type) ?>" role="status"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </div>
</div>
</body>
</html>
