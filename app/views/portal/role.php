<?php
/**
 * One position in the client portal: every candidate sent, decisions and comments, interview times.
 * @var array $me @var array $role @var array $cands @var array $schedule
 */
$labels = \Eshcol\Ats\Services\Shortlists::DECISIONS;
$kinds = \Eshcol\Ats\Services\Interviews::KINDS;
$lengths = \Eshcol\Ats\Services\ClientPortal::LENGTHS;
$open = $role['status'] === 'open';
$waiting = count(array_filter($cands, fn ($c) => !$c['decision'] && $c['state'][0] === 'review'));
$stateChip = ['review' => 'flag', 'interview' => 'yes', 'offer' => 'yes', 'joined' => 'yes', 'closed' => '', 'hold' => 'sand'];
?>
<section class="pub-card">
  <p class="eyebrow"><a href="/portal">Your positions</a> › <?= e($role['code']) ?></p>
  <h1><?= e($role['title']) ?></h1>
  <p class="small muted" style="margin-top:-2px"><?= e(implode(' · ', array_filter([$role['location'], WORK_MODES[$role['work_mode'] ?? ''] ?? null, ['open' => 'Open', 'on_hold' => 'On hold', 'filled' => 'Filled'][$role['status']] ?? null]))) ?></p>
  <?php if (!$cands): ?>
    <p style="margin-bottom:0">We have not sent you candidates for this position yet. They appear here as soon as we do, and we email you each time.</p>
  <?php else: ?>
    <p style="margin-bottom:0"><?= count($cands) ?> candidate<?= count($cands) === 1 ? '' : 's' ?> sent<?= $waiting ? ', <b>' . $waiting . ' waiting for your decision</b>' : '' ?>. Mark each one <b>Shortlist</b>, <b>Hold</b> or <b>Not suitable</b>, add a comment if you like, and press <b>Send my feedback</b>. You can change your answers later.</p>
  <?php endif; ?>
</section>

<?php if ($cands): ?>
<form method="post" action="/portal/roles/<?= (int) $role['id'] ?>"><?= csrf() ?>
<?php foreach ($cands as $it): $p = $it['p']; [$sk, $sl] = $it['state']; ?>
  <section class="pub-card sl-card" id="c<?= (int) $it['id'] ?>">
    <div class="portal-role-head">
      <h2><?= e($p['name'] ?? 'Candidate') ?></h2>
      <span class="chip <?= $stateChip[$sk] ?? '' ?>"><?= e($sl) ?></span>
    </div>
    <?php if (!empty($p['title'])): ?><p class="muted" style="margin-top:-2px"><?= e($p['title']) ?><?= !empty($p['company']) ? ', ' . e($p['company']) : '' ?></p><?php endif; ?>
    <p style="margin:4px 0 10px"><a class="btn small" href="/portal/roles/<?= (int) $role['id'] ?>/cv/<?= (int) $it['id'] ?>" target="_blank" rel="noopener"><?= $it['mode'] === 'cvs' ? 'Open CV' : 'Open profile' ?></a>
      <span class="ref small"><?= e($p['ref'] ?? '') ?></span> <span class="small muted">sent <?= e(nice_date($it['sent_at'])) ?></span></p>
    <?php if ($it['summary']): ?><div class="sl-view"><span class="eyebrow">Our remarks</span><br><?= nl2br(e($it['summary'])) ?></div><?php endif; ?>
    <table class="qa">
      <?php foreach (['email' => 'Email', 'phone' => 'Phone', 'experience' => 'Experience', 'location' => 'Location', 'distance' => 'Distance', 'qualifications' => 'Qualification', 'notice' => 'Can join', 'ctc_current' => 'Current CTC', 'ctc_expected' => 'Expected CTC', 'ctc_min' => 'Lowest acceptable CTC'] as $k => $label): if (empty($p[$k])) { continue; } ?>
        <tr><td><?= e($label) ?></td><td><?= e($p[$k]) ?></td></tr>
      <?php endforeach; ?>
      <?php foreach ($p['answers'] ?? [] as $a): ?><tr><td><?= e($a['q']) ?></td><td><?= e($a['a']) ?></td></tr><?php endforeach; ?>
    </table>
    <?php if (!empty($p['matched'])): ?><p class="small" style="margin:12px 0 4px"><b>Matches your requirements:</b></p><p><?php foreach ($p['matched'] as $m): ?><span class="chip yes"><?= e($m) ?></span><?php endforeach; ?></p><?php endif; ?>
    <fieldset class="pub-q">
      <legend>Your decision</legend>
      <div class="pub-opts">
        <?php foreach ($labels as $k => $label): ?>
          <label class="pub-opt"><input type="radio" name="decision[<?= (int) $it['id'] ?>]" value="<?= $k ?>"<?= $it['decision'] === $k ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
      <label for="cm<?= (int) $it['id'] ?>" class="small" style="display:block;margin-top:10px">Comment <span class="hint">optional</span></label>
      <textarea id="cm<?= (int) $it['id'] ?>" name="comment[<?= (int) $it['id'] ?>]" rows="2" maxlength="2000" placeholder="e.g. Good profile; please arrange a call this week"><?= e((string) $it['comment']) ?></textarea>
    </fieldset>
  </section>
<?php endforeach; ?>
  <button class="btn pub-submit" type="submit">Send my feedback</button>
</form>
<?php endif; ?>

<section class="pub-card sl-card" id="times">
  <h2>Interview times</h2>
  <?php if ($schedule['booked']): ?>
    <p class="small" style="margin:6px 0 4px"><b>Booked</b></p>
    <table class="qa">
      <?php foreach ($schedule['booked'] as $b): ?>
        <tr><td><?= e(date('D j M, g:i a', strtotime($b['starts_at']))) ?></td><td><?= e($b['full_name'] ?: 'Candidate') ?> · <?= (int) $b['duration_min'] ?> min · <?= e($kinds[$b['kind']] ?? '') ?> · round <?= (int) $b['round'] ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($schedule['free']): ?>
    <p class="small" style="margin:12px 0 4px"><b>Offered, not taken yet</b> <span class="muted">(candidates pick from these)</span></p>
    <table class="qa">
      <?php foreach ($schedule['free'] as $f): ?>
        <tr><td><?= e(date('D j M, g:i a', strtotime($f['starts_at']))) ?></td><td><?= (int) $f['duration_min'] ?> min · <?= e($kinds[$f['kind']] ?? '') ?> · round <?= (int) $f['round'] ?>
          <?php if ($f['source'] === 'client'): ?><form method="post" action="/portal/slots/<?= (int) $f['id'] ?>/delete" style="display:inline;margin-left:8px"><?= csrf() ?><button class="btn link small" type="submit">Withdraw</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($open): ?>
    <h3 style="margin:16px 0 4px">Offer times</h3>
    <p class="small" style="margin-top:0">Give us the times when you are free, on one day or several, and choose how long each interview should be. We split each window into interview times, the candidates you shortlist each pick one, and we confirm every interview with you, with a calendar invite. For example, 5:00 to 6:00 pm with 20-minute interviews gives three times: 5:00, 5:20 and 5:40.</p>
    <form method="post" action="/portal/roles/<?= (int) $role['id'] ?>/times"><?= csrf() ?>
      <?php for ($d = 0; $d < 3; $d++): ?>
      <div class="row">
        <div class="field" style="flex:1 1 150px"><label for="pt-date<?= $d ?>">Day <?= $d + 1 ?><?= $d ? ' <span class="muted">(optional)</span>' : '' ?></label><input type="date" id="pt-date<?= $d ?>" name="date[]"<?= $d ? '' : ' required' ?> min="<?= date('Y-m-d') ?>"></div>
        <div class="field" style="flex:1 1 110px"><label for="pt-from<?= $d ?>">From</label><input type="time" id="pt-from<?= $d ?>" name="from[]"<?= $d ? '' : ' required' ?> step="300"></div>
        <div class="field" style="flex:1 1 110px"><label for="pt-to<?= $d ?>">To</label><input type="time" id="pt-to<?= $d ?>" name="to[]"<?= $d ? '' : ' required' ?> step="300"></div>
      </div>
      <?php endfor; ?>
      <div class="row">
        <div class="field" style="flex:1 1 150px"><label for="pt-len">Each interview lasts</label><select id="pt-len" name="length">
          <?php foreach ($lengths as $l): ?><option value="<?= $l ?>"<?= $l === 30 ? ' selected' : '' ?>><?= $l === 60 ? '1 hour' : $l . ' minutes' ?></option><?php endforeach; ?></select></div>
        <div class="field" style="flex:1 1 130px"><label for="pt-kind">Type</label><select id="pt-kind" name="kind"><?php foreach ($kinds as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="flex:1 1 90px"><label for="pt-round">Round</label><input type="number" id="pt-round" name="round" min="1" max="9" value="1"></div>
      </div>
      <button class="btn" type="submit">Offer these times</button>
      <span class="small muted" style="margin-left:8px">Need more than three days? Send the form again.</span>
    </form>
  <?php elseif (!$schedule['booked'] && !$schedule['free']): ?>
    <p class="small muted" style="margin-bottom:0">This position is not open, so no times can be offered.</p>
  <?php endif; ?>
</section>
<p class="hint" style="text-align:center;margin-top:10px">These profiles are shared in confidence for this position: please don't forward them outside your organisation. Please quote the reference (EG-…) when you discuss a candidate with us.</p>
