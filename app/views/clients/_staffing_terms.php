<?php
/** A staffing client's commercial terms, with every earlier version (15 Nov 2026). @var array $client */
use Eshcol\Ats\Services\Staffing;

$stDb = \Eshcol\Ats\App::get()->db;
$hist = $stDb->all('SELECT t.*, u.name AS by_name FROM client_terms t LEFT JOIN users u ON u.id = t.created_by WHERE t.client_id = ? ORDER BY t.id DESC', [$client['id']]);
$t = $hist[0] ?? null;
$canEdit = (($user ?? [])['role'] ?? '') === 'owner';
$n2 = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$safe = Staffing::safeguard($t);
?>
<div class="card" id="staffing-terms">
  <h3>Staffing terms</h3>
  <?php if ($t): ?>
    <p class="small" style="margin:0 0 6px"><b><?= e(Staffing::BILLING[$t['billing_model']]) ?></b>:
      <?= $t['billing_model'] === 'markup' ? e($n2($t['markup_pct'])) . ' %' : ($t['billing_model'] === 'fixed_fee' ? '₹' . e(number_format((float) $t['fixed_fee'])) . ' per person per month' : 'set on each position') ?>
      <?= $t['billing_model'] === 'markup' ? ' on ' . e(strtolower(Staffing::MARKUP_BASE[$t['markup_base'] ?? 'cost'])) : '' ?>
      · GST <?= e($n2($t['gst_pct'])) ?> % on the gross bill</p>
    <p class="small" style="margin:0 0 6px">Wages for PF, bonus and gratuity: <?= ($t['wage_rule'] ?? 'law') === 'gross' ? '<span class="chip flag" style="text-decoration:none">common practice</span> share of gross; the offer shows the extra cost if the law is applied' : 'the law (at least the minimum wage)' ?>
      · basic <?= isset($t['wage_share_pct']) && $t['wage_share_pct'] !== null ? '<b>' . e($n2($t['wage_share_pct'])) . ' % of gross</b> (this client\'s own)' : 'the share in Settings › Staffing' ?>
      <?= !empty($t['bonus_in_gross']) ? '· <b>bonus paid monthly within the gross</b> (no bonus added on top)' : '' ?></p>
    <?php $tp = !empty($t['payers']) ? (json_decode((string) $t['payers'], true) ?: []) : null; ?>
    <p class="small" style="margin:0 0 6px">Who pays: <?php if ($tp === null): ?>everything in the monthly bill (terms saved before 16 Nov 2026)<?php else: $byW = []; foreach (Staffing::COSTS as $ck => [$cl]) { $byW[$tp[$ck] ?? Staffing::PAYER_DEFAULTS[$ck]][] = $cl; }
      echo implode('; ', array_map(fn ($w) => e(Staffing::PAYERS[$w]) . ': ' . e(implode(', ', $byW[$w])), array_keys(array_intersect_key(Staffing::PAYERS, $byW))));
      endif; ?><?= !empty($t['reimburse_days']) ? ' · reimbursed within ' . (int) $t['reimburse_days'] . ' days of proof' : '' ?></p>
    <p class="small" style="margin:0 0 6px">Wages by the 7th: <?= $safe ? '<span class="chip yes" style="text-decoration:none">' . e($safe) . '</span>' : '<span class="chip no" style="text-decoration:none">not protected</span>' ?></p>
    <p class="small muted" style="margin:0">Conversion fee: <?= e($n2($t['conv_pct_6m'])) ?> % of annual CTC within 6 months, <?= e($n2($t['conv_pct_12m'])) ?> % from 6 to 12, <?= e($n2($t['conv_pct_after'])) ?> % after.
      <?= $t['replacement_days'] !== null ? ' Replacement within ' . (int) $t['replacement_days'] . ' days.' : '' ?><?= $t['notice_days'] !== null ? ' Notice ' . (int) $t['notice_days'] . ' days.' : '' ?></p>
  <?php else: ?><p class="small muted" style="margin:0">None yet. Needed before a staffing offer for this client can be approved.</p><?php endif; ?>
  <?php if ($canEdit): ?>
  <details style="margin-top:10px"<?= $t ? '' : ' open' ?>><summary class="small"><?= $t ? 'Change the terms (the current ones are kept in the history)' : 'Set the terms' ?></summary>
    <form method="post" action="/clients/<?= (int) $client['id'] ?>/staffing-terms" style="margin-top:8px"><?= csrf() ?>
      <div class="field"><label for="ct-model">How the client is billed</label><select id="ct-model" name="billing_model">
        <?php foreach (Staffing::BILLING as $k => $l): ?><option value="<?= $k ?>"<?= ($t['billing_model'] ?? 'markup') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="row">
        <div class="field"><label for="ct-mk">Fee %</label><input type="text" inputmode="decimal" id="ct-mk" name="markup_pct" value="<?= e($n2($t['markup_pct'] ?? null)) ?>"></div>
        <div class="field"><label for="ct-ff">Fixed fee ₹ <span class="hint">per person per month</span></label><input type="text" inputmode="decimal" id="ct-ff" name="fixed_fee" value="<?= e($n2($t['fixed_fee'] ?? null)) ?>"></div>
        <div class="field" style="flex:0 1 90px"><label for="ct-gst">GST %</label><input type="text" inputmode="decimal" id="ct-gst" name="gst_pct" value="<?= e($n2($t['gst_pct'] ?? 18)) ?>"></div>
      </div>
      <div class="field"><label for="ct-base">Fee % worked out on</label><select id="ct-base" name="markup_base">
        <?php foreach (Staffing::MARKUP_BASE as $k => $l): ?><option value="<?= $k ?>"<?= ($t['markup_base'] ?? 'gross') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="ct-wr">Wages for PF, bonus and gratuity</label><select id="ct-wr" name="wage_rule">
        <?php foreach (Staffing::WAGE_RULES as $k => $l): ?><option value="<?= $k ?>"<?= ($t['wage_rule'] ?? 'law') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <span class="hint">On common practice, each offer shows what it would cost more if an inspector applied the law.</span></div>
      <div class="field" style="max-width:320px"><label for="ct-ws">Basic as a share of gross, % <span class="hint">this client's own; empty = Settings › Staffing</span></label><input type="text" inputmode="decimal" id="ct-ws" name="wage_share_pct" value="<?= e($n2($t['wage_share_pct'] ?? null)) ?>"></div>
      <div class="field"><label><input type="checkbox" name="bonus_in_gross" value="1"<?= !empty($t['bonus_in_gross']) ? ' checked' : '' ?>> The statutory bonus is paid monthly within the gross <span class="hint">no bonus is added on top</span></label></div>
      <?php $tpf = !empty($t['payers']) ? (json_decode((string) $t['payers'], true) ?: []) : []; ?>
      <p class="small" style="margin:4px 0 4px"><b>Who pays each cost</b></p>
      <div class="table-wrap"><table class="list compact" style="margin:0 0 8px">
        <?php foreach (Staffing::GROUPS as $gk => $gl): ?><tr><td colspan="2" class="small muted"><?= e($gl) ?></td></tr>
          <?php foreach (Staffing::COSTS as $ck => [$cl, $cg]): if ($cg !== $gk) { continue; } ?>
          <tr><td class="small"><label for="ct-p-<?= $ck ?>"><?= e($cl) ?></label></td><td><select id="ct-p-<?= $ck ?>" name="payer[<?= $ck ?>]">
            <?php foreach (Staffing::PAYERS as $pk => $pl): ?><option value="<?= $pk ?>"<?= ($tpf[$ck] ?? Staffing::PAYER_DEFAULTS[$ck]) === $pk ? ' selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?></select></td></tr>
          <?php endforeach; endforeach; ?>
      </table></div>
      <div class="field" style="max-width:260px"><label for="ct-rd">Reimbursed within (days of proof)</label><input type="number" min="0" max="90" id="ct-rd" name="reimburse_days" value="<?= e((string) ($t['reimburse_days'] ?? '')) ?>" placeholder="e.g. 7"></div>
      <div class="row">
        <div class="field"><label for="ct-inv">Invoice</label><select id="ct-inv" name="invoice_timing"><option value="month_end"<?= ($t['invoice_timing'] ?? 'month_end') === 'month_end' ? ' selected' : '' ?>>At month end</option><option value="advance"<?= ($t['invoice_timing'] ?? '') === 'advance' ? ' selected' : '' ?>>In advance</option></select></div>
        <div class="field" style="flex:0 1 120px"><label for="ct-due">Due in (days)</label><input type="number" min="0" max="90" id="ct-due" name="payment_due_days" value="<?= e((string) ($t['payment_due_days'] ?? 5)) ?>"></div>
        <div class="field" style="flex:0 1 140px"><label for="ct-dep">Deposit (months)</label><input type="text" inputmode="decimal" id="ct-dep" name="deposit_months" value="<?= e($n2($t['deposit_months'] ?? 0)) ?>"></div>
      </div>
      <p class="hint" style="margin:-4px 0 8px">Wages must be paid by the 7th. Due within 6 days of a month-end invoice, billing in advance, or a deposit of a month or more protects them.</p>
      <div class="row">
        <div class="field"><label for="ct-rep">Replacement within (days)</label><input type="number" min="0" id="ct-rep" name="replacement_days" value="<?= e((string) ($t['replacement_days'] ?? '')) ?>"></div>
        <div class="field"><label for="ct-not">Notice (days)</label><input type="number" min="0" id="ct-not" name="notice_days" value="<?= e((string) ($t['notice_days'] ?? '')) ?>"></div>
      </div>
      <div class="row">
        <div class="field"><label for="ct-c6">Conversion % in 6 months</label><input type="text" inputmode="decimal" id="ct-c6" name="conv_pct_6m" value="<?= e($n2($t['conv_pct_6m'] ?? 8.33)) ?>"></div>
        <div class="field"><label for="ct-c12">6 to 12 months</label><input type="text" inputmode="decimal" id="ct-c12" name="conv_pct_12m" value="<?= e($n2($t['conv_pct_12m'] ?? 4)) ?>"></div>
        <div class="field"><label for="ct-ca">After 12</label><input type="text" inputmode="decimal" id="ct-ca" name="conv_pct_after" value="<?= e($n2($t['conv_pct_after'] ?? 0)) ?>"></div>
      </div>
      <div class="field"><label for="ct-rev">Rate revision</label><input type="text" id="ct-rev" name="rate_revision" value="<?= e($t['rate_revision'] ?? '') ?>" placeholder="e.g. yearly, with the minimum wage revision"></div>
      <div class="field"><label for="ct-note">Note</label><input type="text" id="ct-note" name="note" maxlength="500" value="<?= e($t['note'] ?? '') ?>"></div>
      <button class="btn small" type="submit">Save the terms</button>
    </form>
  </details>
  <?php endif; ?>
  <?php if (count($hist) > 1): ?>
    <details style="margin-top:8px"><summary class="small">Earlier versions (<?= count($hist) - 1 ?>)</summary>
      <ul class="small" style="margin:6px 0 0;padding-left:18px"><?php foreach (array_slice($hist, 1) as $h): ?>
        <li><?= e(nice_date($h['created_at'])) ?> by <?= e($h['by_name'] ?? '?') ?>: <?= e(Staffing::BILLING[$h['billing_model']]) ?><?= $h['billing_model'] === 'markup' ? ' ' . e($n2($h['markup_pct'])) . ' %' : ($h['billing_model'] === 'fixed_fee' ? ' ₹' . e(number_format((float) $h['fixed_fee'])) : '') ?>, due <?= (int) $h['payment_due_days'] ?> days<?= (float) $h['deposit_months'] ? ', deposit ' . e($n2($h['deposit_months'])) . ' months' : '' ?></li>
      <?php endforeach; ?></ul></details>
  <?php endif; ?>
</div>
