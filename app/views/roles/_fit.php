<?php
/** The role's Claude tab. Needs $role, $fit, $all (applications on the role). */
use Eshcol\Ats\Services\FitAssessor;

$rid = (int) $role['id'];
$byApp = [];
foreach ($all as $a) { $byApp[(int) $a['id']] = $a; }
$rows = $fit['rows'];
$done = array_filter($rows, fn ($f) => $f['status'] === 'done' && isset($byApp[(int) $f['application_id']]));
uasort($done, fn ($x, $y) => [$y['score'], $x['id']] <=> [$x['score'], $y['id']]);
$waiting = count(array_filter($rows, fn ($f) => in_array($f['status'], ['pending', 'reading'], true)));
$failed = array_filter($rows, fn ($f) => $f['status'] === 'failed');
$batched = count(array_filter($rows, fn ($f) => $f['status'] === 'batched'));
$skipped = array_filter($rows, fn ($f) => $f['status'] === 'skipped' && isset($byApp[(int) $f['application_id']]));
$batchOn = \Eshcol\Ats\App::get()->setting('fit_batch', '0') === '1';
$notRel = array_filter($fit['notRelevant'], fn ($w) => $w !== '');
$relevantNew = count(array_filter($fit['notRelevant'], fn ($w) => $w === ''));
$bandTone = ['Strong' => 'yes', 'Possible' => 'sand', 'Weak' => 'no'];
// proven / partly / related / not mentioned / contradicted (old words map across). Its own name: _ready.php, included
// below, sets its own $mark, and sharing the name showed every requirement mark in the ranking as "?" (10-16 Nov 2026).
$verdictMark = \Eshcol\Ats\Services\FitAssessor::VERDICTS;
$inr = fn (float $usd) => '₹' . number_format($usd * INR_PER_USD);
$reqs = $fit['reqs'];
$ladder = $fit['ladder'];
// The same key details on every list (16 Nov 2026).
$fitKf = (new \Eshcol\Ats\Services\KeyFacts(\Eshcol\Ats\App::get()))->forCandidates(array_map(fn ($f) => (int) ($byApp[(int) $f['application_id']]['candidate_id'] ?? 0), $done));
// The extra analysers (6, 5b, 7) are hidden unless switched on (Benjamin, 16 Nov 2026): see Agreement::extrasOn.
$extras = \Eshcol\Ats\Services\Agreement::extrasOn(\Eshcol\Ats\App::get());
$exIds = !$extras ? [] : array_flip(array_map('intval', array_column(\Eshcol\Ats\App::get()->db->all('SELECT candidate_id FROM role_examples WHERE role_id = ?', [$rid]), 'candidate_id')));
?>
<div class="card">
  <p class="small" style="margin:0 0 8px">The free check sorts the CVs first: only candidates in the same field (at least half the must-have keywords) with experience inside this role's allowance go to Claude.
    Claude then assesses each against the requirements below, quoting the CV, and the app scores the answers:
    <b>must-haves 40, relevant experience 30, seniority 10, qualification 10, stability and fit 10.</b> Commute, notice and salary are shown but not scored.
    <b>Strong</b> 75+, <b>Possible</b> 60–74, <b>Weak</b> below 60; a critical requirement not shown or contradicted makes it Weak. Each requirement is marked <b>proven</b> (full credit), <b>partly proven</b> or <b>related, check on the call</b> (half), <b>not mentioned</b> or <b>contradicted</b> (none), with the line from the CV. It only helps you sort: you decide.</p>
  <p class="small muted" style="margin:0">This month: <?= (int) $fit['used'] ?> of <?= (int) $fit['limit'] ?> assessments · about <?= $inr($fit['cost']) ?></p>
  <?php if (!$fit['on']): ?><p class="small" style="margin:10px 0 0;color:var(--red)"><b>Claude assessment is off</b>, or there is no Claude API key (Settings › AI analysis).</p><?php endif; ?>
  <div class="actions" style="margin-top:12px">
    <?php if ($waiting): ?>
      <button class="btn" type="button" id="fit-run" data-url="/roles/<?= $rid ?>/fit/step" data-left="<?= $waiting ?>"<?= $fit['on'] ? '' : ' disabled' ?>>Assess now (<?= $waiting ?> waiting)</button>
      <button class="btn ghost small" type="button" id="fit-stop" hidden>Stop</button>
    <?php endif; ?>
    <?php if ($relevantNew): ?>
      <form method="post" action="/roles/<?= $rid ?>/fit/queue"><?= csrf() ?><button class="btn<?= $waiting ? ' ghost' : '' ?>" type="submit"<?= $fit['on'] ? '' : ' disabled' ?>>Assess the <?= $relevantNew ?> relevant candidate<?= $relevantNew > 1 ? 's' : '' ?> not yet assessed</button></form>
    <?php endif; ?>
    <?php if ($done): ?>
      <form method="post" action="/roles/<?= $rid ?>/fit/queue"><?= csrf() ?><input type="hidden" name="again" value="1"><button class="btn ghost small" type="submit"<?= $fit['on'] ? '' : ' disabled' ?> data-confirm-click="Have Claude assess everyone relevant on this role again? About ₹1–2 each.">Re-assess everyone</button></form>
    <?php endif; ?>
  </div>
  <?php $batchProblem = $batchOn ? (string) \Eshcol\Ats\App::get()->setting('fit_batch_problem', '') : ''; if ($batchProblem !== ''): ?>
    <p class="small" style="margin:8px 0 0;color:var(--red)"><b>Half-price batches are not going through</b> (<?= e($batchProblem) ?>). The waiting ones stay waiting: nothing is charged, and the app tries again every five minutes. "Assess now" gives results straight away at the full price.</p>
  <?php endif; ?>
  <?php if ($batched): ?><p class="small" style="margin:8px 0 0"><span class="chip sand" style="text-decoration:none"><?= $batched ?> with Claude</span> in a half-price batch: the results usually arrive within the hour.</p><?php endif; ?>
  <?php if ($waiting && $batchOn): ?><p class="small muted" style="margin:6px 0 0">Waiting ones go to Claude at half price within five minutes. "Assess now" gives results straight away, at the full price.</p><?php endif; ?>
  <p class="small" id="fit-progress" role="status" aria-live="polite"></p>
</div>

<?php include __DIR__ . '/_ready.php'; ?>

<?php if ($done): ?>
<div class="card">
  <h2>Claude's ranking (<?= count($done) ?>)</h2>
  <div style="overflow-x:auto"><table class="list">
    <thead><tr><th>Claude</th><th>Candidate</th><th>Requirements</th><th>Free</th></tr></thead>
    <tbody>
    <?php foreach ($done as $f): $a = $byApp[(int) $f['application_id']]; $res = $f['result']; $bd = $f['breakdown']; $nm = $a['full_name'] ?: 'Name not found'; $old = $f['role_version'] !== $fit['version']; ?>
      <tr<?= \Eshcol\Ats\Services\Shortlists::isShortlisted(\Eshcol\Ats\App::get(), (int) $a['stage_id']) ? ' class="short" title="Shortlisted"' : '' ?>>
        <td style="width:92px"><span class="score <?= $f['band'] === 'Strong' ? 'score-high' : ($f['band'] === 'Possible' ? 'score-mid' : 'score-low') ?>"><?= (int) $f['score'] ?></span><br><span class="chip <?= $bandTone[(string) $f['band']] ?? '' ?>" style="text-decoration:none"><?= e($f['band']) ?></span></td>
        <td>
          <a href="/candidates/<?= (int) $a['candidate_id'] ?>?role=<?= $rid ?>"><b><?= e($nm) ?></b></a> <span class="ref"><?= ref_candidate($a['candidate_id']) ?></span> <?= \Eshcol\Ats\Services\KeyFacts::stageChip((string) $a['stage'], (string) ($a['stage_kind'] ?? '')) ?>
          <?php if ($old): ?> <span class="chip sand" title="The role's requirements changed after this assessment">role changed</span><?php endif; ?>
          <?php if ($extras): $isEx = isset($exIds[(int) $a['candidate_id']]); ?>
          <form method="post" action="/roles/<?= $rid ?>/examples/<?= (int) $a['candidate_id'] ?>" style="display:inline"><?= csrf() ?><?php if ($isEx): ?><input type="hidden" name="remove" value="1"><?php endif; ?>
            <button class="btn ghost small" type="submit" aria-pressed="<?= $isEx ? 'true' : 'false' ?>" title="<?= $isEx ? 'One of your examples for this position: press to remove' : 'Mark as an example of the person you want (at most 3)' ?>"><?= $isEx ? '★ Example' : '☆ Example' ?></button></form>
          <?php endif; ?>
          <?php $fk = $fitKf[(int) $a['candidate_id']] ?? []; if ($fk): ?><div><?= \Eshcol\Ats\Services\KeyFacts::line($fk) ?> <?= \Eshcol\Ats\Services\KeyFacts::cvButton($fk) ?></div><?php endif; ?>
          <div class="small" style="margin-top:4px"><?= e($res['summary'] ?? '') ?></div>
          <?php if ($extras && !empty($res['like_examples']) && isset(\Eshcol\Ats\Services\Examples::LIKE[$res['like_examples']])): $lk = \Eshcol\Ats\Services\Examples::LIKE[$res['like_examples']]; ?>
            <div class="small" style="margin-top:4px"><span class="chip <?= $lk[1] ?>" style="text-decoration:none"><?= e($lk[0]) ?></span> <?= e($res['like_note'] ?? '') ?></div><?php endif; ?>
          <?php if (!empty($bd['knockout'])): ?><div class="small" style="margin-top:4px"><span class="chip no" style="text-decoration:none"><?= e($bd['knockout']) ?></span></div><?php endif; ?>
          <details style="margin-top:6px"><summary class="small">Why this score</summary>
            <div style="padding-top:8px" data-why="/applications/<?= (int) $a['id'] ?>/why?kind=fit"><span class="small muted">Loading…</span></div>
          </details>
        </td>
        <td style="min-width:120px"><?php foreach ($reqs as $i => $r): $v = '';
          foreach ((array) ($res['requirements'] ?? []) as $x) { if ((int) $x['number'] === $i + 1) { $v = \Eshcol\Ats\Services\FitAssessor::verdict($x['verdict']); } }
          $m = $verdictMark[$v] ?? ['?', '', 'not assessed']; ?><span class="chip <?= $m[1] ?>" style="text-decoration:none" title="<?= e($r['text'] . ': ' . $m[2]) ?>"><?= $m[0] ?></span><?php endforeach; ?></td>
        <td class="small"><?= $a['eff_score'] ?? '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($extras) { include __DIR__ . '/_fair.php'; } ?>

<?php if ($skipped):
    $sdb = \Eshcol\Ats\App::get()->db;
    $ids = implode(',', array_map(fn ($f) => (int) $f['application_id'], $skipped));
    $other = [];
    foreach ($sdb->all("SELECT a.id, f2.status, f2.score, f2.band, r2.id AS role_id, r2.title AS role_title FROM applications a
            JOIN fit_assessments f ON f.application_id = a.id JOIN roles r2 ON r2.id = f.skipped_for
            LEFT JOIN applications a2 ON a2.candidate_id = a.candidate_id AND a2.role_id = f.skipped_for
            LEFT JOIN fit_assessments f2 ON f2.application_id = a2.id WHERE a.id IN ($ids)") as $o) {
        $other[(int) $o['id']] = $o;
    } ?>
<div class="card">
  <details><summary><b>Assessed for another role instead (<?= count($skipped) ?>)</b> <span class="small muted">one assessment per candidate per client, for the role that matches their experience best; they stay on this role</span></summary>
    <ul class="small" style="margin-top:8px"><?php foreach ($skipped as $f): $a = $byApp[(int) $f['application_id']]; $o = $other[(int) $a['id']] ?? null; ?>
      <li style="margin-bottom:6px"><a href="/candidates/<?= (int) $a['candidate_id'] ?>?role=<?= $rid ?>"><?= e($a['full_name'] ?: 'Name not found') ?></a> <span class="ref"><?= ref_candidate($a['candidate_id']) ?></span>:
        <?php if ($o): ?>assessed for <a href="/roles/<?= (int) $o['role_id'] ?>#tab-claude"><?= e($o['role_title']) ?></a><?= $o['status'] === 'done' ? ' (' . (int) $o['score'] . ', ' . e($o['band']) . ')' : ($o['status'] ? ' (on its way)' : '') ?><?php else: ?>assessed for another role<?php endif; ?>
        <form method="post" action="/applications/<?= (int) $a['id'] ?>/fit" style="display:inline"><?= csrf() ?><input type="hidden" name="back" value="/roles/<?= $rid ?>#tab-claude"><button class="btn ghost small" type="submit"<?= $fit['on'] ? '' : ' disabled' ?>>Assess for this role too</button></form></li>
    <?php endforeach; ?></ul>
  </details>
</div>
<?php endif; ?>

<?php if ($notRel || $failed): ?>
<div class="card">
  <?php if ($notRel): ?>
    <details><summary><b>Not sent to Claude (<?= count($notRel) ?>)</b> <span class="small muted">the free check found them not relevant to this role; they stay on the role</span></summary>
      <ul class="small" style="margin-top:8px"><?php foreach ($notRel as $appId => $why): $a = $byApp[$appId] ?? null; if (!$a) { continue; } ?>
        <li><a href="/candidates/<?= (int) $a['candidate_id'] ?>?role=<?= $rid ?>"><?= e($a['full_name'] ?: 'Name not found') ?></a> <span class="ref"><?= ref_candidate($a['candidate_id']) ?></span>: <?= e($why) ?></li>
      <?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
  <?php if ($failed): ?>
    <details style="margin-top:8px"><summary><b>Claude could not assess (<?= count($failed) ?>)</b></summary>
      <ul class="small" style="margin-top:8px"><?php foreach ($failed as $f): $a = $byApp[(int) $f['application_id']] ?? null; ?>
        <li style="margin-bottom:6px"><?= e($a['full_name'] ?? '') ?>: <?= e($f['error']) ?>
          <?php if ($a && $f['error'] === FitAssessor::DECLINED): ?>
            <form method="post" action="/applications/<?= (int) $a['id'] ?>/fit" style="display:inline"><?= csrf() ?><input type="hidden" name="now" value="1"><input type="hidden" name="back" value="/roles/<?= $rid ?>#tab-claude">
              <button class="btn ghost small" type="submit"<?= $fit['on'] ? '' : ' disabled' ?> data-confirm-click="Assess this one now at the full price (about ₹1.75)? If Claude declines again, another Claude model takes it over.">Assess now (full price)</button></form>
          <?php endif; ?></li>
      <?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_wordings.php'; ?>

<?php include __DIR__ . '/_agreement.php'; ?>

<?php if ($extras) { include __DIR__ . '/_examples.php'; } ?>

<div class="card" id="fit-settings">
  <h2>What Claude checks</h2>
  <?php if (!$fit['ownReqs']): ?><p class="small" style="margin:0 0 10px"><span class="chip sand">Using your must-have keywords</span> until you add concrete requirements. Better: let Claude draft them from the JD, then edit.</p><?php endif; ?>
  <form method="post" action="/roles/<?= $rid ?>/fit"><?= csrf() ?>
    <p class="small" style="margin:0 0 8px">Requirements a CV can be checked against, for example "Files GSTR-1 and GSTR-3B independently". Tick <b>critical</b> for the ones a candidate must have.</p>
    <?php $list = $fit['ownReqs'] ? $reqs : []; for ($i = 0; $i < max(6, count($list) + 2); $i++): $r = $list[$i] ?? ['text' => '', 'critical' => false]; ?>
      <div class="row" style="align-items:center;margin-bottom:6px">
        <input type="text" name="req[<?= $i ?>]" value="<?= e($r['text']) ?>" maxlength="250" aria-label="Requirement <?= $i + 1 ?>" style="flex:1">
        <label class="small" style="font-weight:normal;white-space:nowrap"><input type="checkbox" name="crit[<?= $i ?>]" value="1"<?= $r['critical'] ? ' checked' : '' ?>> critical</label>
      </div>
    <?php endfor; ?>
    <p class="small" style="margin:14px 0 6px"><b>Qualification ladder</b> (10 points): meets the requirement 5, a higher degree 7, a professional qualification 10, below the requirement 0. A level only pursued counts as the one below.<?= $ladder['custom'] ? '' : ' <span class="muted">Shown: the default for this role.</span>' ?></p>
    <div class="row">
      <div class="field"><label for="lb">Requirement (5)</label><input type="text" id="lb" name="ladder_base" value="<?= e($ladder['base']) ?>" placeholder="e.g. B.Com"></div>
      <div class="field"><label for="lh">Higher (7)</label><input type="text" id="lh" name="ladder_higher" value="<?= e($ladder['higher']) ?>" placeholder="e.g. M.Com, MBA (Finance)"></div>
      <div class="field"><label for="lt">Professional (10)</label><input type="text" id="lt" name="ladder_top" value="<?= e($ladder['top']) ?>" placeholder="e.g. CA Inter, CMA Inter"></div>
    </div>
    <div class="actions"><button class="btn" type="submit">Save</button></div>
  </form>
  <form method="post" action="/roles/<?= $rid ?>/fit/suggest" style="margin-top:10px"><?= csrf() ?>
    <button class="btn ghost small" type="submit"<?= $fit['on'] && trim((string) $role['jd_text']) !== '' ? '' : ' disabled' ?> data-confirm-click="Have Claude draft the requirements from the JD? It replaces the list above (about ₹1).">Draft the requirements from the JD with Claude</button>
    <?php if (trim((string) $role['jd_text']) === ''): ?><span class="small muted">Needs the JD on the role first.</span><?php endif; ?>
  </form>
  <?php if ($fit['copyFrom']): ?>
  <form method="post" action="/roles/<?= $rid ?>/fit/copy" class="row" style="align-items:flex-end;margin-top:10px"><?= csrf() ?>
    <div class="field" style="flex:1;min-width:0;margin:0"><label for="fit-copy">Or copy from another role</label>
      <select id="fit-copy" name="from" required style="width:100%">
        <option value="">Choose a role…</option>
        <?php foreach ($fit['copyFrom'] as $s): $what = array_filter([$s['reqs'] ? $s['reqs'] . ' requirement' . ($s['reqs'] > 1 ? 's' : '') : '', $s['ladder'] ? 'ladder' : '']); ?>
          <option value="<?= $s['id'] ?>"><?= e(trim(($s['code'] ?? '') . ' ' . $s['title'] . ' · ' . $s['client'])) ?><?= $s['status'] !== 'open' ? ' (' . e(str_replace('_', ' ', $s['status'])) . ')' : '' ?> · <?= e(implode(' + ', $what)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn ghost small" type="submit" data-confirm-click="Copy that role's requirements, critical ticks and qualification ladder here? It replaces what is above (free).">Copy</button>
  </form>
  <p class="small muted" style="margin:4px 0 0">Brings over the requirements, critical ticks and ladder you refined on that role. Most similar titles are listed first.</p>
  <?php endif; ?>
</div>
<script src="<?= asset('fit.js') ?>"></script>
