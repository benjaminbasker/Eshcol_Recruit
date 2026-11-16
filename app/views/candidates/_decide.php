<?php
/**
 * Decide (15 Nov 2026, own-hiring and staffing plan v2, phase 1): interview feedback per round, reference checks and,
 * for our own team and staffing, the offer Eshcol makes and its approval. One application. @var array $a @var array $c
 */
$ddb = \Eshcol\Ats\App::get()->db;
$fb = $ddb->all('SELECT f.*, u.name AS by_name FROM interview_feedback f LEFT JOIN users u ON u.id = f.user_id WHERE f.application_id = ? ORDER BY f.round, f.id', [$a['id']]);
$refs = $ddb->all('SELECT * FROM reference_checks WHERE application_id = ? ORDER BY id', [$a['id']]);
$eshcolOffer = in_array(\Eshcol\Ats\Services\Engagement::of($a), ['inhouse', 'staffing'], true);
$isStaffingD = \Eshcol\Ats\Services\Engagement::of($a) === 'staffing';
$stSvc = $isStaffingD ? new \Eshcol\Ats\Services\Staffing(\Eshcol\Ats\App::get()) : null;
$offer = $eshcolOffer ? $ddb->one('SELECT o.*, u.name AS approver FROM offer_approvals o LEFT JOIN users u ON u.id = o.approved_by WHERE o.application_id = ?', [$a['id']]) : null;
$isOwnerD = (($user ?? [])['role'] ?? '') === 'owner';
$rec = \Eshcol\Ats\Controllers\DecisionController::RECOMMEND;
$outc = \Eshcol\Ats\Controllers\DecisionController::OUTCOME;
$nextRound = $fb ? max(array_column($fb, 'round')) : 1;
?>
<details class="decide" id="decide-<?= (int) $a['id'] ?>"<?= $fb || $refs || $offer ? ' open' : '' ?> style="margin-top:12px">
  <summary class="small"><b>Decide</b> <span class="muted">· interview feedback (<?= count($fb) ?>) · references (<?= count($refs) ?>)<?= $eshcolOffer ? ' · offer: ' . ($offer && $offer['approved_at'] ? 'approved' : ($offer ? 'waiting for approval' : 'not prepared')) : '' ?></span></summary>

  <?php foreach ($fb as $f): ?>
    <div class="dec-row">
      <b>Round <?= (int) $f['round'] ?></b> · <?= e($f['interviewer'] ?: ($f['by_name'] ?? '')) ?>
      <?= $f['rating'] ? ' · <span title="Rating out of 5">' . str_repeat('★', (int) $f['rating']) . str_repeat('☆', 5 - (int) $f['rating']) . '</span>' : '' ?>
      <?= $f['recommend'] ? ' · <span class="chip ' . ['yes' => 'yes', 'maybe' => 'sand', 'no' => 'no'][$f['recommend']] . '" style="text-decoration:none">' . e($rec[$f['recommend']]) . '</span>' : '' ?>
      <span class="small muted"> · <?= e(nice_date($f['created_at'])) ?></span>
      <?php if ($f['strengths']): ?><br><span class="small"><b>Strengths:</b> <?= nl2br(e($f['strengths'])) ?></span><?php endif; ?>
      <?php if ($f['concerns']): ?><br><span class="small"><b>Concerns:</b> <?= nl2br(e($f['concerns'])) ?></span><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <details style="margin:8px 0"><summary class="small">Add interview feedback</summary>
    <form method="post" action="/applications/<?= (int) $a['id'] ?>/feedback" style="margin-top:8px"><?= csrf() ?>
      <div class="row">
        <div class="field" style="flex:0 1 90px"><label for="fr-<?= (int) $a['id'] ?>">Round</label><input type="number" min="1" max="9" id="fr-<?= (int) $a['id'] ?>" name="round" value="<?= (int) $nextRound ?>"></div>
        <div class="field"><label for="fi-<?= (int) $a['id'] ?>">Interviewer</label><input type="text" id="fi-<?= (int) $a['id'] ?>" name="interviewer" placeholder="<?= e(($user ?? [])['name'] ?? '') ?>"></div>
        <div class="field" style="flex:0 1 130px"><label for="fg-<?= (int) $a['id'] ?>">Rating</label><select id="fg-<?= (int) $a['id'] ?>" name="rating"><option value="">–</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> of 5</option><?php endfor; ?></select></div>
        <div class="field" style="flex:0 1 150px"><label for="fc-<?= (int) $a['id'] ?>">Recommend</label><select id="fc-<?= (int) $a['id'] ?>" name="recommend"><option value="">–</option><?php foreach ($rec as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label for="fs-<?= (int) $a['id'] ?>">Strengths</label><textarea id="fs-<?= (int) $a['id'] ?>" name="strengths" rows="2"></textarea></div>
      <div class="field"><label for="fn-<?= (int) $a['id'] ?>">Concerns</label><textarea id="fn-<?= (int) $a['id'] ?>" name="concerns" rows="2"></textarea></div>
      <button class="btn small" type="submit">Save feedback</button>
    </form>
  </details>

  <?php foreach ($refs as $r): ?>
    <div class="dec-row">
      <b><?= e($r['referee']) ?></b><?= $r['relation'] ? ' · ' . e($r['relation']) : '' ?><?= $r['contact'] ? ' · <span class="small">' . e($r['contact']) . '</span>' : '' ?>
      <?= $r['outcome'] ? ' · <span class="chip ' . ['positive' => 'yes', 'mixed' => 'sand', 'negative' => 'no'][$r['outcome']] . '" style="text-decoration:none">' . e($outc[$r['outcome']]) . '</span>' : '' ?>
      <span class="small muted"> · <?= e(nice_date($r['checked_on'])) ?></span>
      <?php if ($r['summary']): ?><br><span class="small"><?= nl2br(e($r['summary'])) ?></span><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <details style="margin:8px 0"><summary class="small">Add a reference check</summary>
    <form method="post" action="/applications/<?= (int) $a['id'] ?>/reference" style="margin-top:8px"><?= csrf() ?>
      <div class="row">
        <div class="field"><label for="rr-<?= (int) $a['id'] ?>">Referee</label><input type="text" id="rr-<?= (int) $a['id'] ?>" name="referee" required></div>
        <div class="field"><label for="rl-<?= (int) $a['id'] ?>">How they know the candidate</label><input type="text" id="rl-<?= (int) $a['id'] ?>" name="relation" placeholder="e.g. Manager at Sharma &amp; Co"></div>
      </div>
      <div class="row">
        <div class="field"><label for="rc-<?= (int) $a['id'] ?>">Phone or email</label><input type="text" id="rc-<?= (int) $a['id'] ?>" name="contact"></div>
        <div class="field" style="flex:0 1 150px"><label for="ro-<?= (int) $a['id'] ?>">Overall</label><select id="ro-<?= (int) $a['id'] ?>" name="outcome"><option value="">–</option><?php foreach ($outc as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="flex:0 1 170px"><label for="rd-<?= (int) $a['id'] ?>">Checked on</label><input type="date" id="rd-<?= (int) $a['id'] ?>" name="checked_on" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="field"><label for="rs-<?= (int) $a['id'] ?>">What they said</label><textarea id="rs-<?= (int) $a['id'] ?>" name="summary" rows="2"></textarea></div>
      <button class="btn small" type="submit">Save reference</button>
    </form>
  </details>

  <?php if ($eshcolOffer): ?>
    <div class="card sand" style="margin:10px 0 0">
      <p class="small" style="margin:0 0 8px"><b>Offer from Eshcol</b> ·
        <?= $offer && $offer['approved_at'] ? '<span class="chip yes" style="text-decoration:none">Approved by ' . e($offer['approver'] ?? '') . ', ' . e(nice_date($offer['approved_at'])) . '</span>'
            : '<span class="chip flag" style="text-decoration:none">Needs approval before "Offer made"</span>' ?></p>
      <form method="post" action="/applications/<?= (int) $a['id'] ?>/offer"><?= csrf() ?>
        <div class="row">
          <div class="field"><label for="od-<?= (int) $a['id'] ?>">Designation</label><input type="text" id="od-<?= (int) $a['id'] ?>" name="designation" value="<?= e($offer['designation'] ?? $a['title']) ?>"></div>
          <?php if ($isStaffingD): ?><div class="field" style="flex:0 1 170px"><label for="og-<?= (int) $a['id'] ?>">Monthly gross pay (₹)</label><input type="text" inputmode="decimal" id="og-<?= (int) $a['id'] ?>" name="gross_month" value="<?= e($offer['gross_month'] ?? '') ?>" placeholder="e.g. 18000"></div>
          <?php else: ?><div class="field" style="flex:0 1 160px"><label for="oc-<?= (int) $a['id'] ?>">Annual CTC (LPA)</label><input type="text" inputmode="decimal" id="oc-<?= (int) $a['id'] ?>" name="ctc_lpa" value="<?= e($offer['ctc_lpa'] ?? '') ?>" placeholder="e.g. 4.2"></div><?php endif; ?>
          <div class="field" style="flex:0 1 170px"><label for="oj-<?= (int) $a['id'] ?>">Joining date</label><input type="date" id="oj-<?= (int) $a['id'] ?>" name="joining_date" value="<?= e($offer['joining_date'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="on-<?= (int) $a['id'] ?>">Note</label><input type="text" id="on-<?= (int) $a['id'] ?>" name="note" value="<?= e($offer['note'] ?? '') ?>" maxlength="500"></div>
        <?php if ($isStaffingD): [$stProblems, $stCalc, $stTerms] = $stSvc->check((int) $a['id'], $offer && $offer['gross_month'] !== null ? (float) $offer['gross_month'] : null, $offer['margin_reason'] ?? null);
          if ($stCalc): $rs = fn ($v) => '₹' . number_format((float) $v, 2); ?>
          <div class="table-wrap"><table class="list compact" style="margin:4px 0 8px">
            <tr><td>Monthly gross pay</td><td class="num"><?= $rs($stCalc['gross']) ?></td></tr>
            <tr><td class="small muted">Wages for PF, bonus and gratuity: <?= $stCalc['wage_rule'] === 'gross' ? 'share of gross (common practice)' : 'basic + DA, at least the minimum wage (the law)' ?></td><td class="num small muted"><?= $rs($stCalc['wage']) ?></td></tr>
            <?php $who = ['bill' => 'in the bill', 'proof' => 'on proof', 'eshcol' => 'Eshcol'];
            foreach (\Eshcol\Ats\Services\Staffing::GROUPS as $gk => $gl): $rows = array_filter(\Eshcol\Ats\Services\Staffing::COSTS, fn ($c, $k) => $c[1] === $gk && (float) $stCalc['parts'][$k] != 0.0, ARRAY_FILTER_USE_BOTH); if (!$rows) { continue; } ?>
              <tr><td colspan="2" class="small muted" style="padding-top:6px"><?= e($gl) ?></td></tr>
              <?php foreach ($rows as $pk => [$pl]): ?><tr><td class="small">+ <?= e($pl) ?> <span class="chip" style="text-decoration:none"><?= e($who[$stCalc['payers'][$pk]]) ?></span></td><td class="num small"><?= $rs($stCalc['parts'][$pk]) ?></td></tr><?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ((float) $stCalc['parts']['overhead'] != 0.0): ?><tr><td class="small">+ Eshcol overhead <span class="chip" style="text-decoration:none">Eshcol</span></td><td class="num small"><?= $rs($stCalc['parts']['overhead']) ?></td></tr><?php endif; ?>
            <tr><td><b>Cost to Eshcol</b></td><td class="num"><b><?= $rs($stCalc['cost']) ?></b></td></tr>
            <tr><td colspan="2" class="small muted" style="padding-top:6px">What the client pays</td></tr>
            <tr><td class="small">Monthly bill: wages, the costs in the bill and Eshcol's fee <?= $rs($stCalc['fee']) ?><?= $stCalc['markup_base'] ? ' (on ' . e(strtolower(\Eshcol\Ats\Services\Staffing::MARKUP_BASE[$stCalc['markup_base']])) . ')' : '' ?> <span class="muted">+ GST <?= $rs($stCalc['monthly_gst']) ?></span></td><td class="num small"><?= $rs($stCalc['monthly_bill']) ?></td></tr>
            <?php if ((float) $stCalc['reimbursed'] != 0.0): ?><tr><td class="small">Reimbursed against proof of payment<?= (float) $stCalc['reimbursed_monthly'] != (float) $stCalc['reimbursed'] ? ' (' . $rs($stCalc['reimbursed_monthly']) . ' monthly, the rest when paid yearly or on leaving)' : ', monthly' ?> <span class="muted">+ GST</span></td><td class="num small"><?= $rs($stCalc['reimbursed']) ?></td></tr><?php endif; ?>
            <tr><td><b>Bill to the client</b>, all of it <span class="small muted">+ GST <?= $rs($stCalc['gst']) ?> = <?= $rs($stCalc['invoice']) ?></span></td><td class="num"><b><?= $rs($stCalc['bill']) ?></b></td></tr>
            <tr><td><b>Margin</b></td><td class="num"><b style="color:<?= $stCalc['margin_pct'] < $stSvc->minMarginPct() ? 'var(--red)' : 'var(--green,#26733A)' ?>"><?= $rs($stCalc['margin']) ?> (<?= e((string) $stCalc['margin_pct']) ?> %)</b></td></tr>
            <?php if (!empty($stTerms['overridden'])): ?><tr><td colspan="2" class="small muted">Billed on this position's own fee (<?= e(\Eshcol\Ats\Services\Staffing::BILLING[$stTerms['billing_model']] ?? '') ?>), not the client's usual terms.</td></tr><?php endif; ?>
            <?php if (!empty($stCalc['min_wage'])): $mwv = $stCalc['min_wage']; ?><tr><td class="small muted">Tamil Nadu minimum wage<?= $mwv['zone'] ? ', Zone ' . e($mwv['zone']) : '' ?> (basic <?= $rs($mwv['basic']) ?> + DA <?= $rs($mwv['da']) ?>)</td><td class="num small"><?= $rs($mwv['total']) ?></td></tr><?php endif; ?>
            <?php if ((float) $stCalc['law_extra'] > 0): ?><tr><td colspan="2" class="small" style="color:var(--amber,#9A6700)">Wages here are below the minimum wage of <?= $rs($stCalc['law_wage']) ?>. If an inspector applies the law, PF, bonus and gratuity cost <?= $rs($stCalc['law_extra']) ?> a month more.</td></tr><?php endif; ?>
            <tr><td class="small muted">Annual CTC in the offer</td><td class="num small"><?= e(lpa($stCalc['ctc_lpa'])) ?></td></tr>
          </table></div>
          <?php endif; ?>
          <div class="field"><label for="om-<?= (int) $a['id'] ?>">Reason, if the margin is below <?= e(rtrim(rtrim(number_format($stSvc->minMarginPct(), 2), '0'), '.')) ?> %</label><input type="text" id="om-<?= (int) $a['id'] ?>" name="margin_reason" maxlength="255" value="<?= e($offer['margin_reason'] ?? '') ?>"></div>
          <?php if ($stProblems && !($offer['approved_at'] ?? null)): ?><ul class="small" style="color:var(--red);margin:0 0 8px;padding-left:18px"><?php foreach ($stProblems as $sp): ?><li><?= e($sp) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php endif; ?>
        <?php if ($isOwnerD): ?><label class="small" style="display:block;margin:0 0 8px"><input type="checkbox" name="approve" value="1"<?= $offer && $offer['approved_at'] ? ' checked' : '' ?>> Approved</label><?php endif; ?>
        <button class="btn small" type="submit">Save the offer</button>
        <?php if (!$isOwnerD): ?><span class="small muted">The owner approves it.</span><?php endif; ?>
      </form>
    </div>
    <?php if ($offer && $offer['approved_at']): $got = array_column($ddb->all('SELECT item, received_on FROM joining_items WHERE application_id = ?', [$a['id']]), 'received_on', 'item'); $all = \Eshcol\Ats\Services\Staffing::JOINING; ?>
    <div class="card" style="margin:10px 0 0">
      <p class="small" style="margin:0 0 6px"><b>Joining checklist</b> · <?= count($got) ?> of <?= count($all) ?> received<?= count($got) >= count($all) ? ' <span class="chip yes" style="text-decoration:none">ready to join</span>' : '' ?></p>
      <p class="hint" style="margin:0 0 8px">Tick what you have received. Only the tick and the date are kept here, never the numbers; the documents themselves go to the HRMS. "Joined" needs every one.</p>
      <form method="post" action="/applications/<?= (int) $a['id'] ?>/joining"><?= csrf() ?>
        <?php foreach ($all as $jk => $jl): ?><label class="small" style="display:block;margin:2px 0"><input type="checkbox" name="items[]" value="<?= e($jk) ?>"<?= isset($got[$jk]) ? ' checked' : '' ?>> <?= e($jl) ?><?= isset($got[$jk]) ? ' <span class="muted">· ' . e(nice_date($got[$jk])) . '</span>' : '' ?></label><?php endforeach; ?>
        <div class="row" style="align-items:flex-end;margin-top:6px"><div class="field" style="flex:0 1 170px"><label for="jd-<?= (int) $a['id'] ?>">Received on</label><input type="date" id="jd-<?= (int) $a['id'] ?>" name="received_on" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><button class="btn small" type="submit">Save the checklist</button></div></div>
      </form>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</details>
