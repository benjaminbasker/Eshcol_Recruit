<div class="page-head">
  <div><p class="eyebrow">What you're hiring for</p><h1>Roles</h1></div>
  <?php $contractor = \Eshcol\Ats\Access::restricted($user ?? \Eshcol\Ats\Auth::user()); if (!$contractor): ?><a class="btn" href="/roles/new<?= !empty($type) ? '?type=' . e($type) : '' ?>">New role</a><?php endif; ?>
</div>
<p class="actions small" style="margin:-8px 0 16px">
  <?php foreach (['open' => 'Open', 'on_hold' => 'On hold', 'draft' => 'Draft', 'filled' => 'Filled', 'cancelled' => 'Cancelled', 'all' => 'All'] + ($contractor ? [] : ['pools' => 'Talent pools']) as $k => $label): ?>
    <a href="/roles?status=<?= $k ?>" class="<?= $status === $k ? 'btn small' : 'btn ghost small' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <?php if (!$contractor && $status !== 'pools'): ?>
    <span style="margin-left:12px" class="muted">·</span>
    <a href="/roles?status=<?= e($status) ?>&amp;view=mine" class="<?= ($view ?? 'all') === 'mine' ? 'btn small' : 'btn ghost small' ?>">My positions</a>
    <a href="/roles?status=<?= e($status) ?>&amp;view=all" class="<?= ($view ?? 'all') === 'all' ? 'btn small' : 'btn ghost small' ?>">Everyone's</a>
  <?php endif; ?>
</p>
<?php if ($status !== 'pools'): $tq = fn (string $t) => '/roles?status=' . e($status) . ($t !== '' ? '&amp;type=' . $t : ''); ?>
<nav class="tabs" aria-label="Type of position" style="margin:-6px 0 16px">
  <a href="<?= $tq('') ?>" class="<?= empty($type) ? 'on' : '' ?>">All <span class="n"><?= array_sum($typeCounts ?? []) ?></span></a>
  <?php foreach (\Eshcol\Ats\Services\Engagement::TYPES as $tk => $tl): ?><a href="<?= $tq($tk) ?>" class="<?= ($type ?? '') === $tk ? 'on' : '' ?>"><?= e($tl) ?> <span class="n"><?= (int) (($typeCounts ?? [])[$tk] ?? 0) ?></span></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if (!$roles && ($view ?? 'all') === 'mine' && !$contractor && $status !== 'pools'): ?>
  <div class="card empty">No positions are assigned to you here. <a href="/roles?status=<?= e($status) ?>&amp;view=all">See everyone's</a>, or choose yourself under "Recruiter" when you edit a position.</div>
<?php elseif (!$roles): ?>
  <div class="card empty"><?= $status === 'pools' ? 'No talent pools yet. They are created by themselves when a CV fits no open role, named after the candidate\'s own position (e.g. HR Executive).' : ($contractor ? 'No positions here. Positions are given to you by the Eshcol team.' : 'No roles here. <a href="/roles/new">Create a role</a>.') ?></div>
<?php else: ?>
  <div class="table-wrap"><table class="list">
    <thead><tr><th>Role</th><th>Client</th><th>Status</th><th>CVs</th><th>Above threshold</th><?= $status !== 'pools' ? '<th>Shortlisted</th>' : '' ?><th>Owner</th><th>Target</th></tr></thead>
    <tbody><?php foreach ($roles as $r): ?>
      <tr>
        <td><a href="/roles/<?= $r['id'] ?>"><b><?= e($r['title']) ?></b></a><br><span class="small muted"><?= e($r['code']) ?><?= $r['location'] ? ' · ' . e($r['location']) : '' ?></span></td>
        <td><?= e(\Eshcol\Ats\Services\Engagement::clientLabel(['name' => $r['client'], 'is_self' => $r['is_self'] ?? 0])) ?><?= \Eshcol\Ats\Services\Engagement::of($r) === 'staffing' ? ' <span class="chip sand" style="text-decoration:none">staffing</span>' : '' ?></td>
        <td><span class="badge <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
        <td><?= (int) $r['cvs'] ?></td><td><?= (int) $r['above'] ?></td>
        <?php if ($status !== 'pools'): $sh = (int) ($r['shortlisted'] ?? 0); $rd = (int) ($r['ready'] ?? 0); ?>
        <td class="nowrap"><?php if ($sh): ?>
          <a class="chip shorted" style="text-decoration:none" href="/roles/<?= (int) $r['id'] ?>#shortlisted" title="See the shortlisted candidates"><?= $sh ?> shortlisted</a>
          <?php if ($rd && \Eshcol\Ats\Services\Engagement::of($r) !== 'inhouse' && !$contractor): ?><br><a class="btn small" style="margin-top:6px" href="/roles/<?= (int) $r['id'] ?>/shortlist/new"><?= icon('send', 'sm') ?>Send to client (<?= $rd ?>)</a><?php endif; ?>
        <?php else: ?><span class="muted">–</span><?php endif; ?></td>
        <?php endif; ?>
        <td><?= e($r['owner'] ?? '–') ?></td>
        <td class="nowrap"><?= e(nice_date($r['target_date'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
<?php endif; ?>
