<?php
/**
 * "Ready to shortlist" on the role's Claude tab (Benjamin, 10 Nov 2026): Claude's band on the CV with
 * what the candidate told us checked against the role. Needs $rid (the role id).
 */
$ready = (new \Eshcol\Ats\Services\FitAssessor(\Eshcol\Ats\App::get()))->readyList($rid);
$tone = ['ok' => 'yes', 'no' => 'no', 'unknown' => 'sand'];
$mark = ['ok' => '✓', 'no' => '✗', 'unknown' => '?'];
$label = ['salary' => 'Salary', 'notice' => 'Notice', 'place' => 'Place'];
// The same key details on every list (16 Nov 2026).
$readyKf = (new \Eshcol\Ats\Services\KeyFacts(\Eshcol\Ats\App::get()))->forCandidates(array_column(array_merge($ready['ready'], $ready['check'], $ready['waiting'], $ready['rest']), 'candidate_id'));
$rowsOf = function (array $list) use ($tone, $mark, $label, $rid, $readyKf): string {
    ob_start(); ?>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Candidate</th><th>Claude</th><th>What they told us</th></tr></thead>
      <tbody><?php foreach ($list as $r): ?>
        <tr>
          <td><a href="/candidates/<?= (int) $r['candidate_id'] ?>?role=<?= $rid ?>"><b><?= e($r['full_name'] ?: 'Name not found') ?></b></a> <span class="ref"><?= ref_candidate($r['candidate_id']) ?></span><br><?= \Eshcol\Ats\Services\KeyFacts::stageChip((string) $r['stage'], (string) ($r['stage_kind'] ?? '')) ?>
            <?php $rk = $readyKf[(int) $r['candidate_id']] ?? []; if ($rk): ?><br><?= \Eshcol\Ats\Services\KeyFacts::line($rk, ['responded']) ?> <?= \Eshcol\Ats\Services\KeyFacts::cvButton($rk) ?><?php endif; ?></td>
          <td class="nowrap"><span class="score <?= $r['band'] === 'Strong' ? 'score-high' : ($r['band'] === 'Possible' ? 'score-mid' : 'score-low') ?>"><?= (int) $r['score'] ?></span> <span class="small"><?= e($r['band']) ?></span></td>
          <td><?php foreach ($r['checks'] as $k => [$st, $txt]): ?><span class="chip <?= $tone[$st] ?>" style="text-decoration:none" title="<?= e($label[$k]) ?>"><?= $mark[$st] ?> <?= e($label[$k]) ?>: <?= e($txt) ?></span> <?php endforeach; ?>
            <?php if ($r['qstatus'] !== 'answered'): ?><br><span class="small muted"><?= $r['qstatus'] ? 'Asked for their details, no answer yet' : 'Not asked for their details yet' ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php return (string) ob_get_clean();
};
$total = count($ready['ready']) + count($ready['check']) + count($ready['waiting']) + count($ready['rest']);
?>
<?php if ($total): ?>
<div class="card" id="ready">
  <h2 style="margin-top:0">Ready to shortlist (<?= count($ready['ready']) ?>)</h2>
  <p class="small muted" style="margin:-6px 0 10px">Claude's band on the CV, with what the candidate told us checked against this role: the lowest salary they would accept within the budget, notice within the limit, and able to work at the place. Strong first.</p>
  <?php if ($ready['ready']): ?><?= $rowsOf($ready['ready']) ?><?php else: ?><p class="small">Nobody yet: no Strong or Possible candidate has all three in order.</p><?php endif; ?>
  <?php if ($ready['check']): ?>
    <details style="margin-top:12px"<?= !$ready['ready'] ? ' open' : '' ?>><summary><b>One thing to check (<?= count($ready['check']) ?>)</b> <span class="small muted">Strong or Possible, with one detail over the limit</span></summary>
      <div style="margin-top:8px"><?= $rowsOf($ready['check']) ?></div>
    </details>
  <?php endif; ?>
  <?php if ($ready['waiting']): ?>
    <details style="margin-top:8px"><summary><b>Waiting for their details (<?= count($ready['waiting']) ?>)</b> <span class="small muted">Strong or Possible, nothing over the limit, but salary, notice or place not given yet</span></summary>
      <div style="margin-top:8px"><?= $rowsOf($ready['waiting']) ?></div>
    </details>
  <?php endif; ?>
  <?php if ($ready['rest']): ?>
    <details style="margin-top:8px"><summary><b>The rest (<?= count($ready['rest']) ?>)</b> <span class="small muted">Weak on the CV, or two or more details over the limit</span></summary>
      <div style="margin-top:8px"><?= $rowsOf($ready['rest']) ?></div>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>
