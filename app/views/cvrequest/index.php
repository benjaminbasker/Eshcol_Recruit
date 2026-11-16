<?php
/** @var \Eshcol\Ats\Services\CvRequests $svc @var array $summary @var array $batches @var array $chosen @var ?array $plan @var int $usedToday @var bool $owner */
$cap = $svc->cap();
$names = \Eshcol\Ats\Services\CvRequests::GROUPS;
$db = \Eshcol\Ats\App::get()->db;
?>
<div class="page-head">
  <div><p class="eyebrow"><a href="/details-requests">Responses</a></p><h1>Share your updated CV</h1>
    <p class="muted">One email to people whose CV we hold from earlier applications: share your updated CV and details, keep me for the future, or remove my details. Sent in your order, at most <?= $cap ?> a day between 9 am and 7 pm, after all other email. Reminders: day <?= e(implode(' and ', $svc->reminderDays(1))) ?> for group 1, day <?= e(implode(' and ', $svc->reminderDays(2)) ?: 'none') ?> for the others.</p></div>
</div>

<?php if ($summary): ?>
  <h2><?= icon('mail') ?> Progress</h2>
  <p class="small muted" style="margin:-4px 0 8px">Today: <?= $usedToday ?> of <?= $cap ?> used<?= $svc->topupFrom() < 24 ? ', plus the evening top-up from 5 pm (' . $svc->reserve() . ' kept spare)' : '' ?>.<?= $svc->paused() ? ' <b class="text-bad">Sending is paused.</b>' : '' ?></p>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Group</th><th>Waiting to send</th><th>Sent</th><th>Reminders</th><th>Opened</th><th>Shared a CV</th><th>Keep for future</th><th>Remove</th><th>No answer</th><th>Left out</th></tr></thead>
    <tbody>
    <?php foreach ($summary as $g => $s): ?>
      <tr><td><b><?= $g ?></b> <span class="small muted"><?= e($names[$g] ?? '') ?></span></td>
        <td><?= $s['queued'] ?></td><td><?= $s['sent'] ?></td><td><?= $s['reminders'] ?></td><td><?= $s['opened'] ?></td>
        <td><b><?= $s['cvs'] ?></b></td><td><?= $s['keep_'] ?></td><td><?= $s['remove_'] ?></td><td><?= $s['no_answer'] ?></td><td><?= $s['skipped'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="small muted">"Shared a CV" people go on to the details form; their answers appear on the Responses page. "Remove" requests wait for you under Candidates › Asked to be removed.</p>
<?php endif; ?>

<h2><?= icon('upload') ?> Start a send</h2>
<div class="card">
  <form method="get" action="/cv-requests">
    <p class="small" style="margin-top:0">Choose the uploads to write to (for example the old Indeed CVs). Nothing is sent at this step: you see the three groups first.</p>
    <?php if (!$batches): ?><p class="muted">No uploads yet.</p><?php endif; ?>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th></th><th>Uploaded</th><th>Label</th><th>Came from</th><th>Role</th><th>People</th></tr></thead>
      <tbody><?php foreach ($batches as $b): ?>
        <tr><td><input type="checkbox" name="batch[]" value="<?= (int) $b['id'] ?>" id="b<?= (int) $b['id'] ?>"<?= in_array((int) $b['id'], $chosen, true) ? ' checked' : '' ?>></td>
          <td class="nowrap"><label for="b<?= (int) $b['id'] ?>"><?= e(nice_date($b['created_at'])) ?></label></td><td><?= e($b['label'] ?: '–') ?></td>
          <td><?= e(channel_name($b['channel'])) ?></td><td class="small"><?= e($b['role'] ?: 'No role') ?></td><td><?= (int) $b['people'] ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <p class="actions"><button class="btn" type="submit">See the groups</button></p>
  </form>
</div>

<?php if ($plan !== null):
  $g = $plan['groups']; $total = count($g[1]) + count($g[2]) + count($g[3]); $wait = array_sum($plan['waiting']); ?>
  <div class="card" id="plan">
    <h2 style="margin-top:0">Who would get it</h2>
    <?php if ($wait): ?>
      <div class="flash bell" role="status"><?= icon('bell', 'sm') ?><div>Still working on these CVs: <?= (int) $plan['waiting']['reading'] ?> being read, <?= (int) $plan['waiting']['categorising'] ?> being given a field, <?= (int) $plan['waiting']['assessing'] ?> being assessed. <b>Wait until this is 0</b> (refresh this page), or some likely fits will land in groups 2 and 3.</div></div>
    <?php endif; ?>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Group</th><th>People</th><th>Email says</th></tr></thead>
      <tbody>
        <?php foreach ([1, 2, 3] as $n): ?>
          <tr><td><b><?= $n ?></b> <?= e($names[$n]) ?></td><td><b><?= count($g[$n]) ?></b></td>
            <td class="small"><?= $n === 1 ? '"…it matches a current requirement of one of our clients"' : '"…we would like to consider you for current and upcoming requirements of our clients"' ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($g[1]): ?>
      <p class="small"><b>Group 1, first ten:</b>
        <?php foreach (array_slice($g[1], 0, 10) as $i => [$cid]): ?><?= $i ? ', ' : '' ?><a href="/candidates/<?= (int) $cid ?>"><?= e((string) ($db->col('SELECT full_name FROM candidates WHERE id = ?', [$cid]) ?: ref_candidate($cid))) ?></a><?php endforeach; ?></p>
    <?php endif; ?>
    <?php if ($plan['excluded']): ?>
      <p class="small"><b>Left out:</b> <?= e(implode('; ', array_map(fn ($k, $v) => "$k: $v", array_keys($plan['excluded']), $plan['excluded']))) ?>.</p>
    <?php endif; ?>
    <p class="small muted"<?= $total ? '' : ' hidden' ?>>At <?= $cap ?> a day, <?= $total ?> people take about <?= max(1, (int) ceil($total / $cap)) ?> working day(s), plus reminders. Reminders go before new emails.</p>
    <?php if ($owner && $total): ?>
      <form method="post" action="/cv-requests/start"><?= csrf() ?>
        <?php foreach ($chosen as $id): ?><input type="hidden" name="batch[]" value="<?= (int) $id ?>"><?php endforeach; ?>
        <button class="btn" type="submit" data-confirm="Start sending to <?= $total ?> people, at most <?= $cap ?> a day?">Start sending to <?= $total ?> people</button>
      </form>
    <?php elseif (!$total): ?>
      <p class="muted">Nobody to write to in these uploads.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($owner): ?>
  <h2><?= icon('sliders') ?> Settings</h2>
  <div class="card">
    <form method="post" action="/cv-requests/settings" class="form-grid"><?= csrf() ?>
      <div class="field"><label for="cap">Emails a day</label><input type="number" id="cap" name="cap" min="1" max="1000" value="<?= $cap ?>">
        <p class="hint">On top of your other email. The overall daily limit in Settings › Email still applies.</p></div>
      <div class="field"><label for="rem1">Reminders for group 1 (days after the first email)</label><input type="text" id="rem1" name="rem1" value="<?= e(implode(', ', $svc->reminderDays(1))) ?>"></div>
      <div class="field"><label for="rem2">Reminders for groups 2 and 3</label><input type="text" id="rem2" name="rem2" value="<?= e(implode(', ', $svc->reminderDays(2))) ?>">
        <p class="hint">Up to three, for example "3, 7". Leave empty for none. A week after the last one, no answer means we don't contact them again until they apply themselves.</p></div>
      <div class="field"><label style="font-weight:normal"><input type="checkbox" name="topup"<?= $svc->topupFrom() < 24 ? ' checked' : '' ?>> Evening top-up: from 5 pm to 7 pm, also use what is left of the overall daily limit</label>
        <label for="reserve" style="margin-top:8px">Keep this many spare for evening email</label><input type="number" id="reserve" name="reserve" min="0" max="200" value="<?= $svc->reserve() ?>">
        <p class="hint">Interview confirmations, replies and thank-yous that come in after 7 pm use the spare.</p></div>
      <div class="field"><label style="font-weight:normal"><input type="checkbox" name="paused"<?= $svc->paused() ? ' checked' : '' ?>> Pause sending</label></div>
      <p class="actions"><button class="btn" type="submit">Save</button></p>
    </form>
  </div>
<?php endif; ?>
