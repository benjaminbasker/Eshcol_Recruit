<?php $kinds = ['ack' => 'Thank-you email', 'test' => 'Test email']; ?>
<div class="page-head">
  <div><p class="eyebrow"><?= e($kinds[$e['kind']] ?? ucfirst($e['kind'])) ?> · <?= e(['held' => 'waiting for you', 'pending' => 'queued', 'sent' => 'sent ' . nice_date($e['sent_at'], true), 'failed' => 'failed', 'cancelled' => 'cancelled', 'unsure' => 'may have been sent'][$e['status']]) ?></p>
    <h1><?= e($e['subject']) ?></h1>
    <p class="muted">To <?= $e['candidate_id'] ? '<a href="/candidates/' . (int) $e['candidate_id'] . '">' . e($e['full_name'] ?: $e['to_name'] ?: 'the candidate') . '</a>' : e($e['to_name'] ?: '') ?> &lt;<?= e($e['to_email']) ?>&gt;</p></div>
  <a class="btn ghost" href="/emails?tab=<?= e($e['status'] === 'cancelled' ? 'sent' : $e['status']) ?>">Back to emails</a>
</div>
<?php if ($e['status'] === 'sent'): ?>
  <div class="card small"><?php if (str_starts_with((string) $e['provider_id'], 'outbox:')): ?>
    <b>Test mode:</b> written to the server, <b>not sent</b>. To send for real, add the Brevo key and tick "Send for real" in <a href="/settings#email">Settings › Email</a>.
  <?php else: ?>
    <b>Accepted by Brevo</b> on <?= e(nice_date($e['sent_at'], true)) ?> (message ID <code><?= e((string) $e['provider_id']) ?></code>). If it hasn't arrived, Brevo › Transactional › Logs shows what happened next (delivered, deferred, bounced or blocked).
  <?php endif; ?></div>
<?php elseif ($e['status'] === 'held'): ?>
  <div class="card sand">
    <p><b>Why it waits:</b> <?= e((string) $e['hold_reason']) ?></p>
    <form method="post" action="/emails/<?= (int) $e['id'] ?>/release" class="row" style="align-items:flex-end"><?= csrf() ?>
      <div class="field" style="flex:2 1 260px;margin:0"><label for="to_email">Send to</label><input type="email" id="to_email" name="to_email" value="<?= e($e['to_email']) ?>" required></div>
      <div class="actions"><button class="btn" type="submit">Approve and send</button></div>
    </form>
    <p class="hint" style="margin:8px 0 0">If you change the address, it is saved on the candidate as confirmed. <a href="/candidates/<?= (int) $e['candidate_id'] ?>">Open the candidate</a> to see their CV.</p>
    <form method="post" action="/emails/<?= (int) $e['id'] ?>/cancel" style="margin-top:10px"><?= csrf() ?><input type="hidden" name="back" value="held"><button class="btn ghost small" type="submit">Don't send it</button></form>
  </div>
<?php elseif ($e['status'] === 'pending'): ?>
  <form method="post" action="/emails/<?= (int) $e['id'] ?>/cancel" class="card"><?= csrf() ?><input type="hidden" name="back" value="pending">
    <p class="small" style="margin:0 0 8px">Queued to go after <?= e(nice_date($e['not_before'], true)) ?>.<?= $e['last_error'] ? ' Last try: ' . e($e['last_error']) : '' ?></p><button class="btn ghost small" type="submit">Cancel it</button></form>
<?php elseif ($e['status'] === 'failed'): ?>
  <form method="post" action="/emails/<?= (int) $e['id'] ?>/retry" class="card"><?= csrf() ?>
    <p class="small" style="margin:0 0 8px"><b>Problem:</b> <?= e((string) $e['last_error']) ?></p><button class="btn ghost small" type="submit">Try again</button></form>
<?php elseif ($e['status'] === 'unsure'): ?>
  <div class="card sand">
    <p class="small" style="margin:0 0 8px"><b>It may already have arrived.</b> <?= e(rtrim((string) $e['last_error'], '. ')) ?>. It will not be sent again by itself. If <?= e($e['to_name'] ?: $e['to_email']) ?> did not get it, send it again; otherwise cancel it.</p>
    <form method="post" action="/emails/<?= (int) $e['id'] ?>/retry" style="display:inline"><?= csrf() ?><button class="btn small" type="submit">Send again</button></form>
    <form method="post" action="/emails/<?= (int) $e['id'] ?>/cancel" style="display:inline"><?= csrf() ?><input type="hidden" name="back" value="unsure"><button class="btn ghost small" type="submit">It arrived: cancel</button></form>
  </div>
<?php endif; ?>
<div class="card" style="padding:0;overflow:hidden">
  <iframe src="/emails/<?= (int) $e['id'] ?>/body" sandbox="allow-same-origin" title="Email preview" style="width:100%;height:1400px;border:0;display:block;background:#F2EFE7"></iframe>
</div>
