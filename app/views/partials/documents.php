<?php
/** @var string $ownerType 'client'|'role' @var int $ownerId @var array $docs @var array $kinds kinds offered here */
$labels = \Eshcol\Ats\Services\Documents::KINDS;
?>
<div class="card" id="documents">
  <h3>Files for letters and shortlists</h3>
  <?php if (!$docs): ?><p class="small muted">None yet. <?= $ownerType === 'client' ? 'The company profile is attached to in-person interview letters.' : 'The JD is attached to every interview letter.' ?></p><?php endif; ?>
  <?php foreach ($docs as $d): ?>
    <div class="doc-row">
      <span class="doc-ico"><?= strtoupper(pathinfo($d['original_name'], PATHINFO_EXTENSION)) ?></span>
      <div><a href="/documents/<?= (int) $d['id'] ?>" target="_blank"><?= e($d['original_name']) ?></a>
        <br><span class="small muted"><?= e($labels[$d['kind']] ?? '') ?> · <?= number_format($d['size_bytes'] / 1024) ?> KB · <?= e(nice_date($d['created_at'])) ?></span></div>
      <form method="post" action="/documents/<?= (int) $d['id'] ?>/delete" style="margin-left:auto"><?= csrf() ?><button class="btn link small" type="submit" data-confirm-click="Remove this file?">Remove</button></form>
    </div>
  <?php endforeach; ?>
  <form method="post" action="/documents" enctype="multipart/form-data" class="doc-upload"><?= csrf() ?>
    <input type="hidden" name="owner_type" value="<?= e($ownerType) ?>"><input type="hidden" name="owner_id" value="<?= (int) $ownerId ?>">
    <select name="kind" aria-label="What kind of file"><?php foreach ($kinds as $k): ?><option value="<?= $k ?>"><?= e($labels[$k]) ?></option><?php endforeach; ?></select>
    <input type="file" name="file" accept=".pdf,.doc,.docx" required aria-label="File">
    <button class="btn small" type="submit">Upload</button>
  </form>
  <p class="hint" style="margin:6px 0 0">PDF or Word, up to 10 MB. A new file replaces the old one of the same kind.</p>
</div>
