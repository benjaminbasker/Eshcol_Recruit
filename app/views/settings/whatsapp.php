<?php /** Settings › WhatsApp (28 Nov 2026). @var array $wa */
$names = ['application_received' => 'Application received', 'questions_request' => 'A few questions (questionnaire)', 'call_request' => 'Ask for a call', 'missed_call' => 'We tried to call you',
    'interview_choose_time' => 'Interview: choose your time', 'interview_invite' => 'Interview fixed', 'interview_rescheduled' => 'Interview rescheduled', 'interview_reminder' => 'Interview-day reminder',
    'selected' => 'Selected (offer made)', 'not_selected' => 'Not selected (client rejected)', 'joining_reminder' => 'Joining reminder (evening before)'];
$byName = [];
foreach ($wa['templates'] as $t) {
    $byName[$t['name']][] = $t;
}
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings">Settings</a> › WhatsApp</p><h1>WhatsApp to candidates</h1></div></div>

<section class="card" style="max-width:900px">
  <div class="card-head"><h2>WABridge</h2><span class="chip <?= $wa['live'] ? 'yes' : 'flag' ?>" style="text-decoration:none"><?= $wa['live'] ? 'Sending' : 'Off' ?></span></div>
  <p class="small">Candidates get a WhatsApp from +91 81228 12340 alongside the thank-you email, the interview email and the interview-day reminder. Only templates Meta has approved are sent. Anyone marked do not contact, or who asked to be removed, gets nothing. Replies come to the WhatsApp Business app on your phone. <?= (int) $wa['queued'] ?> waiting, <?= (int) $wa['held'] ?> held for a template.</p>
  <form method="post" action="/settings/whatsapp" autocomplete="off"><?= csrf() ?>
    <div class="row">
      <div class="field"><label for="wa-app">App Key</label><input type="password" id="wa-app" name="app_key" autocomplete="new-password" placeholder="<?= $wa['has_keys'] ? 'Saved (leave empty to keep it)' : 'From WABridge' ?>"><p class="hint">WABridge, Profile Settings, API &amp; Integrations. Type it here, never in a chat.</p></div>
      <div class="field"><label for="wa-auth">Auth Key</label><input type="password" id="wa-auth" name="auth_key" autocomplete="new-password" placeholder="<?= $wa['has_keys'] ? 'Saved (leave empty to keep it)' : 'From WABridge' ?>"></div>
      <div class="field"><label for="wa-dev">Device ID</label><input type="text" id="wa-dev" name="device_id" value="<?= e($wa['device']) ?>" placeholder="WABridge, My Devices, Device Id"></div>
    </div>
    <label><input type="checkbox" name="live" value="1"<?= $wa['live'] ? ' checked' : '' ?><?= $wa['configured'] ? '' : ' disabled' ?>> Send WhatsApp messages (off keeps them in the outbox)</label>
    <div class="actions" style="margin-top:10px"><button class="btn" type="submit">Save</button></div>
  </form>
</section>

<section class="card" style="max-width:900px" id="auto">
  <h2>Sent on their own</h2>
  <p class="small">Thank-you, questionnaire and interview messages go with their emails. These go when you change a stage, or the evening before someone joins. "Ask for a call" and "We tried to call you" are on each position on the candidate's page.</p>
  <form method="post" action="/settings/whatsapp/auto"><?= csrf() ?>
    <label><input type="checkbox" name="selected" value="1"<?= $wa['auto']['selected'] ? ' checked' : '' ?>> "Selected" when you move someone to <b>Offer made</b></label><br>
    <label><input type="checkbox" name="not_selected" value="1"<?= $wa['auto']['not_selected'] ? ' checked' : '' ?>> "Not selected" when you move someone to <b>Client rejected</b> (never to anyone red-listed)</label>
    <div class="row" style="align-items:flex-end;margin-top:8px"><div class="field" style="flex:0 1 200px"><label for="wa-jt">Reporting time in the joining reminder</label><input type="time" id="wa-jt" name="joining_time" value="<?= e($wa['auto']['joining_time']) ?>"></div>
      <div class="field"><button class="btn small" type="submit">Save</button></div></div>
  </form>
</section>

<section class="card" style="max-width:900px">
  <h2>Templates</h2>
  <table class="list compact"><tbody><?php foreach ($names as $n => $label): $ts = $byName[$n] ?? []; ?>
    <tr><td><b><?= e($label) ?></b><br><span class="small muted"><?= e($n) ?></span></td>
      <td><?php if (!$ts): ?><span class="chip flag" style="text-decoration:none">Not in WABridge yet</span><?php else: foreach ($ts as $t): ?><span class="chip <?= $t['status'] === 'APPROVED' ? 'yes' : 'flag' ?>" style="text-decoration:none"><?= e($t['language']) ?>: <?= e(ucfirst(strtolower($t['status']))) ?></span> <?php endforeach; endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if ($wa['configured']): ?><form method="post" action="/settings/whatsapp/sync" class="actions" style="margin-top:8px"><?= csrf() ?><button class="btn ghost small" type="submit">Sync templates from WABridge</button>
    <span class="small muted"><?= $wa['synced'] ? 'Last synced ' . e(nice_date($wa['synced'])) . '.' : 'Not synced yet.' ?></span></form><?php endif; ?>
  <?php if ($wa['live']): ?>
  <details style="margin-top:10px"><summary class="btn ghost small">Send a test</summary>
    <form method="post" action="/settings/whatsapp/test" style="margin-top:8px"><?= csrf() ?>
      <div class="row">
        <div class="field"><label for="wt-t">Template</label><select id="wt-t" name="template"><?php foreach ($names as $n => $label): ?><option value="<?= $n ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="wt-to">To (your own mobile)</label><input type="text" id="wt-to" name="to" inputmode="tel" required></div>
        <div class="field"><label for="wt-v">Values for {{1}}, {{2}}… separated by |</label><input type="text" id="wt-v" name="values" placeholder="Benjamin | Trainee Technician"></div>
        <div class="field"><label for="wt-b">Button value <span class="muted">(choose-your-time only)</span></label><input type="text" id="wt-b" name="button"></div>
      </div>
      <button class="btn small" type="submit">Send the test</button></form></details>
  <?php endif; ?>
</section>

<?php if ($wa['recent']): ?>
<section class="card" style="max-width:900px">
  <h2>Recent</h2>
  <table class="list compact"><thead><tr><th>When</th><th>To</th><th>Template</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($wa['recent'] as $r): ?><tr><td class="small"><?= e(nice_date($r['created_at'])) ?></td><td class="small"><?= e($r['to_masked']) ?></td><td class="small"><?= e($r['template']) ?></td>
      <td class="small"><span class="chip <?= in_array($r['dlr_status'], ['delivered', 'read'], true) ? 'yes' : (in_array($r['status'], ['failed', 'held', 'cancelled'], true) ? 'flag' : '') ?>" style="text-decoration:none"><?= e($r['dlr_status'] ?: $r['status']) ?></span><?= $r['last_error'] ? '<br><span class="muted">' . e($r['last_error']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>
