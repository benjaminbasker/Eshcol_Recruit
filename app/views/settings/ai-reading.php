<?php
use Eshcol\Ats\Services\AiReading;

$inr = fn (float $usd) => '₹' . number_format($usd * INR_PER_USD, $usd * INR_PER_USD < 10 ? 2 : 0);
$srcName = fn (string $s) => match ($s) {
    'estimated' => 'free reader, estimated', 'filename' => 'file name', 'ocr' => 'scan reader', 'ai' => 'earlier AI note', 'email' => 'email', default => 'free reader',
};
$left = $counts['pending'] + $counts['reading'];
$changes = array_sum(array_map(fn ($w) => count($w['changes']), $waiting));
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings#ai">Settings</a> › AI reading</p><h1>Claude reads every CV</h1></div></div>

<section class="card" style="max-width:900px">
  <p class="small" style="margin-top:0">Claude Sonnet reads each CV as a person would: the PDF itself, columns, tables, designed CVs and scans. In the reader test on 61 of your CVs it got
    <b>99% of details right</b> (the free reader: 65%), with no wrong names, emails or phones, at about ₹1.50 a CV. Its job list also feeds the CV report's history, gaps and short stints.</p>
  <ul class="small">
    <li><b>New CVs</b> (upload or the resume@ inbox) are read within five minutes and corrected straight away.</li>
    <li><b>Scans and photos:</b> Claude also writes out the text, so they are searched and scored like any other CV.</li>
    <li><b>Never changed:</b> what you confirmed, what a candidate gave in the screening questions, and the email address a CV arrived from.</li>
    <li><b>CVs that fit no open role</b> can be read by Claude Haiku instead, about 20 times cheaper (about ₹0.08 a CV; 96.7% right in your reader test). It never replaces a name, email or phone already there. If one later fits a role, Sonnet reads it again before Claude assesses them.</li>
  </ul>
  <?php if (!$hasKey): ?><div class="flash error">Add the Claude API key in <a href="/settings#ai">Settings › AI analysis</a> first.</div><?php endif; ?>
  <p class="small"><b>This month:</b> <?= (int) $used ?> of <?= (int) $limit ?> CV readings · about <?= $inr($cost) ?> (US$<?= number_format($cost, 2) ?>)</p>
  <p class="small muted" style="margin-top:-6px"><b>Prompt cache</b> (CV readings and assessments): Claude keeps the fixed instructions for five minutes, so repeats cost a fraction.
    <?php if ($cache['read'] || $cache['write']): ?>This month <?= number_format($cache['read']) ?> tokens came from the cache (<?= number_format($cache['write']) ?> written), saving about <?= $inr(max(0, $cache['saved'])) ?>.<?php else: ?>No readings with the cache yet this month.<?php endif; ?></p>
  <form method="post" action="/settings/ai-reading" class="row" style="align-items:flex-end"><?= csrf() ?>
    <div class="field" style="max-width:240px"><label for="air_limit">Most CV readings per month</label><input type="number" id="air_limit" name="limit" min="0" max="10000" value="<?= (int) $limit ?>"></div>
    <?php $fitSvc = new \Eshcol\Ats\Services\FitAssessor(\Eshcol\Ats\App::get()); ?>
    <div class="field" style="max-width:280px"><label for="fit_limit">Most assessments per month</label><input type="number" id="fit_limit" name="fit_limit" min="0" max="10000" value="<?= (int) $fitSvc->limit() ?>">
      <p class="hint">This month: <?= (int) $fitSvc->usedThisMonth() ?> used. About ₹0.93 each at half price.</p></div>
    <p style="margin:0 0 14px"><label style="font-weight:normal"><input type="checkbox" name="enabled" value="1"<?= $on ? ' checked' : '' ?>> Claude reads every new CV</label></p>
    <p style="margin:0 0 14px"><label style="font-weight:normal"><input type="checkbox" name="cheap" value="1"<?= $cheap ? ' checked' : '' ?>> Claude Haiku reads CVs that fit no open role</label></p>
    <p style="margin:0 0 14px"><label style="font-weight:normal"><input type="checkbox" name="batch" value="1"<?= $batch ? ' checked' : '' ?>> Claude's assessments at half price (in batches: results within the hour, usually)</label></p>
    <div class="actions" style="margin:0 0 12px"><button class="btn small" type="submit">Save</button></div>
  </form>
</section>

<?php if ($names['fixes'] || $names['unsure']): ?>
<section class="card" style="max-width:900px" id="names">
  <h2>Names to check</h2>
  <p class="small">The free reader sometimes takes a CV heading for the name ("Good Communication", "Employment History", an address). These names don't match the CV's file name, and Claude's reading has a better one. <b>Nothing changes until you press the button.</b> Names you or the candidate confirmed are never listed.</p>
  <?php if ($names['fixes']): ?>
    <form method="post" action="/settings/ai-reading/names"><?= csrf() ?>
      <div class="table-wrap"><table class="list compact">
        <thead><tr><th><input type="checkbox" checked data-check-all="fix[]" aria-label="Tick all"></th><th>Name now</th><th>Claude read</th><th>CV file</th></tr></thead>
        <tbody><?php foreach ($names['fixes'] as $f): ?>
          <tr><td><input type="checkbox" name="fix[]" value="<?= (int) $f['candidate_id'] ?>" checked aria-label="Correct <?= e($f['name']) ?>"></td>
            <td><a href="/candidates/<?= (int) $f['candidate_id'] ?>" class="text-bad"><?= e($f['name'] ?: '(no name)') ?></a></td>
            <td><b><?= e((string) $f['suggested']) ?></b></td><td class="small muted"><?= e($f['file']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <p class="actions"><button class="btn" type="submit">Correct the <?= count($names['fixes']) ?> ticked name<?= count($names['fixes']) > 1 ? 's' : '' ?></button></p>
    </form>
  <?php endif; ?>
  <?php if ($names['unsure']): ?>
    <details class="small"><summary><b><?= count($names['unsure']) ?></b> more look doubtful, with no better name from Claude: open each one and correct it under "Edit and confirm details"</summary>
      <ul style="margin:8px 0 0;padding-left:18px"><?php foreach ($names['unsure'] as $u): ?>
        <li><a href="/candidates/<?= (int) $u['candidate_id'] ?>"><?= e($u['name'] ?: '(no name)') ?></a> <span class="muted">· <?= e($u['file']) ?></span></li>
      <?php endforeach; ?></ul></details>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" style="max-width:900px">
  <h2>Re-read the CVs already here</h2>
  <p class="small">Claude reads every candidate's latest CV again, straight from the database: nothing needs uploading. <b>Nothing changes until you approve</b> the list below.</p>
  <?php if ($left): ?>
    <p class="small"><b><?= $left ?> CV(s) waiting to be read.</b> The five-minute job reads about 40 at a time, or read them now (about 3 seconds each; keep this page open).</p>
    <?php if ($hasKey): ?>
      <div class="actions"><button class="btn" type="button" id="air-run" data-url="/settings/ai-reading/step" data-left="<?= (int) $left ?>">Read them now</button>
        <button class="btn ghost small" type="button" id="air-stop" hidden>Stop</button></div>
      <p class="small" id="air-progress" role="status" aria-live="polite"></p>
    <?php endif; ?>
  <?php else: ?>
    <form method="post" action="/settings/ai-reading/queue-all"><?= csrf() ?>
      <button class="btn" type="submit"<?= $hasKey ? '' : ' disabled' ?> data-confirm-click="Have Claude read every candidate's latest CV again? About ₹1.50 a CV. Nothing changes until you approve.">Re-read all CVs with Claude</button></form>
  <?php endif; ?>
  <?php if ($failed): ?>
    <details style="margin-top:14px"><summary class="small"><b><?= count($failed) ?> CV(s) Claude could not read</b></summary>
      <ul class="small"><?php foreach ($failed as $f): ?><li><a href="/candidates/<?= (int) $f['candidate_id'] ?>"><?= ref_candidate($f['candidate_id']) ?> <?= e($f['full_name'] ?: 'Name not found') ?></a>: <?= e($f['error']) ?></li><?php endforeach; ?></ul>
      <form method="post" action="/settings/ai-reading/retry"><?= csrf() ?><button class="btn ghost small" type="submit">Try them again</button></form>
    </details>
  <?php endif; ?>
</section>

<?php if ($waiting): ?>
<section class="card" id="approve">
  <h2>Changes to approve: <?= $changes ?> for <?= count($waiting) ?> candidates</h2>
  <p class="small">Everything is ticked. Untick anything you don't want; unticked details stay as they are. "Not in the CV" means Claude found nothing there, so the free reader's guess is cleared.</p>
  <form method="post" action="/settings/ai-reading/apply"><?= csrf() ?>
    <div class="actions" style="margin-bottom:12px"><button class="btn" type="submit">Apply ticked changes</button>
      <label class="small" style="font-weight:normal"><input type="checkbox" id="air-all" checked> Tick or untick all</label></div>
    <div style="overflow-x:auto"><table class="list small">
      <thead><tr><th style="width:34px"><span class="sr-only">Apply</span></th><th>Detail</th><th>Now</th><th>Claude read</th></tr></thead>
      <?php foreach ($waiting as $w): ?>
        <tbody>
          <tr><td colspan="4" style="background:var(--bg-soft,#F6F2FB)"><input type="hidden" name="reads[]" value="<?= (int) $w['read_id'] ?>">
            <a href="/candidates/<?= (int) $w['candidate_id'] ?>"><b><span class="ref"><?= ref_candidate($w['candidate_id']) ?></span> <?= e($w['name'] ?: 'Name not found') ?></b></a></td></tr>
          <?php foreach ($w['changes'] as $k => $ch): $cbid = 'take-' . (int) $w['read_id'] . '-' . e($k); ?>
            <tr><td><input type="checkbox" class="air-take" id="<?= $cbid ?>" name="take[<?= (int) $w['read_id'] ?>][]" value="<?= e($k) ?>" checked></td>
              <td><label for="<?= $cbid ?>" style="font-weight:normal"><?= e($ch['label']) ?></label></td>
              <td><?= $ch['old'] === '' ? '<span class="muted">–</span>' : e($ch['old']) . ' <span class="muted">(' . e($srcName($ch['old_source'])) . ')</span>' ?></td>
              <td><?= $ch['new'] === '' ? '<span class="muted">not in the CV</span>' : '<b>' . e($ch['new']) . '</b>' ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      <?php endforeach; ?>
    </table></div>
    <div class="actions" style="margin-top:12px"><button class="btn" type="submit">Apply ticked changes</button></div>
  </form>
</section>
<?php elseif (!$left && $counts['failed'] === 0): ?>
<section class="card" style="max-width:900px"><p class="small" style="margin:0">Nothing waiting for approval.</p></section>
<?php endif; ?>
<script src="<?= asset('ai-reading.js') ?>"></script>
