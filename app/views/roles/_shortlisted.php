<?php
/** The "Shortlisted" panel at the top of a position's Pipeline (16 Nov 2026). Needs $role. */
$shortSvc = new \Eshcol\Ats\Services\Shortlists(\Eshcol\Ats\App::get());
$shortRows = $shortSvc->shortlistedOn((int) $role['id']);
if (!$shortRows) {
    return;
}
$ready = array_filter($shortRows, fn ($r) => \Eshcol\Ats\Services\Stages::is(\Eshcol\Ats\App::get(), (int) $r['stage_id'], \Eshcol\Ats\Services\Stages::SHORTLISTED) && !$r['sent_at']);
$canSend = \Eshcol\Ats\Services\Engagement::of($role) !== 'inhouse';
$bandTone = ['Strong' => 'yes', 'Possible' => 'sand', 'Weak' => 'no'];
$shortKf = (new \Eshcol\Ats\Services\KeyFacts(\Eshcol\Ats\App::get()))->forCandidates(array_column($shortRows, 'candidate_id'));
?>
<div class="card short-panel" id="shortlisted">
  <div class="card-head">
    <h2><?= icon('check') ?> Shortlisted (<?= count($shortRows) ?>)</h2>
    <?php if ($canSend): ?>
      <a class="btn<?= $ready ? '' : ' ghost' ?>" href="/roles/<?= (int) $role['id'] ?>/shortlist/new"><?= icon('send', 'sm') ?>Send to client<?= $ready ? ' (' . count($ready) . ' ready)' : '' ?></a>
    <?php endif; ?>
  </div>
  <p class="small muted" style="margin:0 0 8px">Everyone at Shortlisted or a later stage. <?= $canSend ? '"Send to client" opens the shortlist with the ones not yet sent already ticked.' : '' ?></p>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Candidate</th><th>Stage</th><th>Claude</th><th>Sent to client</th></tr></thead>
    <tbody><?php foreach ($shortRows as $r): ?>
      <tr class="short">
        <td><a href="/candidates/<?= (int) $r['candidate_id'] ?>?role=<?= (int) $role['id'] ?>"><b><?= e($r['full_name'] ?: 'Name not found') ?></b></a> <span class="ref"><?= ref_candidate($r['candidate_id']) ?></span>
          <?php $sk = $shortKf[(int) $r['candidate_id']] ?? []; if ($sk): ?><br><?= \Eshcol\Ats\Services\KeyFacts::line($sk) ?> <?= \Eshcol\Ats\Services\KeyFacts::cvButton($sk) ?><?php endif; ?></td>
        <td><span class="chip shorted" style="text-decoration:none"><?= e($r['stage']) ?></span></td>
        <td><?= $r['score'] !== null ? '<span class="chip ' . ($bandTone[$r['band']] ?? '') . '" style="text-decoration:none">' . (int) $r['score'] . ' · ' . e($r['band']) . '</span>' : '<span class="muted">–</span>' ?></td>
        <td class="small"><?= $r['sent_at'] ? e(nice_date($r['sent_at'])) : '<span class="muted">not yet</span>' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
