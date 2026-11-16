<?php
/** Placement box (B9): the owner's only. @var array $p the placement @var array $a the application */
$psvc = new \Eshcol\Ats\Services\Placements(\Eshcol\Ats\App::get());
$money = $psvc->moneyStatus($p);
$auto = \Eshcol\Ats\Services\Placements::fee($p['fee_type'], $p['fee_value'], $p['offer_ctc_lpa'], (bool) $p['replacement_of']);
$rs = fn ($v) => $v === null || $v === '' ? '' : '₹' . number_format((float) $v, 0, '.', ',');
$basis = $p['fee_type'] === 'percent' ? rtrim(rtrim((string) $p['fee_value'], '0'), '.') . '% of the total annual CTC' : ($p['fee_type'] === 'fixed' ? 'a fixed fee' : null);
$pid = (int) $p['id'];
$tone = ['to_invoice' => 'flag', 'overdue' => 'bad', 'paid' => 'yes', 'invoiced' => 'sand', 'none' => ''];
?>
<div class="placement-box" id="placement-<?= $pid ?>">
  <h4><?= icon('check', 'sm') ?> Placement <span class="chip"><?= e(\Eshcol\Ats\Services\Placements::STATUS[$p['status']] ?? $p['status']) ?></span>
    <?php if ($money !== ''): ?><span class="chip <?= $tone[$money] ?? '' ?>"><?= e(\Eshcol\Ats\Services\Placements::MONEY[$money]) ?></span><?php endif; ?>
    <span class="small muted">only you see this</span></h4>
  <?php if ($p['replacement_of']): ?><p class="small" style="margin:0 0 8px">Replacement for someone who left within the guarantee: <b>no new fee</b>.</p><?php endif; ?>
  <form method="post" action="/placements/<?= $pid ?>"><?= csrf() ?>
    <div class="pl-grid">
      <div class="field"><label for="pod<?= $pid ?>">Offer date</label><input type="date" id="pod<?= $pid ?>" name="offer_date" value="<?= e((string) $p['offer_date']) ?>"></div>
      <div class="field"><label for="pct<?= $pid ?>">Offered annual CTC <span class="hint">lakhs, total incl. variable</span></label><input type="text" inputmode="decimal" id="pct<?= $pid ?>" name="offer_ctc_lpa" value="<?= e((string) ($p['offer_ctc_lpa'] !== null ? rtrim(rtrim((string) $p['offer_ctc_lpa'], '0'), '.') : '')) ?>" placeholder="e.g. 6.5"></div>
      <div class="field"><label for="pej<?= $pid ?>">Expected joining</label><input type="date" id="pej<?= $pid ?>" name="expected_join" value="<?= e((string) $p['expected_join']) ?>"></div>
      <div class="field"><label for="pjo<?= $pid ?>">Joined on</label><input type="date" id="pjo<?= $pid ?>" name="joined_on" value="<?= e((string) $p['joined_on']) ?>"></div>
      <div class="field"><label for="pfe<?= $pid ?>">Fee (₹, before GST)</label><input type="text" inputmode="decimal" id="pfe<?= $pid ?>" name="fee_amount" value="<?= $p['fee_amount'] !== null ? (int) round((float) $p['fee_amount']) : '' ?>">
        <input type="hidden" name="fee_auto" value="<?= $auto !== null ? (int) $auto : '' ?>">
        <p class="hint"><?= $p['replacement_of'] ? 'No fee: a replacement.' : ($basis ? 'Client terms: ' . e($basis) . ($auto !== null ? ' = ' . e($rs($auto)) : ' (add the offered CTC)') . '.' : 'No fee terms on the client yet.') ?><?= $p['fee_overridden'] ? ' <b>Your own figure.</b> Clear it to go back to the terms.' : '' ?></p></div>
      <div class="field"><label for="pin<?= $pid ?>">Invoice number <span class="hint">from Zoho Books</span></label><input type="text" id="pin<?= $pid ?>" name="invoice_no" maxlength="60" value="<?= e((string) $p['invoice_no']) ?>"></div>
      <div class="field"><label for="pio<?= $pid ?>">Invoiced on</label><input type="date" id="pio<?= $pid ?>" name="invoiced_on" value="<?= e((string) $p['invoiced_on']) ?>">
        <?php if ($due = $psvc->dueOn($p)): ?><p class="hint">Payment due <?= e(nice_date($due)) ?> (<?= (int) ($p['payment_terms_days'] ?? 30) ?> days).</p><?php endif; ?></div>
      <div class="field"><label for="ppo<?= $pid ?>">Paid on</label><input type="date" id="ppo<?= $pid ?>" name="paid_on" value="<?= e((string) $p['paid_on']) ?>"></div>
    </div>
    <p class="small muted" style="margin:4px 0 8px"><?= $p['guarantee_until'] ? 'Replacement guarantee until <b>' . e(nice_date($p['guarantee_until'])) . '</b>.' : ($p['joined_on'] ? 'No replacement guarantee set on the client.' : 'The guarantee starts from the joining date.') ?></p>
    <button class="btn small" type="submit">Save placement</button>
  </form>
  <?php if ($p['status'] === 'joined' && $p['joined_on']): ?>
    <div class="row" style="margin-top:10px;gap:8px;align-items:center">
      <?php foreach ([30, 60] as $d): $dueAt = date('Y-m-d', strtotime($p['joined_on'] . " +$d days")); ?>
        <?php if ($p["checkin{$d}_at"]): ?><span class="chip yes"><?= $d ?>-day check-in done</span>
        <?php elseif ($dueAt <= date('Y-m-d')): ?><form method="post" action="/placements/<?= $pid ?>/checkin/<?= $d ?>"><?= csrf() ?><button class="btn ghost small" type="submit">Still there at <?= $d ?> days</button></form><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <details class="small" style="margin-top:10px"><summary>They left</summary>
      <form method="post" action="/placements/<?= $pid ?>/left" class="row" style="gap:8px;align-items:flex-end;margin-top:8px"><?= csrf() ?>
        <div class="field" style="margin:0"><label for="plo<?= $pid ?>">Left on</label><input type="date" id="plo<?= $pid ?>" name="left_on" value="<?= date('Y-m-d') ?>"></div>
        <div class="field" style="margin:0;flex:1"><label for="pln<?= $pid ?>">What happened</label><input type="text" id="pln<?= $pid ?>" name="left_note" maxlength="255"></div>
        <button class="btn small" type="submit" data-confirm-click="<?= $p['guarantee_until'] && date('Y-m-d') <= $p['guarantee_until'] ? 'Within the guarantee: this reopens the position as a replacement, with no new fee. Go ahead?' : 'Record that they left? (The guarantee has ended, so the position stays as it is.)' ?>">Record it</button>
      </form>
    </details>
  <?php elseif ($p['status'] === 'left'): ?>
    <p class="small" style="margin:8px 0 0">Left on <?= e(nice_date($p['left_on'])) ?><?= $p['left_note'] ? ': ' . e($p['left_note']) : '' ?>.</p>
  <?php endif; ?>
</div>
