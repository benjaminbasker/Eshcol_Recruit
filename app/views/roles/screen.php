<?php /** @var array $role @var array $rows @var bool $bcom @var string $scope */
$byRule = ['not_qualified' => [], 'other_city' => []];
foreach ($rows as $r) { $byRule[$r['rule']][] = $r; } ?>
<div class="back-bar"><a class="back-btn" href="/roles/<?= (int) $role['id'] ?>#tab-pipeline">‹ Back to <?= e($role['title']) ?></a></div>
<div class="page-head">
  <div><p class="eyebrow"><?= e($role['code'] ?? '') ?></p><h1>Screening rules: <?= e($role['title']) ?></h1>
    <p class="muted">The rules on this position:
      <?= $bcom ? '<b>B.Com required</b> (M.Com, CA or CMA count as B.Com; MBA or PGDM with no graduation shown is flagged for you to confirm, not rejected)' : 'no qualification rule (set B.Com under Edit › Qualification to add one)' ?>;
      <?= $scope === 'local' ? '<b>local candidates only</b> (' . e((string) $role['location']) . ')' : ($scope === 'radius' ? '<b>within ' . \Eshcol\Ats\Services\FunctionRouting::radiusOf($role) . ' km of the office</b> (' . e((string) $role['location']) . ', plus towns within that distance)' : 'candidates from other cities are taken') ?>.
      New CVs are checked automatically. Below: people already on the position who don't meet them. Anyone Shortlisted or further is never touched, and nobody is emailed.</p></div>
</div>
<?php if (!$rows): ?>
  <div class="card empty">Everyone still being considered meets the rules. Nothing to move.</div>
<?php else: ?>
  <form method="post" action="/roles/<?= (int) $role['id'] ?>/screen" class="card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><?= csrf() ?>
    <span><b><?= count($rows) ?></b> to move to <b>Not suitable</b>, each with the reason below:
      <?= count($byRule['not_qualified']) ?> not a B.Com, <?= count($byRule['other_city']) ?> from another city.</span>
    <button class="btn" type="submit" data-confirm-click="Move these <?= count($rows) ?> to Not suitable? Nobody is emailed; you can move anyone back.">Move <?= count($rows) ?> to Not suitable</button>
  </form>
  <?php foreach (['not_qualified' => 'Not a B.Com', 'other_city' => 'From another city'] as $k => $label): if (!$byRule[$k]) { continue; } ?>
    <div class="card"><h2><?= e($label) ?> (<?= count($byRule[$k]) ?>)</h2>
      <div class="table-wrap"><table class="list compact"><thead><tr><th>Candidate</th><th>Stage now</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($byRule[$k] as $r): ?>
          <tr><td><a href="/candidates/<?= $r['candidate'] ?>?role=<?= (int) $role['id'] ?>"><b><?= e($r['name'] ?: 'Name not found') ?></b></a> <span class="ref"><?= ref_candidate($r['candidate']) ?></span></td>
            <td><?= e($r['stage']) ?></td><td class="small"><?= e($r['reason']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div></div>
  <?php endforeach; ?>
<?php endif; ?>
