<?php
/**
 * The candidate's category card (13 Nov 2026): field of work, seniority, where they'd work, with the
 * evidence; "Looks right", "Correct it", and the history.
 * @var array $c @var ?array $category @var array $categoryHistory @var string $categoryQueued @var string $catMode
 */
$app = \Eshcol\Ats\App::get();
$fns = \Eshcol\Ats\Services\Categoriser::functions($app);
$fn = fn (?string $code) => \Eshcol\Ats\Services\Categoriser::functionName($app, $code);
$sen = \Eshcol\Ats\Services\Categoriser::SENIORITY;
$k = $category;
$where = function (?array $k): string {
    if (!$k) {
        return '';
    }
    $bits = [];
    if ($k['preferred_cities']) {
        $bits[] = 'prefers ' . $k['preferred_cities'];
    }
    $bits[] = match ($k['relocation']) { 'yes' => 'open to relocating', 'no' => 'not willing to relocate', default => 'relocation not stated (needs clarification)' };
    if ($k['remote_ok'] === 'yes') {
        $bits[] = 'open to remote work';
    }
    return implode(' · ', $bits);
};
$evidence = $k ? (json_decode((string) $k['evidence'], true) ?: []) : [];
// Opened from the list of unsure categories (Settings › Categories): keep the way back, and the next one.
$catFrom = $catFrom ?? false;
$backField = $catFrom ? '<input type="hidden" name="back" value="categories">' : '';
$stillUnsure = $k && $k['needs_review'] && !$k['reviewed_at'];
?>
<div class="card<?= $catFrom ? ' accent' : '' ?>" id="category">
  <h3><?= icon('briefcase', 'sm') ?> Category</h3>
  <?php if ($catFrom): ?>
    <div class="card sand" style="padding:10px 12px;margin:0 0 10px">
      <p class="small" style="margin:0 0 8px"><?= $stillUnsure ? 'Press <b>Looks right</b> or <b>Correct it</b> below; it then leaves the list.' : '<b>Done:</b> this one is off the list.' ?>
        <?= ($catLeft ?? 0) ? (int) $catLeft . ' left to check.' : 'That was the last one.' ?></p>
      <div class="actions" style="margin:0">
        <a class="btn small<?= $stillUnsure ? ' ghost' : '' ?>" href="/settings/categories#check">← Back to the list</a>
        <?php if (!empty($catNext)): ?><a class="btn ghost small" href="/candidates/<?= (int) $catNext['candidate_id'] ?>?from=categories#category">Next: <?= e($catNext['full_name'] ?: 'candidate') ?> →</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  <?php if (!$k): ?>
    <p class="small muted" style="margin:0 0 8px"><?= $categoryQueued === 'pending' || $categoryQueued === 'working' ? 'Waiting to be categorised (within five minutes of Claude reading the CV).' : 'Not categorised yet.' ?></p>
  <?php else: ?>
    <p style="margin:0 0 4px"><b><?= e($fn($k['function_code'])) ?></b><?= $k['secondary_code'] ? ' <span class="muted">+ ' . e($fn($k['secondary_code'])) . '</span>' : '' ?></p>
    <p class="small" style="margin:0 0 6px"><?= e(implode(' · ', array_filter([$k['specialisation'], $k['seniority'] ? ($sen[$k['seniority']] ?? '') : null, $k['employment_status']]))) ?></p>
    <p class="small" style="margin:0 0 6px"><span class="muted">Lives in</span> <?= e($c['location'] ?: 'not known') ?><br><span class="muted">Would work</span> <?= e($where($k)) ?></p>
    <?php if ($k['original_title']): ?><p class="small" style="margin:0 0 6px"><span class="muted">Title on the CV</span> <?= e($k['original_title']) ?><?= $k['normalised_title'] && $k['normalised_title'] !== $k['original_title'] ? ' <span class="muted">(' . e($k['normalised_title']) . ')</span>' : '' ?></p><?php endif; ?>
    <p class="small muted" style="margin:0 0 8px"><?= match ($k['source']) { 'recruiter' => 'Set by you' . ($k['reviewer'] ? ' (' . e($k['reviewer']) . ')' : ''), 'free' => 'Guessed from the job title (free)', default => 'Claude, ' . e($k['confidence']) . ' confidence' }
        ?><?= $k['source'] === 'ai' && $k['reviewed_at'] ? ' · checked by you' : '' ?><?= $k['needs_review'] && !$k['reviewed_at'] ? ' · <b class="err">please check</b>' : '' ?></p>
    <?php if ($evidence): ?><details class="small"><summary>Why</summary><ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($evidence as $q): ?><li><?= e((string) $q) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  <?php endif; ?>
  <div class="actions" style="margin-top:8px">
    <?php if ($k && $k['source'] === 'ai' && !$k['reviewed_at']): ?>
      <form method="post" action="/candidates/<?= (int) $c['id'] ?>/category/confirm" style="margin:0"><?= csrf() ?><?= $backField ?><button class="btn ghost small" type="submit">Looks right</button></form>
    <?php endif; ?>
    <?php if ($catMode !== 'off' || !$k): ?>
      <form method="post" action="/candidates/<?= (int) $c['id'] ?>/category/now" style="margin:0"><?= csrf() ?><?= $backField ?><button class="btn ghost small" type="submit"><?= $k ? 'Categorise again' : 'Categorise now' ?></button></form>
    <?php endif; ?>
  </div>
  <details style="margin-top:8px"<?= $k && $k['needs_review'] && !$k['reviewed_at'] ? ' open' : '' ?>><summary class="small"><?= $k ? 'Correct it' : 'Set it yourself' ?></summary>
    <form method="post" action="/candidates/<?= (int) $c['id'] ?>/category" style="margin-top:8px"><?= csrf() ?><?= $backField ?>
      <div class="field"><label for="cat_fn">Field of work</label><select id="cat_fn" name="function" required><option value="">Choose…</option>
        <?php foreach ($fns as $code => $f): ?><option value="<?= e($code) ?>"<?= ($k['function_code'] ?? '') === $code ? ' selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="cat_sec">Second field <span class="hint">only if they've really worked in it</span></label><select id="cat_sec" name="secondary"><option value="">None</option>
        <?php foreach ($fns as $code => $f): ?><option value="<?= e($code) ?>"<?= ($k['secondary_code'] ?? '') === $code ? ' selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="cat_spec">Specialisation</label><input type="text" id="cat_spec" name="specialisation" maxlength="160" value="<?= e($k['specialisation'] ?? '') ?>" placeholder="e.g. GST and TDS compliance"></div>
      <div class="field"><label for="cat_sen">Seniority</label><select id="cat_sen" name="seniority"><option value="">Not sure</option>
        <?php foreach ($sen as $sk => $sl): ?><option value="<?= $sk ?>"<?= ($k['seniority'] ?? '') === $sk ? ' selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="cat_pref">Cities they'd work in <span class="hint">as they told us; comma between</span></label><input type="text" id="cat_pref" name="preferred_cities" maxlength="255" value="<?= e($k['preferred_cities'] ?? '') ?>" placeholder="e.g. Pune, Mumbai"></div>
      <div class="row">
        <div class="field"><label for="cat_rel">Relocating</label><select id="cat_rel" name="relocation"><?php foreach (['unknown' => 'Not stated', 'yes' => 'Open to it', 'no' => 'Not willing'] as $rk => $rl): ?><option value="<?= $rk ?>"<?= ($k['relocation'] ?? 'unknown') === $rk ? ' selected' : '' ?>><?= $rl ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="cat_rem">Remote</label><select id="cat_rem" name="remote_ok"><?php foreach (['unknown' => 'Not stated', 'yes' => 'Open to it', 'no' => 'No'] as $rk => $rl): ?><option value="<?= $rk ?>"<?= ($k['remote_ok'] ?? 'unknown') === $rk ? ' selected' : '' ?>><?= $rl ?></option><?php endforeach; ?></select></div>
      </div>
      <button class="btn small" type="submit">Save the category</button>
      <p class="hint">Yours stays until a new CV arrives; where they said they'd work stays even then.</p>
    </form>
  </details>
  <?php if (count($categoryHistory) > 1): ?>
    <details style="margin-top:6px"><summary class="small">History (<?= count($categoryHistory) ?>)</summary>
      <ul class="small" style="margin:6px 0 0;padding-left:18px"><?php foreach ($categoryHistory as $h): ?>
        <li><?= e(nice_date($h['created_at'])) ?>: <?= e($fn($h['function_code'])) ?><?= $h['seniority'] ? ', ' . e($sen[$h['seniority']] ?? '') : '' ?> <span class="muted">(<?= e($h['source'] === 'recruiter' ? ($h['who'] ?: 'you') : ($h['source'] === 'free' ? 'free guess' : 'Claude')) ?><?= $h['is_current'] ? ', current' : '' ?>)</span></li>
      <?php endforeach; ?></ul></details>
  <?php endif; ?>
</div>
