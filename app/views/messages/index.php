<?php /** @var array $rows */ ?>
<div class="page-head">
  <div><p class="eyebrow">Candidates</p><h1>Messages</h1>
    <p class="muted">Emails between you and candidates. Their replies to any of our emails (and what they write on their application form) arrive here through resume@; your answers go from resume@ and their reply comes back into the same conversation.</p></div>
</div>
<?php if (!$rows): ?>
  <div class="card empty">No messages yet. When a candidate replies to one of our emails, it appears here.</div>
<?php else: ?>
  <div class="table-wrap"><table class="list">
    <thead><tr><th>Candidate</th><th>Latest</th><th>When</th><th></th></tr></thead>
    <?php $msgKf = (new \Eshcol\Ats\Services\KeyFacts(\Eshcol\Ats\App::get()))->forCandidates(array_column($rows, 'candidate_id')); // the same key details on every list (16 Nov 2026) ?>
    <tbody><?php prime_stages(array_column($rows, 'candidate_id')); foreach ($rows as $r): ?>
      <tr<?= $r['unread'] ? ' class="unread"' : '' ?>>
        <td><a href="/candidates/<?= (int) $r['candidate_id'] ?>#messages"><b><?= e($r['full_name'] ?: 'Candidate') ?></b></a>
          <?php if ($r['unread']): ?><span class="chip yes"><?= (int) $r['unread'] ?> new</span><?php endif; ?>
          <?php if ($r['removal'] === 'asked'): ?><span class="chip flag">may want removal</span><?php endif; ?>
          <?= stage_tags($r['candidate_id']) ?>
          <br><span class="small muted"><?= e(ref_candidate((int) $r['candidate_id'])) ?></span>
          <?php $mk = $msgKf[(int) $r['candidate_id']] ?? []; if ($mk): ?><br><?= \Eshcol\Ats\Services\KeyFacts::line($mk) ?> <?= \Eshcol\Ats\Services\KeyFacts::cvButton($mk) ?><?php endif; ?></td>
        <td class="small"><?= $r['direction'] === 'out' ? '<span class="muted">You:</span> ' : ($r['source'] === 'form' ? '<span class="muted">Form:</span> ' : '') ?><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $r['body']), 0, 110, '…')) ?></td>
        <td class="small nowrap"><?= e(nice_date($r['received_at'], true)) ?></td>
        <td class="nowrap"><a class="btn ghost small" href="/candidates/<?= (int) $r['candidate_id'] ?>#messages"><?= $r['direction'] === 'in' ? 'Reply' : 'Open' ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <script type="module" src="<?= asset('cv-viewer.js') ?>"></script>
<?php endif; ?>
