<?php /** @var string $token @var string $first @var ?string $error */ ?>
<section class="pub-card">
  <p class="eyebrow">Share your updated CV</p>
  <h1><?= $first !== '' ? 'Thank you, ' . e($first) . '.' : 'Thank you.' ?></h1>
  <p>Upload your updated CV, then fill in a few details on the next page: your current and expected CTC, how soon you could join, and the city and locality you live in. About 3 minutes in all.</p>
  <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/u/<?= e($token) ?>" enctype="multipart/form-data"><?= csrf() ?>
    <fieldset class="pub-q">
      <legend><label for="cv">Your updated CV <span class="pub-req" aria-hidden="true">*</span></label></legend>
      <p class="hint">PDF or Word, up to 10 MB. On a phone, choose it from your files or downloads.</p>
      <input class="pub-file" type="file" id="cv" name="cv" accept=".pdf,.doc,.docx,.rtf,.odt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
    </fieldset>
    <button class="btn pub-submit" type="submit">Upload and continue</button>
  </form>
  <p class="small muted" style="margin-top:18px">You can also simply reply to our email with your CV attached.</p>
  <div class="pub-others">
    <p><b>Not looking right now?</b> <a href="/u/<?= e($token) ?>/keep">Keep me for the future</a></p>
    <p><b>No longer interested?</b> <a href="/u/<?= e($token) ?>/remove">Remove my details</a></p>
  </div>
  <?php include __DIR__ . '/_linkedin.php'; ?>
</section>
