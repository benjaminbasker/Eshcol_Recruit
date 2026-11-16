<p class="tag">Error <?= e($code) ?></p>
<h1><?= $code === 404 ? 'Page not found' : 'Something went wrong' ?></h1>
<p><?= e($message) ?></p>
<p><a class="btn" href="/">Back to the dashboard</a></p>
