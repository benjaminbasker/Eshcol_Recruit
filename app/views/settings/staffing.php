<?php
/** Settings › Staffing (15 Nov 2026). @var array $rates @var array $wages @var int $headcount @var ?array $licence */
use Eshcol\Ats\Services\Staffing;

$unit = ['pct' => '%', 'inr_month' => '₹ / month', 'inr_year' => '₹ / year', 'inr' => '₹'];
$open = count(array_filter($rates, fn ($r) => $r['confirmed_at'] === null));
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings">Settings</a></p><h1>Staffing</h1></div></div>
<p class="small muted" style="margin:-8px 0 16px">What a person on Eshcol's payroll costs each month, and the rules a staffing offer must meet before you approve it. Payroll itself will be in the HRMS.</p>

<section class="card" id="rates">
  <div class="card-head"><h2>Statutory rates</h2><?= $open ? '<span class="chip flag" style="text-decoration:none">' . $open . ' to confirm</span>' : '<span class="chip yes" style="text-decoration:none">All confirmed</span>' ?></div>
  <p class="small">These are suggested figures. Check each against the current rules, correct it if needed, and tick <b>Confirm</b>. Your name and the date are kept. A staffing offer can't be approved while any rate is unconfirmed, and a rate you change needs confirming again.</p>
  <form method="post" action="/settings/staffing/rates"><?= csrf() ?>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Rate</th><th style="width:150px">Value</th><th>Confirmed</th></tr></thead>
      <tbody><?php foreach ($rates as $k => $r): ?>
        <tr>
          <td><b><?= e($r['label']) ?></b><?= $r['note'] ? '<br><span class="small muted">' . e($r['note']) . '</span>' : '' ?></td>
          <td><label class="visually-hidden" for="v-<?= e($k) ?>"><?= e($r['label']) ?></label><input type="text" inputmode="decimal" id="v-<?= e($k) ?>" name="v[<?= e($k) ?>]" value="<?= e(rtrim(rtrim((string) $r['value'], '0'), '.')) ?>" style="width:90px"> <span class="small muted"><?= e($unit[$r['unit']] ?? '') ?></span></td>
          <td class="small"><?php if ($r['confirmed_at']): ?><span class="chip yes" style="text-decoration:none">✓ <?= e($r['confirmer'] ?? '') ?>, <?= e(nice_date($r['confirmed_at'])) ?></span>
            <?php else: ?><label><input type="checkbox" name="confirm[]" value="<?= e($k) ?>"> Confirm</label><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <div class="row" style="align-items:flex-end;margin-top:12px">
      <div class="field" style="flex:0 1 240px"><label for="min_margin">Minimum margin <span class="hint">% of the bill; below it an offer needs your reason</span></label><input type="text" inputmode="decimal" id="min_margin" name="min_margin" value="<?= e(rtrim(rtrim(number_format($minMargin, 2), '0'), '.')) ?>"></div>
      <div class="field"><button class="btn" type="submit">Save</button></div>
    </div>
  </form>
</section>

<section class="card" id="wages">
  <?php $wTo = (int) count(array_filter($wages, fn ($w) => $w['confirmed_at'] === null)); $dTo = (int) count(array_filter($das, fn ($d) => $d['confirmed_at'] === null)); ?>
  <div class="card-head"><h2>Tamil Nadu minimum wages</h2><?= $wTo + $dTo ? '<span class="chip flag" style="text-decoration:none">' . ($wTo + $dTo) . ' to confirm</span>' : '<span class="chip yes" style="text-decoration:none">All confirmed</span>' ?></div>
  <p class="small">The minimum wage is the <b>basic rate</b> (fixed by notification, per class of employee and zone) <b>plus the dearness allowance</b> (revised every 1 April). A daily rate counts as × 30 for the month. Check each row against the notification and tick it; only confirmed rows are used, and an offer below the minimum can't be approved.</p>

  <h3 style="margin-top:14px">Dearness allowance</h3>
  <form method="post" action="/settings/staffing/wages/confirm"><?= csrf() ?>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Scheduled employment</th><th>Per month</th><th>From</th><th>Worked out</th><th>Source</th><th>Confirmed</th></tr></thead>
      <tbody><?php foreach ($das as $d): ?>
        <tr><td><?= e(Staffing::WORK_KINDS[$d['work_kind']] ?? $d['work_kind']) ?></td><td><b>₹<?= e(number_format((float) $d['monthly'], 2)) ?></b></td><td class="nowrap"><?= e(nice_date($d['effective_from'])) ?></td>
          <td class="small"><?= $d['index_points'] !== null ? '(' . e(rtrim(rtrim((string) $d['index_points'], '0'), '.')) . ' − ' . e(rtrim(rtrim((string) $d['base_points'], '0'), '.')) . ' points) × ₹' . e((string) $d['rate_per_point']) : '' ?></td>
          <td class="small"><?= e((string) $d['note']) ?></td>
          <td class="small"><?= $d['confirmed_at'] ? '<span class="chip yes" style="text-decoration:none">✓ ' . e($d['confirmer'] ?? '') . ', ' . e(nice_date($d['confirmed_at'])) . '</span>' : '<label><input type="checkbox" name="da[]" value="' . (int) $d['id'] . '"> Confirm</label>' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>

    <h3 style="margin-top:14px">Basic rates</h3>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Class of employee</th><th>Zone</th><th>Basic per month</th><th>From</th><th>Source</th><th>Confirmed</th></tr></thead>
      <tbody><?php $lastKind = null; foreach ($wages as $w): if ($w['work_kind'] !== $lastKind): $lastKind = $w['work_kind']; ?>
        <tr><td colspan="6" class="small"><b><?= e(Staffing::WORK_KINDS[$w['work_kind']] ?? $w['work_kind']) ?></b></td></tr><?php endif; ?>
        <tr><td><?= e(Staffing::SKILLS[$w['skill_level']] ?? $w['skill_level']) ?></td><td><?= e($w['zone'] ?: 'All') ?></td>
          <td>₹<?= e(number_format((float) $w['monthly'], 2)) ?><?= $w['daily'] !== null ? ' <span class="small muted">(₹' . e(number_format((float) $w['daily'], 2)) . ' a day × 30)</span>' : '' ?></td>
          <td class="nowrap small"><?= e(nice_date($w['effective_from'])) ?></td><td class="small"><?= e((string) $w['note']) ?></td>
          <td class="small"><?= $w['confirmed_at'] ? '<span class="chip yes" style="text-decoration:none">✓ ' . e($w['confirmer'] ?? '') . '</span>' : '<label><input type="checkbox" name="mw[]" value="' . (int) $w['id'] . '"> Confirm</label>' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if ($wTo + $dTo): ?><p class="actions" style="margin-top:10px"><button class="btn" type="submit">Confirm the ticked rows</button>
      <label class="small"><input type="checkbox" data-check-all="mw[]"> Tick every basic rate</label></p><?php endif; ?>
  </form>

  <details style="margin-top:14px"><summary class="small">Add a new DA (each 1 April) or a basic rate (when a notification changes)</summary>
    <form method="post" action="/settings/staffing/da" class="row" style="align-items:flex-end;margin-top:10px"><?= csrf() ?>
      <div class="field"><label for="da-kind">Scheduled employment</label><select id="da-kind" name="work_kind"><?php foreach (Staffing::WORK_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="flex:0 1 130px"><label for="da-amt">DA per month (₹)</label><input type="text" inputmode="decimal" id="da-amt" name="monthly"></div>
      <div class="field" style="flex:0 1 160px"><label for="da-from">From</label><input type="date" id="da-from" name="effective_from" value="<?= date('Y') + (date('n') >= 4 ? 1 : 0) ?>-04-01"></div>
      <div class="field" style="flex:0 1 110px"><label for="da-pts">Index points</label><input type="text" inputmode="decimal" id="da-pts" name="index_points"></div>
      <div class="field"><label for="da-note">Source</label><input type="text" id="da-note" name="note" maxlength="255" placeholder="e.g. Commissioner of Labour statement no."></div>
      <div class="field"><button class="btn ghost" type="submit">Add DA</button></div>
    </form>
    <form method="post" action="/settings/staffing/wages" class="row" style="align-items:flex-end;margin-top:10px"><?= csrf() ?>
      <div class="field"><label for="mw-kind">Scheduled employment</label><select id="mw-kind" name="work_kind"><?php foreach (Staffing::WORK_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="mw-skill">Class of employee</label><select id="mw-skill" name="skill_level"><?php foreach (Staffing::SKILLS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="flex:0 1 90px"><label for="mw-zone">Zone</label><select id="mw-zone" name="zone"><option value="A">A</option><option value="B">B</option></select></div>
      <div class="field" style="flex:0 1 140px"><label for="mw-amt">Basic (₹)</label><input type="text" inputmode="decimal" id="mw-amt" name="amount"></div>
      <div class="field" style="flex:0 1 110px"><label for="mw-per">Per</label><select id="mw-per" name="per"><option value="month">month</option><option value="day">day (× 30)</option></select></div>
      <div class="field" style="flex:0 1 160px"><label for="mw-from">From</label><input type="date" id="mw-from" name="effective_from"></div>
      <div class="field"><label for="mw-note">Source</label><input type="text" id="mw-note" name="note" maxlength="255" placeholder="e.g. the G.O. number"></div>
      <div class="field"><button class="btn ghost" type="submit">Add basic rate</button></div>
    </form>
  </details>
</section>

<section class="card" id="licence">
  <h2>Contract labour licence</h2>
  <p class="small">Needed once <?= Staffing::LICENCE_AT ?> or more people are deputed (OSH Code 2020). People with an approved offer count too. My day warns from <?= Staffing::WARN_AT ?>; past <?= Staffing::LICENCE_AT - 1 ?> no staffing offer can be approved without a licence in date.</p>
  <p><b><?= (int) $headcount ?></b> deputed now <span class="small muted">(at "Joined" on staffing positions)</span> · <b><?= (int) ($about ?? 0) ?></b> about to join <span class="small muted">(approved offers)</span>
    · <?= $licence ? '<span class="chip yes" style="text-decoration:none">Licence ' . e($licence['no']) . ', valid to ' . e(nice_date($licence['valid_to'])) . '</span>' : '<span class="chip">No licence on file</span>' ?></p>
  <form method="post" action="/settings/staffing/licence" class="row" style="align-items:flex-end"><?= csrf() ?>
    <div class="field"><label for="lic-no">Licence number</label><input type="text" id="lic-no" name="licence_no" value="<?= e($licenceNo) ?>"></div>
    <div class="field" style="flex:0 1 170px"><label for="lic-to">Valid to</label><input type="date" id="lic-to" name="licence_valid_to" value="<?= e($licenceTo) ?>"></div>
    <div class="field"><button class="btn ghost" type="submit">Save</button></div>
  </form>
</section>
