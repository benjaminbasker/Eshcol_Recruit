<?php /** Messages: a plain inbox (simplified 28 Nov 2026). Unread in bold, read in regular; a click opens the conversation. @var array $rows */
$when = function (string $dt): string {
    $t = strtotime($dt);
    if (date('Y-m-d', $t) === date('Y-m-d')) {
        return date('g:i a', $t);
    }
    return date('Y', $t) === date('Y') ? date('j M', $t) : date('j M Y', $t);
};
?>
<div class="page-head">
  <div><p class="eyebrow">Candidates</p><h1>Messages</h1>
    <p class="muted">Replies from candidates, newest first. Unread ones are in bold.</p></div>
</div>
<?php if (!$rows): ?>
  <div class="card empty">No messages yet. When a candidate replies to one of our emails, it appears here.</div>
<?php else: ?>
  <div class="card inbox">
    <?php foreach ($rows as $r): $u = (int) $r['unread'] > 0; ?>
      <a class="inbox-row<?= $u ? ' is-unread' : '' ?>" id="m-<?= (int) $r['candidate_id'] ?>" href="/candidates/<?= (int) $r['candidate_id'] ?>#messages">
        <span class="inbox-dot" aria-hidden="true"></span>
        <span class="inbox-who"><span class="inbox-name"><?= e($r['full_name'] ?: 'Candidate') ?></span>
          <?php if ($r['role_title']): ?><span class="inbox-role"><?= e($r['role_title']) ?></span><?php endif; ?></span>
        <span class="inbox-text"><?php if ($r['removal'] === 'asked'): ?><span class="chip flag">may want removal</span> <?php endif; ?><?= $r['direction'] === 'out' ? 'You: ' : '' ?><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $r['body']), 0, 140, '…')) ?></span>
        <span class="inbox-when"><?= e($when((string) $r['received_at'])) ?><?php if ($u): ?><span class="visually-hidden">, unread</span><?php endif; ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
