<?php /** @var string $token @var string $first @var ?string $error */ ?>
<section class="pub-card">
  <p class="eyebrow">Keep me for the future</p>
  <h1><?= $first !== '' ? 'Thank you, ' . e($first) . '.' : 'Thank you.' ?></h1>
  <p>We will keep your details and consider you when a suitable requirement comes up with one of our clients. If you have an updated CV, please add it: it helps us match you to the right opening.</p>
  <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/u/<?= e($token) ?>/keep" enctype="multipart/form-data"><?= csrf() ?>
    <fieldset class="pub-q">
      <legend><label for="cv">Your updated CV <span class="muted">(optional)</span></label></legend>
      <p class="hint">PDF or Word, up to 10 MB.</p>
      <input class="pub-file" type="file" id="cv" name="cv" accept=".pdf,.doc,.docx,.rtf,.odt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    </fieldset>
    <button class="btn pub-submit" type="submit">Keep me for the future</button>
  </form>
  <p class="small muted" style="margin-top:18px">Open to a change now? <a href="/u/<?= e($token) ?>">Share your updated CV and details instead</a>.</p>
  <?php include __DIR__ . '/_linkedin.php'; ?>
</section>
