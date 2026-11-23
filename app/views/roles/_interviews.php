<?php
/** @var array $role @var array $slots @var array $ivs @var int $clientShortlisted */
$kinds = \Eshcol\Ats\Services\Interviews::KINDS;
$byRound = [];
foreach ($slots as $s) { $byRound[(int) $s['round']][] = $s; }
$upcoming = array_filter($ivs, fn ($i) => in_array($i['status'], ['choosing', 'booked'], true));
// Ready to invite, per round (20 Nov 2026): round 1 = the client's shortlist; later rounds = through the previous one.
// Everyone is ticked; one button sends each a link to choose one of the client's times for that round.
$ivSvcR = new \Eshcol\Ats\Services\Interviews(\Eshcol\Ats\App::get());
$readyBy = [];
for ($r = 1; $r <= 3; $r++) {
    $ready = $ivSvcR->readyFor((int) $role['id'], $r);
    if ($ready) {
        $readyBy[$r] = ['people' => $ready, 'open' => count($ivSvcR->openSlots((int) $role['id'], $r))];
    }
}
?>
<section class="card" id="interviews">
  <div class="actions" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Interviews <a class="small" style="font-weight:600;margin-left:8px" href="/calendar?client=<?= (int) $role['client_id'] ?>">Open the calendar ›</a></h2>
  </div>
  <?php foreach ($readyBy as $r => $rb): ?>
    <div class="card sand" style="margin:10px 0">
      <p style="margin:0 0 4px"><b>Ready for round <?= $r ?>: <?= count($rb['people']) ?> candidate<?= count($rb['people']) === 1 ? '' : 's' ?></b>
        <span class="small muted"><?= $r === 1 ? '· the client marked them Shortlist' : '· through round ' . ($r - 1) ?></span></p>
      <?php if ($rb['open']): ?>
        <form method="post" action="/roles/<?= (int) $role['id'] ?>/interviews/invite"><?= csrf() ?><input type="hidden" name="round" value="<?= $r ?>"><input type="hidden" name="back" value="/roles/<?= (int) $role['id'] ?>#interviews">
          <ul class="iv-ready"><?php foreach ($rb['people'] as $p): ?><li><label><input type="checkbox" name="apps[]" value="<?= (int) $p['application_id'] ?>" checked> <?= e($p['full_name']) ?></label></li><?php endforeach; ?></ul>
          <button class="btn" type="submit" style="white-space:normal;max-width:100%;text-align:left" data-confirm-click="Email the ticked candidates a link to choose one of the <?= (int) $rb['open'] ?> open round <?= $r ?> times?">Invite the ticked to choose a round <?= $r ?> time (<?= (int) $rb['open'] ?> open)</button>
          <span class="hint">Each gets a link; times go first come, first served, and a time taken by one is gone for the others.</span>
        </form>
      <?php else: ?>
        <p class="small" style="margin:4px 0 0"><?= e(implode(', ', array_column($rb['people'], 'full_name'))) ?></p>
        <p class="small" style="margin:6px 0 0"><b>No round <?= $r ?> times yet.</b> Add the times the client gave you below (set Round to <?= $r ?>), then invite them here in one go.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($upcoming): ?>
    <div class="iv-list">
      <?php prime_stages(array_column($upcoming, 'candidate_id')); foreach ($upcoming as $i): ?>
        <div class="iv-row">
          <span class="iv-when"><?= $i['starts_at'] ? '<b>' . date('D j M', strtotime($i['starts_at'])) . '</b><br>' . date('g:i A', strtotime($i['starts_at'])) : '<span class="muted">choosing</span>' ?></span>
          <div><a href="/candidates/<?= (int) $i['candidate_id'] ?>#interviews"><b><?= e($i['full_name']) ?></b></a> <?= stage_tags($i['candidate_id'], (int) $role['id']) ?> <span class="ref small"><?= ref_interview($i['id']) ?></span>
            <br><span class="small muted">Round <?= (int) $i['round'] ?> · <?= e($kinds[$i['kind']]) ?> · <?= $i['status'] === 'booked' ? 'confirmed' . ($i['reminded_at'] ? ', reminded' : '') . (!empty($i['reschedules']) ? ', moved ' . (int) $i['reschedules'] . '×' : '') : 'invited to choose a time' ?></span>
            <?php if ($i['status'] === 'booked'): ?><br><a class="small" href="/candidates/<?= (int) $i['candidate_id'] ?>#interviews">Change the time or cancel ›</a><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <h3 style="margin-top:14px">Times offered</h3>
  <?php if (!$slots): ?><p class="small muted">No times yet. When the client gives you times (or suggests them on their shortlist page), they appear here and candidates can choose one.</p><?php endif; ?>
  <?php foreach ($byRound as $round => $list): ?>
    <p class="small" style="margin:8px 0 4px"><b>Round <?= $round ?></b></p>
    <div class="slot-chips">
      <?php foreach ($list as $s): $t = strtotime($s['starts_at']); ?>
        <span class="slot-chip<?= $s['interview_id'] ? ' taken' : '' ?><?= $t < time() ? ' past' : '' ?>" title="<?= $s['source'] === 'client' ? 'Suggested by the client' : 'Added by you' ?>">
          <?= date('D j M, g:i A', $t) ?> · <?= e($kinds[$s['kind']]) ?><?= $s['interview_id'] ? ' · <b>' . e($s['taken_by'] ?? 'booked') . '</b>' : (!empty($s['clash_by']) ? ' · <span class="chip no" style="text-decoration:none" title="Booked on another of this client\'s positions: not offered to anyone">taken: ' . e($s['clash_by']) . '</span>' : '') ?><?= $s['source'] === 'client' ? ' ★' : '' ?>
          <?php if (!$s['interview_id']): ?><form method="post" action="/slots/<?= (int) $s['id'] ?>/delete" style="display:inline"><?= csrf() ?><button class="x" type="submit" aria-label="Remove this time" title="Remove">×</button></form><?php endif; ?>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <details style="margin-top:12px">
    <summary class="small"><b>Add times the client gave you</b></summary>
    <form method="post" action="/roles/<?= (int) $role['id'] ?>/slots" class="slot-form"><?= csrf() ?>
      <div class="row" style="align-items:flex-end">
        <div class="field" style="flex:0 1 100px"><label for="sl-round">Round</label><input type="number" id="sl-round" name="round" min="1" max="9" value="1"></div>
        <div class="field" style="flex:0 1 150px"><label for="sl-kind">Type</label><select id="sl-kind" name="kind"><?php foreach ($kinds as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="field" style="flex:0 1 130px"><label for="sl-dur">Length</label><select id="sl-dur" name="duration"><?php foreach ([30, 45, 60, 90] as $d): ?><option value="<?= $d ?>"<?= $d === 30 ? ' selected' : '' ?>><?= $d ?> min</option><?php endforeach; ?></select></div>
      </div>
      <?php $tpId = 'sl'; include __DIR__ . '/../partials/time_picker.php'; ?>
      <button class="btn small" type="submit">Add these times</button>
      <p class="hint" style="margin:6px 0 0">Round 1 is usually virtual (the client's meeting link is used) and round 2 in person (the client's address and map link).</p>
    </form>
  </details>
</section>
