<?php
/**
 * "At a glance" for the Complete-your-application requests (Benjamin, 10 Nov 2026).
 * Needs $dash from Questionnaires::dashboard(). Counted once per person, every request (with a role or without).
 */
$d = $dash;
$pc = fn (int $n, int $of) => $of ? (int) round(100 * $n / $of) : 0;
$sent = (int) $d['sent'];
$openedOnly = max(0, $d['opened'] - $d['answered'] - $d['declined']);
$notOpened = max(0, $sent - $d['answered'] - $d['declined'] - $openedOnly);
$hrs = $d['median_hours'];
$hrsText = $hrs === null ? null : ($hrs < 1 ? 'within the hour' : ($hrs < 36 ? 'within ' . (int) ceil($hrs) . ' hours' : 'within ' . (int) ceil($hrs / 24) . ' days'));
$lpa = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
?>
<section class="card glance" aria-labelledby="glance-h">
  <h2 id="glance-h" style="margin-top:0">At a glance</h2>
  <p class="small muted" style="margin:-6px 0 14px">Every "Complete your application" request, counted once per person: from the thank-you emails (with a role or without) and from "Ask more people".</p>
  <div class="grid g5 glance-tiles">
    <div class="stat"><b><?= $sent ?></b><span>Emails sent<?= $d['queued'] ? ' · ' . (int) $d['queued'] . ' still to go out' : '' ?></span></div>
    <div class="stat"><b><?= (int) $d['opened'] ?></b><span>Opened the link (<?= $pc((int) $d['opened'], $sent) ?>%)</span></div>
    <div class="stat good"><b><?= (int) $d['answered'] ?></b><span>Answered (<?= (int) $d['rate'] ?>%)</span></div>
    <div class="stat gold"><b><?= (int) $d['waiting'] ?></b><span>No answer yet</span></div>
    <div class="stat"><b><?= (int) $d['declined'] ?></b><span>No longer interested</span></div>
  </div>
  <?php if ($sent): ?>
    <div class="glance-bar" role="img" aria-label="<?= (int) $d['answered'] ?> answered, <?= (int) $d['declined'] ?> no longer interested, <?= $openedOnly ?> opened but not answered, <?= $notOpened ?> not opened, of <?= $sent ?> sent">
      <?php foreach ([['ans', $d['answered']], ['dec', $d['declined']], ['opn', $openedOnly], ['non', $notOpened]] as [$k, $n]): if ($n): ?><span class="<?= $k ?>" style="flex:<?= (int) $n ?>"></span><?php endif; endforeach; ?>
    </div>
    <p class="glance-key small"><span><i class="ans"></i>Answered <?= (int) $d['answered'] ?></span><span><i class="dec"></i>No longer interested <?= (int) $d['declined'] ?></span><span><i class="opn"></i>Opened, not answered <?= $openedOnly ?></span><span><i class="non"></i>Not opened <?= $notOpened ?></span></p>
  <?php endif; ?>

  <div class="grid g3" style="margin-top:14px">
    <div class="glance-box">
      <h3>What they told us</h3>
      <?php if (!$d['answered']): ?><p class="small muted">No answers yet.</p><?php else: ?>
        <ul class="glance-list">
          <li><span>Current salary</span><b><?= (int) $d['gave']['ctc'] ?> of <?= (int) $d['answered'] ?></b></li>
          <li><span>Lowest salary they'd accept</span><b><?= (int) $d['gave']['min'] ?> of <?= (int) $d['answered'] ?></b></li>
          <li><span>How soon they can join</span><b><?= (int) $d['gave']['notice'] ?> of <?= (int) $d['answered'] ?></b></li>
        </ul>
        <p class="small" style="margin:8px 0 0"><?= $d['gave']['all'] === $d['answered'] ? 'Everyone who answered gave all three.' : (int) $d['gave']['all'] . ' gave all three.' ?></p>
      <?php endif; ?>
    </div>
    <div class="glance-box">
      <h3>When they answered</h3>
      <?php if (!$d['answered']): ?><p class="small muted">No answers yet.</p><?php else: ?>
        <ul class="glance-list">
          <li><span>After the first email</span><b><?= (int) $d['after']['first'] ?></b></li>
          <li><span>After the reminder</span><b><?= (int) $d['after']['reminder'] ?></b></li>
          <li><span>After the final email</span><b><?= (int) $d['after']['final'] ?></b></li>
        </ul>
        <?php if ($hrsText): ?><p class="small" style="margin:8px 0 0">Half of them answered <?= e($hrsText) ?> of the email.</p><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="glance-box">
      <h3>Still to answer</h3>
      <?php if (!$d['waiting']): ?><p class="small muted">Nobody is waiting.</p><?php else: ?>
        <ul class="glance-list">
          <li><span>Reminder not due yet</span><b><?= (int) $d['stage']['first'] ?></b></li>
          <li><span>Reminded</span><b><?= (int) $d['stage']['reminded'] ?></b></li>
          <li><span>Final email sent</span><b><?= (int) $d['stage']['final'] ?></b></li>
          <?php if ($d['stage']['expired']): ?><li><span>Link expired</span><b><?= (int) $d['stage']['expired'] ?></b></li><?php endif; ?>
        </ul>
        <p class="small muted" style="margin:8px 0 0">Reminders go by themselves: 48 hours after the email, then a final one 48 hours later.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid g2" style="margin-top:4px">
    <?php if ($d['roles']): ?>
    <div>
      <h3>By role</h3>
      <div class="table-wrap"><table class="list compact">
        <thead><tr><th>Role</th><th class="num">On the role</th><th class="num">Asked</th><th class="num">Answered</th><th class="num">Within budget</th><th class="num">Not interested</th></tr></thead>
        <tbody><?php foreach ($d['roles'] as $r): ?>
          <tr><td><?= e($r['title']) ?></td><td class="num"><?= (int) $r['on'] ?></td><td class="num"><?= (int) $r['asked'] ?></td><td class="num"><b><?= (int) $r['answered'] ?></b> <span class="muted">(<?= $pc((int) $r['answered'], (int) $r['asked']) ?>%)</span></td>
            <td class="num"<?= $r['budget'] !== null ? ' title="Lowest salary they would accept is at or below ' . e($lpa($r['budget'])) . ' LPA"' : '' ?>><?= (int) $r['within'] ?></td><td class="num"><?= (int) $r['declined'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <p class="small muted" style="margin:4px 0 0">Within budget: the lowest salary they would accept is within the role's budget. Everyone on the role counts, whichever email reached them; someone on two roles counts in both.</p>
    </div>
    <?php endif; ?>
    <?php if ($d['days']): ?>
    <div>
      <h3>By day sent</h3>
      <div class="table-wrap"><table class="list compact">
        <thead><tr><th>Sent on</th><th class="num">Sent</th><th class="num">Answered</th><th class="num">Not interested</th></tr></thead>
        <tbody><?php foreach (array_slice($d['days'], 0, 10) as $r): ?>
          <tr><td class="nowrap"><?= e(date('D j M', strtotime($r['day']))) ?></td><td class="num"><?= (int) $r['sent'] ?></td>
            <td class="num"><b><?= (int) $r['answered'] ?></b> <span class="muted">(<?= $pc((int) $r['answered'], (int) $r['sent']) ?>%)</span></td><td class="num"><?= (int) $r['declined'] ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </div>
  <?php $gapSum = (new \Eshcol\Ats\Services\GapQuestions(\Eshcol\Ats\App::get()))->summary(); if ($gapSum['people']): ?>
  <p class="small" style="margin:10px 0 0"><b>Gaps answered</b> (requirements their CV didn't mention): <?= (int) $gapSum['yes'] ?> yes, <?= (int) $gapSum['some'] ?> some exposure, <?= (int) $gapSum['no'] ?> no, from <?= (int) $gapSum['people'] ?> <?= $gapSum['people'] === 1 ? 'person' : 'people' ?>.</p>
  <?php endif; ?>
</section>
