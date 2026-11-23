<?php
/** One application's interviews, on the candidate page. @var array $a (application) @var array $c (candidate) */
$db = \Eshcol\Ats\App::get()->db;
$ivSvc = new \Eshcol\Ats\Services\Interviews(\Eshcol\Ats\App::get());
$kinds = \Eshcol\Ats\Services\Interviews::KINDS;
$outcomes = \Eshcol\Ats\Services\Interviews::OUTCOMES;
$list = $db->all("SELECT * FROM interviews WHERE application_id = ? AND status <> 'cancelled' ORDER BY round, id", [$a['id']]);
$next = $ivSvc->nextRound((int) $a['id']);
// Picked "Client interview · Round N" in the stage drop-down with no times from the client yet: the form opens at N.
$wantRound = (int) ($_GET['app'] ?? 0) === (int) $a['id'] ? (int) ($_GET['round'] ?? 0) : 0;
$next = $wantRound > $next && $wantRound <= 3 ? $wantRound : $next;
$open = count($ivSvc->openSlots((int) $a['role_id'], $next));
$client = $db->one('SELECT cl.* FROM roles r JOIN clients cl ON cl.id = r.client_id WHERE r.id = ?', [$a['role_id']]);
$defaultKind = $next === 1 ? 'virtual' : 'in_person';
$back = '/candidates/' . (int) $c['id'] . '#interviews';
// The next round already has an invitation or a booking: it is changed from its own row, not booked again (20 Nov 2026).
$nextActive = array_values(array_filter($list, fn ($i) => (int) $i['round'] === $next && in_array($i['status'], ['choosing', 'booked'], true)));
$canContact = !$c['do_not_contact'] && !$c['removal_requested_at'] && empty($c['unreliable_at']) && empty($c['red_listed_at']);
$whereFields = function (array $v) use ($client): string {
    ob_start(); ?>
    <div class="field"><label>Meeting link <span class="hint">virtual</span></label><input type="text" name="meeting_link" value="<?= e((string) (($v['meeting_link'] ?? '') ?: $client['meeting_link'])) ?>" placeholder="meet.google.com/..."></div>
    <div class="row">
      <div class="field" style="flex:2 1 240px"><label>Address <span class="hint">in person</span></label><textarea name="address" rows="2"><?= e((string) (($v['address'] ?? '') ?: $client['interview_address'])) ?></textarea></div>
      <div class="field" style="flex:1 1 180px"><label>Google Maps link</label><input type="text" name="maps_url" value="<?= e((string) (($v['maps_url'] ?? '') ?: $client['maps_url'])) ?>"></div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="iv-box">
  <p class="small" style="margin:0 0 6px"><b>Interviews</b></p>
  <?php foreach ($list as $i): $roundOpen = $i['status'] === 'booked' ? count($ivSvc->openSlots((int) $a['role_id'], (int) $i['round'])) : 0; ?>
    <div class="iv-row" id="iv-<?= (int) $i['id'] ?>">
      <span class="iv-when"><?= $i['starts_at'] ? '<b>' . date('D j M', strtotime($i['starts_at'])) . '</b><br>' . date('g:i A', strtotime($i['starts_at'])) : '<span class="muted">—</span>' ?></span>
      <div style="flex:1;min-width:0">
        <b>Client interview · Round <?= (int) $i['round'] ?></b> · <?= e($kinds[$i['kind']]) ?> <span class="ref small"><?= ref_interview($i['id']) ?></span>
        <br><span class="small muted"><?= match ($i['status']) {
            'choosing' => 'Invited to choose a time',
            'booked' => 'Confirmed · call letter sent ' . e(nice_date($i['letter_sent_at'], true)) . ($i['reminded_at'] ? ' · reminded' : '') . (!empty($i['reschedules']) ? ' · moved ' . (int) $i['reschedules'] . '×' : ''),
            'done' => 'Done · ' . e($outcomes[$i['outcome']] ?? ''),
            'no_show' => '<b style="color:var(--bad,#b42318)">Did not turn up</b>',
            default => e($i['status']),
        } ?></span>
        <?php if ($i['feedback']): ?><br><span class="small">&ldquo;<?= e($i['feedback']) ?>&rdquo;</span><?php endif; ?>

        <?php if ($i['status'] === 'booked' && $i['starts_at'] && strtotime((string) $i['starts_at']) < time()): ?>
          <p class="small" style="margin:6px 0 0;padding:6px 9px;border-radius:8px;background:var(--warn-tint,#FFF6E0)"><b>This time has passed.</b> If it took place, use <b>Record the result</b> (for the next round, choose "Through to round <?= (int) $i['round'] + 1 ?>" and then book it). Use <b>Change the time</b> only if this round <?= (int) $i['round'] ?> interview itself moved.</p>
        <?php endif; ?>
        <?php if (in_array($i['status'], ['booked', 'choosing'], true)): ?>
          <details class="iv-act" style="margin-top:6px"><summary class="small">Wrong round? Correct it</summary>
            <form method="post" action="/interviews/<?= (int) $i['id'] ?>/round" class="row" style="gap:6px;align-items:center;margin-top:6px"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
              <label class="small" for="cr-<?= (int) $i['id'] ?>">This is round</label>
              <select id="cr-<?= (int) $i['id'] ?>" name="round"><?php for ($r = 1; $r <= 3; $r++): ?><option value="<?= $r ?>"<?= $r === (int) $i['round'] ? ' selected' : '' ?>><?= $r ?></option><?php endfor; ?></select>
              <button class="btn small ghost" type="submit">Correct the round</button>
              <span class="hint">Nobody is emailed. Earlier rounds are recorded as held ("Through to the next round").</span>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($i['status'] === 'booked'): ?>
          <div class="iv-actions">
            <details class="iv-act"><summary class="btn small">Change the time</summary>
              <form method="post" action="/interviews/<?= (int) $i['id'] ?>/reschedule" class="book-form"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                <div class="row" style="align-items:flex-end">
                  <div class="field" style="flex:1 1 190px"><label>New date and time</label><input type="datetime-local" name="when" step="900" required value="<?= e(date('Y-m-d\TH:i', strtotime((string) $i['starts_at']))) ?>"></div>
                  <div class="field" style="flex:0 1 110px"><label>Length</label><select name="duration"><?php foreach ([30, 45, 60, 90] as $d): ?><option value="<?= $d ?>"<?= $d === (int) $i['duration_min'] ? ' selected' : '' ?>><?= $d ?> min</option><?php endforeach; ?></select></div>
                  <div class="field" style="flex:0 1 130px"><label>Type</label><select name="kind"><?php foreach ($kinds as $k => $v): ?><option value="<?= $k ?>"<?= $k === $i['kind'] ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                  <div class="field" style="flex:0 1 80px"><label>Round</label><select name="round"><?php for ($r = 1; $r <= 3; $r++): ?><option value="<?= $r ?>"<?= $r === (int) $i['round'] ? ' selected' : '' ?>><?= $r ?></option><?php endfor; ?></select></div>
                </div>
                <?= $whereFields($i) ?>
                <button class="btn small" type="submit">Move it and tell everyone</button>
                <span class="hint">Same interview, same round: the candidate gets the new call letter, the client is told straight away (old and new time), and their calendar entry is replaced. To fix only the round, without emailing anyone, use "Wrong round? Correct it".</span>
              </form>
            </details>
            <?php if ($roundOpen): ?>
              <form method="post" action="/interviews/<?= (int) $i['id'] ?>/choose-again"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                <button class="btn small ghost" type="submit" data-confirm-click="Cancel this time and send <?= e((string) $c['full_name']) ?> a link to choose another of the client's <?= $roundOpen ?> open round <?= (int) $i['round'] ?> times? The client is told the current time is off.">Let them choose again (<?= $roundOpen ?> open)</button></form>
            <?php endif; ?>
            <details class="iv-act"><summary class="btn small ghost">Record the result</summary>
              <form method="post" action="/interviews/<?= (int) $i['id'] ?>/outcome" style="margin-top:6px"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                <div class="pub-opts"><?php foreach ($outcomes as $k => $v): ?><label class="pub-opt"><input type="radio" name="outcome" value="<?= $k ?>" required> <span><?= e($k === 'next_round' ? 'Through to round ' . ((int) $i['round'] + 1) : $v) ?></span></label><?php endforeach; ?></div>
                <textarea name="feedback" rows="2" placeholder="Client's feedback (optional)" style="width:100%;margin-top:6px"></textarea>
                <button class="btn small" type="submit" style="margin-top:6px">Save</button>
                <span class="hint">"Through to round <?= (int) $i['round'] + 1 ?>" opens round <?= (int) $i['round'] + 1 ?> here. "Selected" moves them to Offer made; "Not selected" to Not suitable; "Did not turn up" to Withdrew, and marks them unreliable.</span>
              </form>
            </details>
            <details class="iv-act"><summary class="btn small ghost">Cancel</summary>
              <form method="post" action="/interviews/<?= (int) $i['id'] ?>/cancel" style="margin-top:6px"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                <label class="small" style="display:block;font-weight:normal"><input type="checkbox" name="tell_candidate" value="1" checked> Email <?= e((string) $c['full_name']) ?></label>
                <label class="small" style="display:block;font-weight:normal"><input type="checkbox" name="tell_client" value="1" checked> Email the client</label>
                <input type="text" name="reason" maxlength="300" placeholder="Reason (optional), e.g. the client is unavailable" style="width:100%;margin-top:6px">
                <button class="btn small" type="submit" style="margin-top:6px">Cancel the interview</button>
                <span class="hint">To move it to another time, use Change the time instead: that keeps the round.</span>
              </form>
            </details>
          </div>
        <?php elseif ($i['status'] === 'choosing'): ?>
          <div class="iv-actions">
            <form method="post" action="/interviews/<?= (int) $i['id'] ?>/cancel"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn small ghost" type="submit" data-confirm-click="Withdraw this invitation? Their link stops working.">Withdraw the invitation</button></form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($canContact && !array_filter($nextActive, fn ($i) => $i['status'] === 'booked')): ?>
    <div class="iv-next">
      <p class="small" style="margin:10px 0 6px"><b>Round <?= $next ?><?= $next > 1 ? ' (next round)' : '' ?></b><?= array_filter($nextActive, fn ($i) => $i['status'] === 'choosing') ? ' <span class="muted">· invited, waiting for them to choose. You can still book a time for them below.</span>' : '' ?></p>
      <div class="actions">
        <?php if ($open): ?>
          <form method="post" action="/applications/<?= (int) $a['id'] ?>/interview/invite"><?= csrf() ?><input type="hidden" name="round" value="<?= $next ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
            <button class="btn small" type="submit">Invite to choose a round <?= $next ?> time (<?= $open ?> open)</button></form>
        <?php else: ?>
          <span class="small muted">No round <?= $next ?> times from the client yet. <a href="/roles/<?= (int) $a['role_id'] ?>#interviews">Add them on the position</a>, or book a time below.</span>
        <?php endif; ?>
      </div>
      <details class="small" style="margin-top:6px"<?= $wantRound ? ' open' : '' ?>><summary><b>Book round <?= $next ?> directly</b> (a time agreed on the phone)</summary>
        <form method="post" action="/applications/<?= (int) $a['id'] ?>/interview/book" class="book-form"><?= csrf() ?><input type="hidden" name="back" value="<?= e($back) ?>">
          <div class="row" style="align-items:flex-end">
            <div class="field" style="flex:0 1 80px"><label>Round</label><select name="round"><?php for ($r = 1; $r <= 3; $r++): ?><option value="<?= $r ?>"<?= $r === min(3, $next) ? ' selected' : '' ?>><?= $r ?></option><?php endfor; ?></select></div>
            <div class="field" style="flex:0 1 140px"><label>Type</label><select name="kind" data-kind><?php foreach ($kinds as $k => $v): ?><option value="<?= $k ?>"<?= $k === $defaultKind ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="field" style="flex:1 1 190px"><label>Date and time</label><input type="datetime-local" name="when" step="900" required></div>
            <div class="field" style="flex:0 1 110px"><label>Length</label><select name="duration"><?php foreach ([30, 45, 60, 90] as $d): ?><option value="<?= $d ?>"><?= $d ?> min</option><?php endforeach; ?></select></div>
          </div>
          <?= $whereFields([]) ?>
          <button class="btn small" type="submit">Book and send the call letter</button>
          <span class="hint">The letter (your format for the round type), the JD<?= $defaultKind === 'in_person' ? ', the company profile' : '' ?> and a calendar invite go to the candidate; the client gets a confirmation.</span>
        </form>
      </details>
    </div>
  <?php endif; ?>
</div>
