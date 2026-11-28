<?php /** @var array $role @var array $rep @var \Eshcol\Ats\Services\SuitabilityCheck $svc @var string $mode @var array $counts */
$c = $rep['counts'];
$checked = $c['yes'] + $c['maybe'] + $c['no'];
$pct = fn (int $n, int $d) => $d > 0 ? round(100 * $n / $d) . '%' : '–';
$sh = $rep['agree']['shortlisted'];
$td = $rep['agree']['turned_down'];
$shN = array_sum($sh);
$tdN = array_sum($td);
$left = (int) $counts['pending'] + (int) $counts['working']; ?>
<div class="back-bar"><a class="back-btn" href="/roles/<?= (int) $role['id'] ?>#tab-pipeline">‹ Back to <?= e($role['title']) ?></a></div>
<div class="page-head">
  <div><p class="eyebrow"><?= e($role['code'] ?? '') ?></p><h1>Kind of work: <?= e($role['title']) ?></h1>
    <p class="muted">Claude reads each <b>whole CV</b> against the <b>whole job description</b> and answers one question: is this the same <i>kind</i> of work? It is not a score and not keywords; it only decides who belongs on this list at all.
      Mode now: <b><?= e(\Eshcol\Ats\Services\SuitabilityCheck::MODES[$mode]) ?></b>.
      <?= $mode === 'trial' ? 'Nothing is moved or hidden in trial mode: this page shows what <i>would</i> change.' : '' ?></p></div>
</div>

<div class="tiles">
  <div class="tile hl"><b><?= $checked ?></b><span>Checked</span></div>
  <div class="tile"><b><?= $c['yes'] ?></b><span>Same kind of work</span></div>
  <div class="tile"><b><?= $c['maybe'] ?></b><span>Related or unclear</span></div>
  <div class="tile"><b><?= $c['no'] ?></b><span>Different kind of work</span></div>
  <div class="tile"><b><?= $left ?></b><span>Still to check<?= $left ? ' (all positions)' : '' ?></span></div>
</div>

<?php if ($shN || $tdN): ?>
  <section class="card">
    <h2>Does Claude agree with you?</h2>
    <p class="small muted" style="margin-top:0">Measured against your own decisions on this position. The closer to the left, the better.</p>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Your decision</th><th>Same kind</th><th>Related</th><th>Different kind</th></tr></thead>
      <tbody>
        <tr><td>Shortlisted or taken further (<?= $shN ?>)</td><td><?= $sh['yes'] ?> (<?= $pct($sh['yes'], $shN) ?>)</td><td><?= $sh['maybe'] ?></td><td><?= $sh['no'] ?><?= $sh['no'] ? ' ⚑' : '' ?></td></tr>
        <tr><td>Marked Not suitable (<?= $tdN ?>)</td><td><?= $td['yes'] ?><?= $td['yes'] ? ' ⚑' : '' ?></td><td><?= $td['maybe'] ?></td><td><?= $td['no'] ?> (<?= $pct($td['no'], $tdN) ?>)</td></tr>
      </tbody>
    </table></div>
    <p class="small muted" style="margin-bottom:0">⚑ = Claude disagrees with you; look at those. Not suitable also includes people turned down for other reasons (salary, city), so "same kind" there is not a mistake.</p>
  </section>
<?php endif; ?>

<?php $rowsOf = function (array $list, string $why) { ?>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Candidate</th><th>Stage</th><th>The CV shows</th><th>The job needs</th><th><?= e($why) ?></th></tr></thead>
    <tbody>
      <?php foreach ($list as $r): ?>
        <tr><td><a href="/candidates/<?= (int) $r['candidate_id'] ?>?role=<?= (int) $r['role_id'] ?>"><b><?= e($r['full_name'] ?: 'Name not found') ?></b></a> <span class="ref"><?= ref_candidate($r['candidate_id']) ?></span></td>
          <td class="small"><?= e($r['stage']) ?></td><td class="small"><?= e($r['cv_work']) ?></td><td class="small"><?= e($r['position_work']) ?></td>
          <td class="small"><?= e($r['reason']) ?> <span class="muted">(<?= e($r['confidence']) ?>)</span></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
<?php }; ?>

<section class="card">
  <h2>Claude says a different kind of work, and they are still being considered (<?= count($rep['different_active']) ?>)</h2>
  <?php if (!$rep['different_active']): ?>
    <p class="muted" style="margin:0"><?= $checked ? 'Nobody. Everyone still being considered is the same or a related kind of work.' : 'Nothing checked yet. The checks run in the background, about 40 every five minutes.' ?></p>
  <?php else: ?>
    <?php $sure = count(array_filter($rep['different_active'], fn ($r) => $r['confidence'] !== 'low')); ?>
    <form method="post" action="/roles/<?= (int) $role['id'] ?>/suitability" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 10px"><?= csrf() ?>
      <span class="small muted">Only the ones Claude is sure about (<?= $sure ?>) are moved. Anyone Shortlisted or later is never touched; nobody is emailed.</span>
      <button class="btn" type="submit" data-confirm-click="Move <?= $sure ?> to Not suitable, each with Claude's reason? Nobody is emailed; you can move anyone back."<?= $sure ? '' : ' disabled' ?>>Move <?= $sure ?> to Not suitable</button>
    </form>
    <?php $rowsOf($rep['different_active'], 'Why'); ?>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Claude says the same kind of work, but the rules screened them out (<?= count($rep['same_screened']) ?>)</h2>
  <p class="small muted" style="margin-top:0">Worth a look: the screening rules (city, qualification, branch) may be too strict for these.</p>
  <?php if (!$rep['same_screened']): ?><p class="muted" style="margin:0">None.</p><?php else: $rowsOf($rep['same_screened'], 'Claude\'s view'); endif; ?>
</section>

<section class="card">
  <h2>Settings</h2>
  <form method="post" action="/suitability/settings" class="row" style="align-items:flex-end;gap:12px;flex-wrap:wrap"><?= csrf() ?><input type="hidden" name="back" value="/roles/<?= (int) $role['id'] ?>/suitability">
    <div class="field" style="margin:0"><label for="sm">Mode</label><select id="sm" name="mode"><?php foreach (\Eshcol\Ats\Services\SuitabilityCheck::MODES as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($mode, $k) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0"><label for="sr">Reader</label><select id="sr" name="reader"><option value="haiku"<?= selected($svc->reader(), 'haiku') ?>>Claude Haiku (a few paise each)</option><option value="sonnet"<?= selected($svc->reader(), 'sonnet') ?>>Claude Sonnet (about 20 times dearer)</option></select></div>
    <div class="field" style="margin:0"><label for="sl">Monthly limit</label><input type="number" id="sl" name="limit" min="100" max="20000" step="100" value="<?= (int) $svc->limit() ?>" style="width:110px"></div>
    <button class="btn ghost" type="submit">Save</button>
  </form>
  <p class="small muted" style="margin:10px 0 0">This month: <?= (int) $svc->usedThisMonth() ?> checks, ₹<?= number_format($svc->costThisMonth() * 90, 2) ?> (at ₹90 to the dollar). <b>On</b> means a confident "no" is no longer assessed or scored; it still takes your button to move people.
    <?= $counts['failed'] ? '<br>' . (int) $counts['failed'] . ' check(s) failed and are tried again by the system.' : '' ?></p>
</section>
