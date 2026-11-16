<?php
/** Analyser 5a (15 Nov 2026): Claude's bands against your decisions and the client's answers. Needs $rid. */
use Eshcol\Ats\Services\Agreement;

$ag = (new Agreement(\Eshcol\Ats\App::get()))->forRole($rid);
if ($ag['assessed'] === 0) {
    return;
}
$cols = Agreement::DECISIONS;
$hasClient = array_sum(array_map(fn ($g) => $g['client_shortlist'] + $g['client_hold'] + $g['client_reject'], $ag['grid'])) > 0;
$tone = ['Strong' => 'yes', 'Possible' => 'sand', 'Weak' => 'no'];
?>
<div class="card" id="agreement">
  <h2>Claude and your decisions</h2>
  <p class="small muted" style="margin:0 0 10px">Where each assessed candidate is now, by Claude's band. Taken forward means Shortlisted or later. "Withdrew" was their choice, so it doesn't count either way.</p>
  <?php if ($ag['decided'] > 0): ?>
    <p style="margin:0 0 10px"><b><?= $ag['pct'] ?>% agreement</b> <span class="small muted">(<?= (int) $ag['agree'] ?> of <?= (int) $ag['decided'] ?>: Strong you took forward, plus Weak you turned down)</span>
      <?php if (!$ag['enough']): ?><span class="chip flag" style="text-decoration:none" title="Fewer than <?= Agreement::ENOUGH ?> decisions on Strong or Weak candidates">too early to judge</span><?php endif; ?></p>
  <?php else: ?>
    <p class="small" style="margin:0 0 10px">No decisions yet on Strong or Weak candidates. Taking someone forward or turning them down starts this.</p>
  <?php endif; ?>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Claude's band</th><?php foreach ($cols as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach; ?>
      <?php if ($hasClient): ?><th class="num" title="The client's answer on the shortlist">Client: yes</th><th class="num">Client: hold</th><th class="num">Client: no</th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($ag['grid'] as $band => $g): ?>
      <tr><td><span class="chip <?= $tone[$band] ?>" style="text-decoration:none"><?= e($band) ?></span></td>
        <?php foreach (array_keys($cols) as $k): $n = (int) $g[$k]; $odd = ($band === 'Strong' && $k === 'down') || ($band === 'Weak' && $k === 'forward'); ?>
          <td class="num"<?= $odd && $n ? ' style="color:var(--red);font-weight:bold"' : '' ?>><?= $n ?: '<span class="muted">0</span>' ?></td><?php endforeach; ?>
        <?php if ($hasClient): ?><td class="num"><?= (int) $g['client_shortlist'] ?></td><td class="num"><?= (int) $g['client_hold'] ?></td><td class="num"><?= (int) $g['client_reject'] ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($ag['differ']): ?>
    <details style="margin-top:10px"<?= count($ag['differ']) <= 5 ? ' open' : '' ?>><summary class="small"><b>Where you and Claude differ (<?= count($ag['differ']) ?>)</b> <span class="muted">your reasons show what Claude is missing</span></summary>
      <ul class="small" style="margin:8px 0 0;padding-left:18px"><?php foreach ($ag['differ'] as $d): ?>
        <li style="margin-bottom:4px"><a href="/candidates/<?= $d['candidate_id'] ?>?role=<?= $rid ?>"><?= e($d['name'] ?: 'Name not found') ?></a>:
          Claude <?= e($d['band']) ?> (<?= $d['score'] ?>), you <?= $d['decision'] === 'down' ? 'turned them down' : 'took them forward' ?> (<?= e($d['stage']) ?>)<?= $d['reason'] !== '' ? ': <i>' . e($d['reason']) . '</i>' : ($d['decision'] === 'down' ? ' <span class="muted">· no reason given</span>' : '') ?></li>
      <?php endforeach; ?></ul></details>
  <?php endif; ?>
  <?php /* Analyser 5b (15 Nov 2026): Claude drafts changes from your reasons; you accept or ignore each. Hidden while the extras are off. */
  if (Agreement::extrasOn(\Eshcol\Ats\App::get())):
  $rs =new \Eshcol\Ats\Services\RuleSuggestions(\Eshcol\Ats\App::get());
  $rsReasons = count($rs->reasons($rid));
  $rsOpen = $rs->open($rid);
  $rsOn = \Eshcol\Ats\Services\FitAssessor::enabled(\Eshcol\Ats\App::get()); ?>
  <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px">
    <form method="post" action="/roles/<?= $rid ?>/rules/suggest" style="display:inline"><?= csrf() ?>
      <button class="btn ghost small" type="submit"<?= $rsOn && $rsReasons >= \Eshcol\Ats\Services\RuleSuggestions::MIN_REASONS ? '' : ' disabled' ?>
        data-confirm-click="Have Claude read your <?= $rsReasons ?> reason<?= $rsReasons === 1 ? '' : 's' ?> and suggest changes to what it checks? About ₹2. Nothing changes until you accept a suggestion.">Suggest rule changes</button></form>
    <span class="small muted"><?php if ($rsReasons < \Eshcol\Ats\Services\RuleSuggestions::MIN_REASONS): ?>Needs at least <?= \Eshcol\Ats\Services\RuleSuggestions::MIN_REASONS ?> differences with your reason (<?= $rsReasons ?> so far).<?php else: ?>Claude reads your <?= $rsReasons ?> reasons and drafts changes. About ₹2 a press.<?php if (!$ag['enough']): ?> Better with <?= Agreement::ENOUGH ?> or more decisions.<?php endif; ?><?php endif; ?></span>
    <?php if ($rsOpen): ?>
      <ul class="small" style="margin:10px 0 0;padding-left:18px"><?php foreach ($rsOpen as $s): ?>
        <li style="margin-bottom:8px"><b><?= e(\Eshcol\Ats\Services\RuleSuggestions::describe($s)) ?></b><br><span class="muted">Why: <?= e($s['why']) ?></span><br>
          <form method="post" action="/roles/<?= $rid ?>/rules/<?= (int) $s['id'] ?>" style="display:inline"><?= csrf() ?><input type="hidden" name="accept" value="1"><button class="btn small" type="submit">Accept</button></form>
          <form method="post" action="/roles/<?= $rid ?>/rules/<?= (int) $s['id'] ?>" style="display:inline"><?= csrf() ?><button class="btn ghost small" type="submit">Ignore</button></form></li>
      <?php endforeach; ?></ul>
      <p class="small muted" style="margin:4px 0 0">An added requirement starts as not critical; tick critical in "What Claude checks" if it should be. Accepting marks earlier assessments "role changed"; nobody is re-assessed unless you press Re-assess everyone.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
