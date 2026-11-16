<?php $labels = \Eshcol\Ats\Services\Shortlists::DECISIONS; $cls = ['shortlist' => 'yes', 'hold' => 'sand', 'reject' => 'flag']; ?>
<div class="page-head">
  <div><p class="eyebrow"><?= e($s['client']) ?> · <?= e($s['code']) ?> · <?= ref_shortlist($s['id']) ?></p><h1><?= e($s['title']) ?></h1>
    <p class="muted">Sent to <?= e($s['to_name'] ? $s['to_name'] . ' <' . $s['to_email'] . '>' : $s['to_email']) ?><?= $s['cc'] ? ' (cc ' . e($s['cc']) . ')' : '' ?> by <?= e($s['sender'] ?? '–') ?>, <?= e(nice_date($s['created_at'], true)) ?>
      · <?= $s['status'] === 'closed' ? '<b>closed</b>' : ($s['answered_at'] ? 'replied ' . e(nice_date($s['answered_at'], true)) : ($s['first_viewed_at'] ? 'opened ' . e(nice_date($s['first_viewed_at'], true)) : 'not opened yet')) ?>
      · link works until <?= e(nice_date($s['expires_at'])) ?></p></div>
  <a class="btn ghost" href="/roles/<?= (int) $s['role_id'] ?>">Back to the role</a>
</div>
<div class="card">
  <p class="small" style="margin:0 0 8px"><b>Email:</b> <?= $email ? e(['held' => 'waiting for you under Emails', 'pending' => 'queued', 'sent' => 'sent ' . nice_date($email['sent_at'], true), 'failed' => 'failed: ' . $email['last_error'], 'cancelled' => 'cancelled'][$email['status']]) : '–' ?></p>
  <div class="row" style="align-items:flex-end">
    <div class="field" style="flex:3 1 300px;margin:0"><label for="sl-link">The client's private link</label><input type="text" id="sl-link" value="<?= e($link) ?>" readonly></div>
    <div class="actions"><button class="btn ghost small" type="button" data-copy="sl-link">Copy</button>
      <a class="btn ghost small" href="/shortlists/<?= (int) $s['id'] ?>/pdf" target="_blank">PDF</a>
      <?php if ($s['status'] !== 'closed'): ?>
        <form method="post" action="/shortlists/<?= (int) $s['id'] ?>/resend" style="display:inline"><?= csrf() ?><button class="btn ghost small" type="submit">Email it again</button></form>
        <form method="post" action="/shortlists/<?= (int) $s['id'] ?>/close" style="display:inline"><?= csrf() ?><button class="btn ghost small" type="submit" data-confirm-click="Close this link? The client won't be able to open it any more.">Close the link</button></form>
      <?php endif; ?></div>
  </div>
</div>
<div class="table-wrap"><table class="list">
  <thead><tr><th>Candidate</th><th>Client's decision</th><th>Comment</th></tr></thead>
  <tbody><?php foreach ($items as $it): ?>
    <tr><td><a href="/candidates/<?= (int) $it['candidate_id'] ?>"><b><?= e($it['p']['name'] ?? 'Candidate') ?></b></a><?= $it['summary'] ? '<br><span class="small muted">' . e(mb_strimwidth($it['summary'], 0, 140, '…')) . '</span>' : '' ?></td>
      <td class="nowrap"><?= $it['decision'] ? '<span class="chip ' . $cls[$it['decision']] . '">' . e($labels[$it['decision']]) . '</span><br><span class="small muted">' . e(nice_date($it['decided_at'], true)) . '</span>' : '<span class="muted">waiting</span>' ?></td>
      <td><?= e((string) $it['comment']) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<p class="hint" style="margin-top:10px">Nobody is moved automatically when the client decides. Update their stages from the role page when you are ready.</p>
