<?php /** @var string $content */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($title ?? 'Eshcol Global') ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('apple-touch-icon.png') ?>">
</head>
<body class="public">
<header class="pub-head"><a href="https://www.eshcolglobal.com/"><img src="<?= asset('logo.webp') ?>" width="136" height="67" alt="Eshcol Global: People, Property"></a></header>
<main class="pub-main">
<?= $content ?>
</main>
<footer class="pub-foot">Eshcol Global Services Private Limited · Chennai · <a href="https://www.eshcolglobal.com/privacy/">How we use your details</a></footer>
</body>
</html>
