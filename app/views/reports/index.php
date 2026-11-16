<?php
/** @var string $period @var string $from @var string $to @var array $funnel @var array $sources @var array $fill @var array $claude @var array $claudeRoles @var array $money */
$rs = fn ($v) => '₹' . number_format((float) $v, 0, '.', ',');
$inr = fn ($usd) => '₹' . number_format((float) $usd * INR_PER_USD, 0, '.', ',');
$top = max(1, (int) ($funnel[0]['n'] ?? 0));
?>
<div class="page-head">
  <div><p class="eyebrow">Only you see this page</p><h1>Reports</h1>
    <p class="muted"><?= $period === 'all' ? 'All time' : e(nice_date($from)) . ' to ' . e(nice_date($to)) ?>. Numbers come from what the app already records; positions here are your client positions (talent pools are left out).</p></div>
</div>
<p class="actions small" style="margin:-4px 0 16px">
  <?php foreach (\Eshcol\Ats\Services\Reports::PERIODS as $k => $label): ?>
    <a href="/reports?period=<?= $k ?>" class="<?= $period === $k ? 'btn small' : 'btn ghost small' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</p>

<div class="row" style="margin-bottom:16px">
  <div class="card" style="flex:1;min-width:240px"><h3>Placements</h3>
    <p style="margin:0"><b><?= (int) $money['placed'] ?></b> joined · fees <b><?= $rs($money['fees']) ?></b></p>
    <p class="small" style="margin:4px 0 0">Received <b><?= $rs($money['received']) ?></b> · outstanding <?= $rs($money['outstanding']) ?> · <a href="/placements">Placements</a></p></div>
  <div class="card" style="flex:1;min-width:240px"><h3>Claude this month</h3>
    <?php $m = $claude ? $claude[count($claude) - 1] : ['reading' => 0, 'categories' => 0, 'assessments' => 0, 'n_read' => 0, 'n_assessed' => 0]; ?>
    <p style="margin:0"><b><?= $inr($m['reading'] + $m['categories'] + $m['assessments']) ?></b> in all</p>
    <p class="small" style="margin:4px 0 0">Reading <?= (int) $m['n_read'] ?> CVs <?= $inr($m['reading']) ?> · fields <?= $inr($m['categories']) ?> · <?= (int) $m['n_assessed'] ?> assessments <?= $inr($m['assessments']) ?></p></div>
</div>

<h2><?= icon('list') ?> How far applications got</h2>
<div class="card">
  <?php if (!$funnel || !$funnel[0]['n']): ?><p class="muted" style="margin:0">No applications in this period.</p><?php else: ?>
  <div class="funnel">
    <?php foreach ($funnel as $f): ?>
      <div class="f-row"><span class="f-label"><?= e($f['step']) ?></span>
        <span class="f-bar"><i style="width:<?= max(2, round(100 * $f['n'] / $top)) ?>%"></i></span>
        <span class="f-n"><b><?= (int) $f['n'] ?></b><?= $f['of_prev'] !== null ? ' <span class="small muted">(' . ((int) $f['of_prev'] === 0 && $f['n'] > 0 ? 'under 1%' : (int) $f['of_prev'] . '%') . ' of the step before)</span>' : '' ?></span></div>
    <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin:10px 0 0">Applications started in the period, counted at the furthest step they reached (even if they left later).</p>
  <?php endif; ?>
</div>

<h2><?= icon('users') ?> Where hires come from</h2>
<div class="card">
  <?php if (!$sources): ?><p class="muted" style="margin:0">No applications in this period.</p><?php else: ?>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Source</th><th>CVs</th><th>Sent to client</th><th>Interviewed</th><th>Joined</th></tr></thead>
    <tbody><?php foreach ($sources as $s): ?>
      <tr><td><b><?= e($s['source']) ?></b></td><td><?= (int) $s['cvs'] ?></td><td><?= (int) $s['sent'] ?></td><td><?= (int) $s['interviewed'] ?></td><td><b><?= (int) $s['joined'] ?></b></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p class="small muted" style="margin:8px 0 0">"Source" is how they came to the position, or where their CV first came from.</p>
  <?php endif; ?>
</div>

<h2><?= icon('clock') ?> Time to fill</h2>
<div class="card">
  <?php if (!$fill): ?><p class="muted" style="margin:0">No positions opened in this period.</p><?php else: ?>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Position</th><th>Opened</th><th>Days to first offer</th><th>Days to joining</th></tr></thead>
    <tbody><?php foreach ($fill as $f): ?>
      <tr><td><b><?= e($f['title']) ?></b><br><span class="small muted"><?= e($f['client']) ?> · <?= e(str_replace('_', ' ', $f['status'])) ?></span></td>
        <td class="nowrap small"><?= e(nice_date($f['opened'])) ?></td>
        <td><?= $f['to_offer'] !== null ? (int) $f['to_offer'] : '<span class="muted">–</span>' ?></td>
        <td><?= $f['to_join'] !== null ? '<b>' . (int) $f['to_join'] . '</b>' : '<span class="small muted">open ' . (int) $f['open_days'] . ' days</span>' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<h2><?= icon('sparkles') ?> What Claude cost</h2>
<div class="card">
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Month</th><th>Reading CVs</th><th>Field of work</th><th>Assessments</th><th>Total</th></tr></thead>
    <tbody><?php foreach (array_reverse($claude) as $m): ?>
      <tr><td class="nowrap"><?= e(date('M Y', strtotime($m['month'] . '-01'))) ?></td>
        <td><?= $inr($m['reading']) ?> <span class="small muted">(<?= (int) $m['n_read'] ?>)</span></td><td><?= $inr($m['categories']) ?></td>
        <td><?= $inr($m['assessments']) ?> <span class="small muted">(<?= (int) $m['n_assessed'] ?>)</span></td><td><b><?= $inr($m['reading'] + $m['categories'] + $m['assessments']) ?></b></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($claudeRoles): ?>
    <p class="small" style="margin:12px 0 4px"><b>Assessments per position, in this period:</b></p>
    <ul class="small" style="margin:0;padding-left:18px"><?php foreach ($claudeRoles as $c): ?><li><?= e($c['title']) ?> (<?= e($c['client']) ?>): <?= (int) $c['n'] ?> assessments, <?= $inr($c['cost']) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <p class="small muted" style="margin:8px 0 0">At ₹<?= (int) INR_PER_USD ?> to the US dollar. Claude's own bill (console.anthropic.com) is the final word.</p>
</div>
