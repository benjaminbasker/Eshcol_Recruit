<?php
/**
 * Tick days and times (23 Nov 2026, Benjamin: "make the fix-time interface easier"): the client's free times as
 * buttons, not the browser's clock wheel. Every ticked day gets every ticked time; posts date[], clock[],
 * other_date, other_time (read by Interviews::pickedTimes). @var string $tpId a unique prefix for the ids.
 */
$tpId = $tpId ?? 'tp';
$days = [];
for ($i = 0, $d = strtotime('today'); count($days) < 12 && $i < 20; $i++, $d = strtotime('+1 day', $d)) {
    if (date('N', $d) !== '7') { $days[] = $d; } // Monday to Saturday, the next two weeks
}
$groups = ['Morning' => [9, 12], 'Afternoon' => [12, 17], 'Evening' => [17, 20]];
?>
<div class="tp" data-time-picker>
  <fieldset class="tp-set">
    <legend>1. Days <span class="hint">tick one or more</span></legend>
    <div class="tp-chips">
      <?php foreach ($days as $d): $v = date('Y-m-d', $d); ?>
        <label class="tp-chip tp-day"><input type="checkbox" name="date[]" value="<?= e($v) ?>"><span><b><?= e(date('D', $d)) ?></b> <?= e(date('j M', $d)) ?><?= $v === date('Y-m-d') ? ' <i>today</i>' : '' ?></span></label>
      <?php endforeach; ?>
      <label class="tp-other"><span class="small muted">Another day</span> <input type="date" name="other_date" min="<?= e(date('Y-m-d')) ?>" aria-label="Another day"></label>
    </div>
  </fieldset>
  <fieldset class="tp-set">
    <legend>2. Times <span class="hint">tick each start time the client gave</span></legend>
    <?php foreach ($groups as $label => [$from, $to]): ?>
      <div class="tp-group"><span class="tp-glabel"><?= e($label) ?></span><div class="tp-chips">
        <?php for ($m = $from * 60; $m < $to * 60; $m += 30): $hm = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?>
          <label class="tp-chip"><input type="checkbox" name="clock[]" value="<?= $hm ?>"><span><?= e(date('g:i A', strtotime("2000-01-01 $hm"))) ?></span></label>
        <?php endfor; ?>
      </div></div>
    <?php endforeach; ?>
    <label class="tp-other"><span class="small muted">Another time</span> <input type="time" name="other_time" step="300" aria-label="Another time"></label>
  </fieldset>
  <p class="tp-summary small" aria-live="polite" data-tp-summary></p>
</div>
