<?php
/** @var array $bd decoded score_breakdown */
if (!$bd) { echo '<p class="muted small">Not scored yet.</p>'; return; }
$must = $bd['parts']['must'] ?? null;
?>
<div class="breakdown">
  <?php foreach ($bd['parts'] as $part): ?>
    <span><?= e($part['label']) ?></span>
    <span><span class="bar"><i style="width:<?= $part['max'] ? round($part['points'] / $part['max'] * 100) : 0 ?>%"></i></span><span class="small muted"><?= e($part['note']) ?></span></span>
    <span class="small nowrap"><?= e(rtrim(rtrim(number_format((float) $part['points'], 1), '0'), '.')) ?>/<?= (int) $part['max'] ?></span>
  <?php endforeach; ?>
</div>
<?php if ($must && ($must['matched'] || $must['missing'])): ?>
  <p style="margin:12px 0 4px">
    <?php foreach ($must['matched'] as $m): ?><span class="chip yes"><?= e($m['value']) ?></span><?php endforeach; ?>
    <?php foreach ($must['missing'] as $m): ?><span class="chip no" title="Not found in the CV"><?= e($m) ?></span><?php endforeach; ?>
    <?php foreach ($bd['nice'] ?? [] as $n): ?><span class="chip sand" title="Nice-to-have">+ <?= e($n) ?></span><?php endforeach; ?>
  </p>
  <?php if ($must['matched']): ?>
    <ul class="evidence">
      <?php foreach ($must['matched'] as $m): ?><li><?= $m['snippet'] ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
<?php if (!empty($bd['unknown'])): ?>
  <p class="small" style="margin:8px 0 0"><span class="confirm">confirm</span> Not found in the CV: <?= e(implode(', ', $bd['unknown'])) ?>. Scored at half until confirmed.</p>
<?php endif; ?>
