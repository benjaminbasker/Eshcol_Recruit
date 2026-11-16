<?php
/** The client's positions. @var array $me @var array $roles */
$labels = ['open' => 'Open', 'on_hold' => 'On hold', 'filled' => 'Filled', 'draft' => 'Requested'];
$live = array_filter($roles, fn ($r) => in_array($r['status'], ['open', 'on_hold'], true));
$waiting = array_sum(array_column($roles, 'undecided'));
?>
<section class="pub-card">
  <?php $cl = client_logo($me, 'pub', '/portal/logo'); if ($cl): ?><div class="pub-for"><?= $cl ?></div><?php endif; ?>
  <p class="eyebrow">Client portal</p>
  <h1>Your positions</h1>
  <p><?= $waiting ? '<b>' . $waiting . ' candidate' . ($waiting === 1 ? ' is' : 's are') . ' waiting for your decision.</b> ' : '' ?>Open a position to see every candidate we have sent you, give your decision, and offer interview times.</p>
  <p><a class="btn ghost small" href="/portal/new">Ask for a new position</a></p>
</section>
<?php if (!$roles): ?>
  <section class="pub-card sl-card"><p style="margin:0">No positions yet. Use <b>Ask for a new position</b> to send us your first requirement.</p></section>
<?php endif; ?>
<?php foreach ($roles as $r): $isDraft = $r['status'] === 'draft'; ?>
  <section class="pub-card sl-card portal-role">
    <div class="portal-role-head">
      <h2><?php if ($isDraft): ?><?= e($r['title']) ?><?php else: ?><a href="/portal/roles/<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a><?php endif; ?></h2>
      <span class="badge <?= e($r['status']) ?>"><?= e($labels[$r['status']] ?? $r['status']) ?></span>
    </div>
    <p class="small muted" style="margin:0 0 10px"><?= e(implode(' · ', array_filter([$r['code'], $r['location'], WORK_MODES[$r['work_mode'] ?? ''] ?? null, (int) $r['positions'] > 1 ? (int) $r['positions'] . ' positions' : null]))) ?></p>
    <?php if ($isDraft): ?>
      <p class="small" style="margin:0">Requested on <?= e(nice_date($r['requested_at'])) ?>. We are checking the details and will start the search shortly.</p>
    <?php else: ?>
      <p style="margin:0 0 10px">
        <span class="chip"><?= (int) $r['sent'] ?> candidate<?= (int) $r['sent'] === 1 ? '' : 's' ?> sent</span>
        <?php if ($r['undecided']): ?><span class="chip flag"><?= (int) $r['undecided'] ?> waiting for your decision</span><?php endif; ?>
        <?php if ($r['interviews']): ?><span class="chip yes"><?= (int) $r['interviews'] ?> interview<?= (int) $r['interviews'] === 1 ? '' : 's' ?> booked</span><?php endif; ?>
      </p>
      <a class="btn small" href="/portal/roles/<?= (int) $r['id'] ?>"><?= $r['undecided'] ? 'Review candidates' : 'Open' ?></a>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
