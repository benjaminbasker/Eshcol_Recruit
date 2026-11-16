<?php
/** One Claude fit assessment in full. Needs $f (decoded fit row) and $reqs (the role's requirements). */
$res = $f['result'];
$bd = $f['breakdown'];
$markFD = \Eshcol\Ats\Services\FitAssessor::VERDICTS;
$recency = ['current' => 'in the current job', 'recent' => 'recently', 'older' => 'some years ago', 'unknown' => ''];
?>
<div class="breakdown">
  <?php foreach ($bd['parts'] ?? [] as $part): ?>
    <span><?= e($part['label']) ?></span>
    <span><span class="bar"><i style="width:<?= $part['max'] ? round($part['points'] / $part['max'] * 100) : 0 ?>%"></i></span><span class="small muted"><?= e($part['note']) ?></span></span>
    <span class="small nowrap"><?= e(rtrim(rtrim(number_format((float) $part['points'], 1), '0'), '.')) ?>/<?= (int) $part['max'] ?></span>
  <?php endforeach; ?>
</div>
<ul class="evidence" style="margin-top:10px">
  <?php foreach ($reqs as $i => $r): $x = null;
    foreach ((array) ($res['requirements'] ?? []) as $y) { if ((int) $y['number'] === $i + 1) { $x = $y; } }
    $m = $x ? $markFD[\Eshcol\Ats\Services\FitAssessor::verdict($x['verdict'] ?? null)] : ['?', '', 'Not assessed']; ?>
    <li><span class="chip <?= $m[1] ?>" style="text-decoration:none"><?= $m[0] ?> <?= $m[2] ?></span> <b><?= e($r['text']) ?></b><?= $r['critical'] ? ' <span class="small muted">(critical)</span>' : '' ?>
      <?php if ($x && !empty($x['candidate_says'])): $gapNo = ($x['gap_answer'] ?? '') === 'no'; ?> <span class="chip <?= $gapNo ? ($r['critical'] ? 'no' : 'flag') : 'sand' ?>" style="text-decoration:none" title="The candidate's own answer on the application form. The CV doesn't mention it<?= $gapNo ? '' : ', so it counts ' . (($x['gap_answer'] ?? '') === 'some' ? 'a quarter' : 'half') . ' until you check it on the call' ?>."><?= e($x['candidate_says']) ?></span><?php endif; ?>
      <?php if ($x): ?><br><span class="small">&ldquo;<?= e($x['evidence']) ?>&rdquo;<?= !empty($x['quote_planted']) ? ' <span class="chip flag" style="text-decoration:none" title="This line in the CV is written to an AI, not to a recruiter, so it is not proof' . (!empty($x['verdict_claude']) ? ': Claude\'s &quot;proven&quot; counts as half until you check it' : '') . '">⚠ line written to an AI</span>' : (!empty($x['quote_unverified']) ? ' <span class="chip flag" style="text-decoration:none" title="These words could not be found in the CV' . (!empty($x['verdict_claude']) ? ', so Claude\'s &quot;proven&quot; counts as half until you check it' : '') . '">⚠ quote not found in the CV</span>' : '') ?><?= !empty($x['reason']) ? ' <span class="muted">· ' . e($x['reason']) . '</span>' : '' ?><?= ($recency[$x['recency']] ?? '') !== '' ? ' <span class="muted">· ' . e($recency[$x['recency']]) . '</span>' : '' ?></span><?php endif; ?></li>
  <?php endforeach; ?>
</ul>
<p class="small" style="margin:8px 0 0"><b>Relevant experience:</b> <?= e($res['relevant_experience_note'] ?? '') ?><br>
  <b>Seniority:</b> <?= e($res['seniority_note'] ?? '') ?><br>
  <b>Qualification:</b> <?= e($res['qualification_note'] ?? '') ?><br>
  <b>Stability:</b> <?= e($res['stability_note'] ?? '') ?><br>
  <b>Fit:</b> <?= e($res['fit_note'] ?? '') ?><?php if (!empty($res['career_direction'])): ?><br>
  <b>Career direction:</b> <?= e(['rising' => 'Rising', 'flat' => 'Flat', 'falling' => 'Falling', 'unclear' => 'Unclear'][$res['career_direction']] ?? '') ?><?= !empty($res['career_note']) ? ': ' . e($res['career_note']) : '' ?><?php endif; ?><?php if (\Eshcol\Ats\Services\Agreement::extrasOn(\Eshcol\Ats\App::get()) && !empty($res['like_examples']) && isset(\Eshcol\Ats\Services\Examples::LIKE[$res['like_examples']])): ?><br>
  <b>Like your examples:</b> <?= e(\Eshcol\Ats\Services\Examples::LIKE[$res['like_examples']][0]) ?><?= !empty($res['like_note']) ? ': ' . e($res['like_note']) : '' ?> <span class="muted">(shown, not scored)</span><?php endif; ?></p>
<?php if (!empty($res['transferable'])): ?><p class="small" style="margin:8px 0 0"><b>Transferable:</b></p><ul class="small"><?php foreach ($res['transferable'] as $tx): ?><li><?= e($tx) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!empty($res['trainable'])): ?><p class="small" style="margin:8px 0 0"><b>Trainable gaps:</b></p><ul class="small"><?php foreach ($res['trainable'] as $tx): ?><li><?= e($tx) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!empty($res['red_flags'])): ?><p class="small" style="margin:8px 0 0"><b>Red flags:</b></p><ul class="small"><?php foreach ($res['red_flags'] as $rf): ?><li><?= e($rf) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!empty($res['questions'])): ?><p class="small" style="margin:8px 0 0"><b>Ask on the call:</b></p><ul class="small"><?php foreach ($res['questions'] as $qq): ?><li><?= e($qq) ?></li><?php endforeach; ?></ul><?php endif; ?>
<p class="small muted" style="margin:8px 0 0">Assessed by Claude (<?= e($f['model'] ?? '') ?>) on <?= e(nice_date($f['updated_at'] ?? $f['created_at'], true)) ?>. A first view to help you sort: check it against the CV.</p>
