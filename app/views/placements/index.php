<?php
/** @var \Eshcol\Ats\Services\Placements $svc @var array $rows @var string $show @var array $month @var array $year @var int $fy */
$rs = fn ($v) => '₹' . number_format((float) $v, 0, '.', ',');
$tone = ['to_invoice' => 'flag', 'overdue' => 'bad', 'paid' => 'yes', 'invoiced' => 'sand', 'none' => ''];
$box = function (string $label, array $t) use ($rs) { ?>
  <div class="card" style="flex:1;min-width:260px">
    <h3 style="margin-top:0"><?= e($label) ?></h3>
    <p style="margin:0 0 4px"><b><?= (int) $t['placed'] ?></b> joined · fees <b><?= $rs($t['fees']) ?></b></p>
    <p class="small" style="margin:0">Invoiced <?= $rs($t['invoiced']) ?> · received <b><?= $rs($t['received']) ?></b> · outstanding <b><?= $rs($t['outstanding']) ?></b></p>
  </div>
<?php };
?>
<div class="page-head">
  <div><p class="eyebrow">Only you see this page</p><h1>Placements</h1>
    <p class="muted">Offers, joinings, fees and invoices. Invoices are raised in Zoho Books; type the number and date on the candidate's Placement box. Fees are worked out on the total annual CTC (variable included) from each client's terms, and you can change any fee there. Amounts are before GST.</p></div>
</div>
<div class="row" style="margin-bottom:16px">
  <?php $box(date('F Y'), $month); $box('Financial year ' . $fy . '–' . substr((string) ($fy + 1), 2), $year); ?>
</div>
<p class="actions small" style="margin:0 0 12px">
  <?php foreach (['all' => 'All', 'offers' => 'Offers waiting to join', 'unpaid' => 'Not yet paid'] as $k => $label): ?>
    <a href="/placements?show=<?= $k ?>" class="<?= $show === $k ? 'btn small' : 'btn ghost small' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</p>
<?php if (!$rows): ?>
  <div class="card empty">Nothing here yet. Moving someone to "Offer made" or "Joined" on their candidate page starts a placement.</div>
<?php else: ?>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Candidate</th><th>Position</th><th>Status</th><th>Joining</th><th>Fee</th><th>Invoice</th><th>Money</th><th>Guarantee until</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr>
        <td><?= $r['candidate_id'] ? '<a href="/candidates/' . (int) $r['candidate_id'] . '#placement-' . (int) $r['id'] . '"><b>' . e((string) $r['name']) . '</b></a>' : '<b>' . e((string) ($r['name'] ?: 'Deleted candidate')) . '</b>' ?></td>
        <td class="small"><?= e((string) $r['role']) ?><br><span class="muted"><?= e((string) $r['client']) ?></span></td>
        <td class="small"><?= e(\Eshcol\Ats\Services\Placements::STATUS[$r['status']] ?? $r['status']) ?><?= $r['replacement_of'] ? '<br><span class="muted">replacement</span>' : '' ?></td>
        <td class="nowrap small"><?= $r['joined_on'] ? e(nice_date($r['joined_on'])) : ($r['expected_join'] ? 'expected ' . e(nice_date($r['expected_join'])) : '–') ?></td>
        <td class="nowrap"><?= $r['fee_amount'] !== null ? $rs($r['fee_amount']) : '<span class="muted">–</span>' ?></td>
        <td class="small"><?= e((string) ($r['invoice_no'] ?: '–')) ?><?= $r['invoiced_on'] ? '<br><span class="muted">' . e(nice_date($r['invoiced_on'])) . '</span>' : '' ?></td>
        <td><?= $r['money'] !== '' ? '<span class="chip ' . ($tone[$r['money']] ?? '') . '">' . e(\Eshcol\Ats\Services\Placements::MONEY[$r['money']]) . '</span>' . ($r['money'] === 'overdue' || $r['money'] === 'invoiced' ? '<br><span class="small muted">due ' . e(nice_date($r['due_on'])) . '</span>' : '') : '' ?></td>
        <td class="nowrap small"><?= $r['guarantee_until'] ? e(nice_date($r['guarantee_until'])) : '–' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
<?php endif; ?>
