<?php
/** @var array $role @var array $slots @var array $ivs @var int $clientShortlisted */
$kinds = \Eshcol\Ats\Services\Interviews::KINDS;
$byRound = [];
foreach ($slots as $s) { $byRound[(int) $s['round']][] = $s; }
$upcoming = array_filter($ivs, fn ($i) => in_array($i['status'], ['choosing', 'booked'], true));
?>
<section class="card" id="interviews">
  <div class="actions" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Interviews <a class="small" style="font-weight:600;margin-left:8px" href="/calendar?client=<?= (int) $role['client_id'] ?>">Open the calendar ›</a></h2>
    <?php if ($clientShortlisted): ?>
      <form method="post" action="/roles/<?= (int) $role['id'] ?>/interviews/invite"><?= csrf() ?><input type="hidden" name="round" value="1">
        <button class="btn small" type="submit" data-confirm-click="Email everyone the client marked Shortlist a link to choose a round 1 time?">Invite the client's shortlist to choose a time</button></form>
    <?php endif; ?>
  </div>
  <?php if ($upcoming): ?>
    <div class="iv-list">
      <?php prime_stages(array_column($upcoming, 'candidate_id')); foreach ($upcoming as $i): ?>
        <div class="iv-row">
          <span class="iv-when"><?= $i['starts_at'] ? '<b>' . date('D j M', strtotime($i['starts_at'])) . '</b><br>' . date('g:i A', strtotime($i['starts_at'])) : '<span class="muted">choosing</span>' ?></span>
          <div><a href="/candidates/<?= (int) $i['candidate_id'] ?>#interviews"><b><?= e($i['full_name']) ?></b></a> <?= stage_tags($i['candidate_id'], (int) $role['id']) ?> <span class="ref small"><?= ref_interview($i['id']) ?></span>
            <br><span class="small muted">Round <?= (int) $i['round'] ?> · <?= e($kinds[$i['kind']]) ?> · <?= $i['status'] === 'booked' ? 'confirmed' . ($i['reminded_at'] ? ', reminded' : '') : 'invited to choose a time' ?></span></div>
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
      <div class="row">
        <?php for ($k = 0; $k < 5; $k++): ?><div class="field" style="flex:1 1 200px"><label for="sl-t<?= $k ?>">Time <?= $k + 1 ?></label><input type="datetime-local" id="sl-t<?= $k ?>" name="times[]" step="900"></div><?php endfor; ?>
      </div>
      <button class="btn small" type="submit">Add these times</button>
      <p class="hint" style="margin:6px 0 0">Round 1 is usually virtual (the client's meeting link is used) and round 2 in person (the client's address and map link).</p>
    </form>
  </details>
</section>
