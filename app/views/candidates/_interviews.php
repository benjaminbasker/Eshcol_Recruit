<?php
/** One application's interviews, on the candidate page. @var array $a (application) @var array $c (candidate) */
$db = \Eshcol\Ats\App::get()->db;
$ivSvc = new \Eshcol\Ats\Services\Interviews(\Eshcol\Ats\App::get());
$kinds = \Eshcol\Ats\Services\Interviews::KINDS;
$outcomes = \Eshcol\Ats\Services\Interviews::OUTCOMES;
$list = $db->all("SELECT * FROM interviews WHERE application_id = ? AND status <> 'cancelled' ORDER BY round, id", [$a['id']]);
$next = $ivSvc->nextRound((int) $a['id']);
$open = count($ivSvc->openSlots((int) $a['role_id'], $next));
$client = $db->one('SELECT cl.* FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.id = ?', [$a['role_id']]);
$defaultKind = $next === 1 ? 'virtual' : 'in_person';
$back = '/candidates/' . (int) $c['id'] . '#interviews';
?>
<div class="iv-box">
  <p class="small" style="margin:0 0 6px"><b>Interviews</b></p>
  <?php foreach ($list as $i): ?>
    <div class="iv-row">
      <span class="iv-when"><?= $i['starts_at'] ? '<b>' . date('D j M', strtotime($i['starts_at'])) . '</b><br>' . date('g:i A', strtotime($i['starts_at'])) : '<span class="muted">—</span>' ?></span>
      <div style="flex:1">
        <b>Round <?= (int) $i['round'] ?></b> · <?= e($kinds[$i['kind']]) ?> <span class="ref small"><?= ref_interview($i['id']) ?></span>
        <br><span class="small muted"><?= match ($i['status']) {
            'choosing' => 'Invited to choose a time',
            'booked' => 'Confirmed · call letter sent ' . e(nice_date($i['letter_sent_at'], true)) . ($i['reminded_at'] ? ' · reminded' : ''),
            'done' => 'Done · ' . e($outcomes[$i['outcome']] ?? ''),
            'no_show' => '<b style="color:var(--bad,#b42318)">Did not turn up</b>',
            default => e($i['status']),
        } ?></span>
        <?php if ($i['feedback']): ?><br><span class="small">&ldquo;<?= e($i['feedback']) ?>&rdquo;</span><?php endif; ?>
        <?php if ($i['status'] === 'booked'): ?>
          <details class="small" style="margin-top:4px"><summary>How did it go?</summary>
            <form method="post" action="/interviews/<?= (int) $i['id'] ?>/outcome" style="margin-top:6px"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
              <div class="pub-opts"><?php foreach ($outcomes as $k => $v): ?><label class="pub-opt"><input type="radio" name="outcome" value="<?= $k ?>" required> <span><?= e($v) ?></span></label><?php endforeach; ?></div>
              <textarea name="feedback" rows="2" placeholder="Client's feedback (optional)" style="width:100%;margin-top:6px"></textarea>
              <button class="btn small" type="submit" style="margin-top:6px">Save</button>
              <span class="hint">"Selected" moves them to Offer made; "Not selected" to Not suitable; "Did not turn up" to Withdrew, and marks them unreliable.</span>
            </form>
          </details>
        <?php endif; ?>
      </div>
      <?php if (in_array($i['status'], ['choosing', 'booked'], true)): ?>
        <form method="post" action="/interviews/<?= (int) $i['id'] ?>/cancel"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn link small" type="submit" data-confirm-click="Cancel this interview? The time becomes free again.">Cancel</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$c['do_not_contact'] && !$c['removal_requested_at'] && empty($c['unreliable_at'])): ?>
    <div class="actions" style="margin-top:8px">
      <?php if ($open): ?>
        <form method="post" action="/applications/<?= (int) $a['id'] ?>/interview/invite"><?= csrf() ?><input type="hidden" name="round" value="<?= $next ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
          <button class="btn small" type="submit">Invite to choose a round <?= $next ?> time (<?= $open ?> open)</button></form>
      <?php endif; ?>
    </div>
    <details class="small" style="margin-top:6px"><summary><b>Book round <?= $next ?> directly</b> (a time agreed on the phone)</summary>
      <form method="post" action="/applications/<?= (int) $a['id'] ?>/interview/book" class="book-form"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
        <div class="row" style="align-items:flex-end">
          <div class="field" style="flex:0 1 80px"><label>Round</label><input type="number" name="round" min="1" max="9" value="<?= $next ?>"></div>
          <div class="field" style="flex:0 1 140px"><label>Type</label><select name="kind" data-kind><?php foreach ($kinds as $k => $v): ?><option value="<?= $k ?>"<?= $k === $defaultKind ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
          <div class="field" style="flex:1 1 190px"><label>Date and time</label><input type="datetime-local" name="when" step="900" required></div>
          <div class="field" style="flex:0 1 110px"><label>Length</label><select name="duration"><?php foreach ([30, 45, 60, 90] as $d): ?><option value="<?= $d ?>"><?= $d ?> min</option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label>Meeting link <span class="hint">virtual</span></label><input type="text" name="meeting_link" value="<?= e((string) $client['meeting_link']) ?>" placeholder="meet.google.com/..."></div>
        <div class="row">
          <div class="field" style="flex:2 1 260px"><label>Address <span class="hint">in person</span></label><textarea name="address" rows="2"><?= e((string) $client['interview_address']) ?></textarea></div>
          <div class="field" style="flex:1 1 200px"><label>Google Maps link</label><input type="text" name="maps_url" value="<?= e((string) $client['maps_url']) ?>"></div>
        </div>
        <button class="btn small" type="submit">Book and send the call letter</button>
        <span class="hint">The letter (your format), the JD<?= $defaultKind === 'in_person' ? ', the company profile' : '' ?> and a calendar invite go to the candidate; the client gets a confirmation.</span>
      </form>
    </details>
  <?php endif; ?>
</div>
