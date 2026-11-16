<?php
$labels = ['held' => 'Waiting for you', 'pending' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'] + (($counts['unsure'] ?? 0) || $tab === 'unsure' ? ['unsure' => 'Unsure'] : []);
$kinds = ['ack' => 'Thank-you', 'test' => 'Test', 'interview' => 'Interview', 'rejection' => 'Regret', 'questionnaire' => 'Details request', 'digest' => 'Summary', 'shortlist' => 'Shortlist', 'client_reply' => 'Client reply', 'message' => 'Message'];
?>
<div class="page-head">
  <div><p class="eyebrow">Outgoing</p><h1>Emails</h1>
    <p class="muted"><?= (int) $sentToday ?> of <?= (int) $cap ?> sent today<?= $live ? '' : ' · <b>test mode</b>: emails are written to the server, not sent' ?>. The queue is sent every 5 minutes.</p></div>
  <a class="btn ghost small" href="/details-requests"><?= icon('check', 'sm') ?>Candidate details: responses</a>
</div>
<p class="actions small" style="margin:-8px 0 16px">
  <?php foreach ($labels as $k => $label): ?>
    <a href="/emails?tab=<?= $k ?>" class="<?= $tab === $k ? 'btn small' : 'btn ghost small' ?>"><?= $label ?> (<?= (int) ($counts[$k] ?? 0) ?>)</a>
  <?php endforeach; ?>
</p>
<?php if ($tab === 'unsure' && $rows): ?>
  <div class="card sand small">These were handed to Brevo, but no clear answer came back, so they may already have arrived. They are never sent again by themselves. Open one: if the person did not get it, press Send again; otherwise cancel it.</div>
<?php endif; ?>
<?php if ($tab === 'held' && $rows): ?>
  <div class="card sand small">These thank-you emails were not sent because the address might be wrong, and a wrong address would tell a stranger that someone applied for a job. Open one to check it, correct the address if needed, and approve it.</div>
<?php endif; ?>
<?php if (!$rows): ?>
  <div class="card empty"><?= ['held' => 'Nothing waiting for you.', 'pending' => 'Nothing queued.', 'sent' => 'Nothing sent yet.', 'failed' => 'No failures.', 'unsure' => 'Nothing to check.'][$tab] ?></div>
<?php else: ?>
  <form method="post" action="/emails/bulk"><?= csrf() ?>
  <div class="table-wrap"><table class="list">
    <thead><tr>
      <?php if ($tab === 'held' && ($user['role'] ?? '') === 'owner'): ?><th style="width:34px"><input type="checkbox" data-all aria-label="Select all"></th><?php endif; ?>
      <th>To</th><th>Email</th><th><?= $tab === 'held' ? 'Why it waits' : ($tab === 'sent' ? 'Sent' : ($tab === 'failed' || $tab === 'unsure' ? 'Problem' : 'Goes after')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr>
        <?php if ($tab === 'held' && ($user['role'] ?? '') === 'owner'): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Select"></td><?php endif; ?>
        <td><?php if ($r['candidate_id']): ?><a href="/candidates/<?= (int) $r['candidate_id'] ?>"><b><?= e($r['full_name'] ?: $r['to_name'] ?: 'Candidate') ?></b></a><?php else: ?><b><?= e($r['to_name'] ?: '–') ?></b><?php endif; ?>
          <br><span class="small muted"><?= e($r['to_email']) ?></span></td>
        <td><span class="chip"><?= e($kinds[$r['kind']] ?? ucfirst($r['kind'])) ?></span> <?= e($r['subject']) ?></td>
        <td class="small"><?= $tab === 'held' ? e(ucfirst((string) $r['hold_reason'])) : ($tab === 'sent' ? e(nice_date($r['sent_at'], true)) : ($tab === 'failed' || $tab === 'unsure' ? e((string) $r['last_error']) : e(nice_date($r['not_before'], true)))) ?></td>
        <td class="nowrap"><a class="btn ghost small" href="/emails/<?= (int) $r['id'] ?>"><?= $tab === 'held' ? 'Check' : 'View' ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($tab === 'held' && ($user['role'] ?? '') === 'owner'): ?>
    <p class="actions" style="margin-top:12px">
      <button class="btn ghost small" type="submit" name="do" value="release" data-confirm-click="Send the selected emails to the addresses shown?">Approve selected as they are</button>
      <button class="btn ghost small" type="submit" name="do" value="cancel">Cancel selected</button>
    </p>
  <?php endif; ?>
  </form>
<?php endif; ?>
