<?php
/** Analyser 7 (15 Nov 2026): the fair check of the top 10. Needs $rid, $fit and $byApp. */
use Eshcol\Ats\Services\FairCheck;

$fcSvc = new FairCheck(\Eshcol\Ats\App::get());
$fcTop = $fcSvc->top($rid);
$fc = $fcSvc->latest($rid);
$fcBusy = $fc && in_array($fc['status'], ['pending', 'batched'], true);
$fcName = function (int $appId) use ($byApp, $rid): string {
    $a = $byApp[$appId] ?? null;
    return $a ? '<a href="/candidates/' . (int) $a['candidate_id'] . '?role=' . $rid . '">' . e($a['full_name'] ?: 'Name not found') . '</a>' : '<span class="muted">no longer on the position</span>';
};
$fcScore = $fc ? array_column($fc['top'], 'score', 'app') : [];
if (count($fcTop) < FairCheck::MIN && !$fc) {
    return;
}
?>
<div class="card" id="fair">
  <h2>Fair check of the top <?= FairCheck::TOP ?></h2>
  <p class="small muted" style="margin:0 0 10px">Before a client shortlist: Claude compares each next-door pair in its top <?= FairCheck::TOP ?> (1 v 2, 2 v 3 …) twice, once in each order, because AI tends to favour the CV it reads first. Both CVs are blind.
    Where both orders agree, the order is firm. Scores never change; you get a suggested order to weigh.</p>
  <?php if ($fcBusy): ?>
    <p class="small"><span class="chip sand" style="text-decoration:none">With Claude</span> in a half-price batch since <?= e(nice_date($fc['created_at'], true)) ?>. The result usually arrives within the hour.<?= $fc['error'] ? ' <span class="muted">(Last try: ' . e($fc['error']) . ')</span>' : '' ?></p>
  <?php elseif ($fc && $fc['status'] === 'failed'): ?>
    <p class="small" style="color:var(--red)"><b>Claude did not take the half-price batch</b> (<?= e((string) $fc['error']) ?>). Nothing was charged, and it was not run at full price. Press the button to try again.</p>
  <?php elseif ($fc && $fc['status'] === 'done'): ?>
    <p class="small" style="margin:0 0 8px">Checked <?= e(nice_date($fc['updated_at'], true)) ?><?= $fc['cost_usd'] !== null ? ', about ₹' . number_format((float) $fc['cost_usd'] * INR_PER_USD) : '' ?>.
      <?php if ($fc['outdated']): ?><span class="chip flag" style="text-decoration:none" title="The top <?= FairCheck::TOP ?>, their scores or the requirements changed after this check">out of date</span><?php endif; ?></p>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Pair</th><th>Result</th></tr></thead>
      <tbody><?php foreach ($fc['results']['pairs'] ?? [] as $k => $p): $v = FairCheck::VERDICTS[$p['verdict']] ?? FairCheck::VERDICTS['close']; ?>
        <tr><td class="small"><?= $k + 1 ?>. <?= $fcName((int) $p['upper']) ?> (<?= (int) ($fcScore[$p['upper']] ?? 0) ?>) v <?= $fcName((int) $p['lower']) ?> (<?= (int) ($fcScore[$p['lower']] ?? 0) ?>)</td>
          <td class="small"><span class="chip <?= $v[1] ?>" style="text-decoration:none"><?= e($v[0]) ?></span><?= !empty($p['missing']) ? ' <span class="muted">(Claude did not answer both ways)</span>' : '' ?><?= $p['reason'] !== '' ? '<br><span class="muted">' . e($p['reason']) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php $order = $fc['results']['order'] ?? []; if ($order && $order !== array_column($fc['top'], 'app')): ?>
      <p class="small" style="margin:10px 0 4px"><b>Suggested order</b> (Claude's order with the agreed swaps):</p>
      <ol class="small" style="margin:0;padding-left:22px"><?php foreach ($order as $appId): ?><li><?= $fcName((int) $appId) ?> <span class="muted">(<?= (int) ($fcScore[$appId] ?? 0) ?>)</span></li><?php endforeach; ?></ol>
    <?php elseif ($order): ?>
      <p class="small" style="margin:10px 0 0">No agreed swaps: Claude's order stands.</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if (!$fcBusy && count($fcTop) >= FairCheck::MIN): $est = FairCheck::estimate(count($fcTop)); ?>
    <form method="post" action="/roles/<?= $rid ?>/fair-check" style="margin-top:10px"><?= csrf() ?>
      <button class="btn ghost small" type="submit"<?= $fit['on'] ? '' : ' disabled' ?> data-confirm-click="Have Claude compare the top <?= count($fcTop) ?> two at a time, both ways round? <?= (count($fcTop) - 1) * 2 ?> comparisons in a half-price batch, about ₹<?= $est ?>–<?= (int) round($est * 1.4) ?>. Results usually within the hour.">Fair check of the top <?= count($fcTop) ?> (about ₹<?= $est ?>)</button></form>
  <?php endif; ?>
</div>
