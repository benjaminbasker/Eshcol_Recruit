<?php
$hour = (int) date('G');
$first = e(explode(' ', trim($user['name'] ?? ''))[0] ?? '');
$bands = [['New', '#C8B6E6'], ['Under review', '#9B7BD4'], ['With client', '#6B3FB4'], ['Interview', '#D8B36A'], ['Offer / joined', '#26733A']];
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayLabel = fn (string $d) => $d === $today ? 'Today' : ($d === $tomorrow ? 'Tomorrow' : date('l j M', strtotime($d)));
$kindIcon = ['virtual' => 'video', 'in_person' => 'pin', 'phone' => 'phone'];
?>
<?php // My day, redesigned 14 Nov 2026 (Benjamin's mix): C's aubergine band with A's number tiles, C's alert cards,
      // an Applications & responses panel, A's tabs and pipeline bar, B's Today timeline. ?>
<section class="day-band" id="day">
  <div class="db-hello">
    <h1>Good <?= $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening') ?>, <?= $first ?></h1>
    <p><?= e(date('l, j F Y')) ?> · <?php
      $n = count($todo); $iv = count(array_filter($schedule, fn ($s) => substr($s['starts_at'], 0, 10) === $today));
      echo $iv ? "$iv interview" . ($iv > 1 ? 's' : '') . ' today · ' : '';
      echo $n ? "$n thing" . ($n > 1 ? 's' : '') . ' waiting on you' : 'nothing waiting on you';
    ?></p>
    <div class="db-actions">
      <span class="seg" role="group" aria-label="Whose positions">
        <a class="btn small<?= ($view ?? 'all') === 'mine' ? '' : ' ghost' ?>" href="/?view=mine">My positions</a>
        <a class="btn small<?= ($view ?? 'all') === 'all' ? '' : ' ghost' ?>" href="/?view=all">Everyone</a>
      </span>
      <a class="btn gold small" href="/upload"><?= icon('upload', 'sm') ?>Upload CVs</a>
    </div>
  </div>
  <div class="db-kpis">
    <a class="db-kpi" href="/roles"><span class="ic m1"><?= icon('briefcase') ?></span><span><b><?= (int) $stats['open_roles'] ?></b>Open positions</span></a>
    <div class="db-kpi"><span class="ic m3"><?= icon('users') ?></span><span><b><?= number_format((int) $stats['in_play']) ?></b>Candidates in progress</span></div>
    <a class="db-kpi" href="/candidates"><span class="ic m2"><?= icon('inbox') ?></span><span><b><?= number_format((int) $stats['candidates']) ?></b>In your database<em>+<?= (int) $stats['new_week'] ?> this week</em></span></a>
    <div class="db-kpi"><span class="ic m5"><?= icon('calendar') ?></span><span><b><?= (int) ($interviewsWeek ?? 0) ?></b>Interviews this week</span></div>
  </div>
</section>

<?php // The three alerts that matter most as cards; any more as a short list below them.
$cards = array_slice($alerts, 0, 3);
$restAlerts = array_slice($alerts, 3); ?>
<?php if ($cards): ?>
  <div class="alert-cards">
    <?php foreach ($cards as $i => [$type, $html]): ?>
      <div class="a-card <?= $type === 'error' ? 'a-red' : ['a-gold', 'a-grape', 'a-green'][$i % 3] ?>"><?= icon($type === 'error' ? 'alert' : 'bell', 'sm') ?><div><?= $html /* fixed text built in DashboardController */ ?></div></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php foreach ($restAlerts as [$type, $html]): ?>
  <div class="flash <?= e($type) ?>"><?= icon($type === 'error' ? 'alert' : 'bell', 'sm') ?><div><?= $html ?></div></div>
<?php endforeach; ?>
<?php foreach ($scanAlerts ?? [] as [$type, $html]): ?>
  <div class="flash <?= e($type) ?>"><?= icon($type === 'error' ? 'alert' : 'file', 'sm') ?><div><?= $html /* fixed text from AiReading::scanAlerts */ ?></div></div>
<?php endforeach; ?>

<?php if (!empty($resp)): $periods = ['today' => 'Today', 'week' => 'Week to date', 'month' => 'Month to date']; ?>
<section class="card resp-board" id="responses">
  <div class="card-head"><h2><?= icon('check') ?> Applications and responses</h2><a class="small" href="/details-requests">Responses <?= icon('chev-right', 'sm') ?></a></div>
  <div class="resp-grid">
    <?php foreach ($periods as $k => $label): $r = $resp[$k]; ?>
      <div class="resp-col">
        <p class="resp-label"><?= e($label) ?></p>
        <div class="resp-row"><span>Applications received</span><b><?= number_format($r['applications']) ?></b></div>
        <div class="resp-row"><span>Mailed for details</span><b><?= number_format($r['asked']) ?></b></div>
        <div class="resp-row key"><span>Responded</span><b><?= number_format($r['answered']) ?></b></div>
        <div class="resp-rate"><?= $r['rate'] !== null ? '<b>' . (int) $r['rate'] . '%</b> of those mailed have answered' : '<span class="muted">nobody mailed yet</span>' ?><?= $r['declined'] ? ' · ' . (int) $r['declined'] . ' not interested' : '' ?></div>
        <?php if ($r['cvs']): ?><div class="resp-rate"><b><?= (int) $r['cvs'] ?></b> updated CV<?= $r['cvs'] > 1 ? 's' : '' ?> from "Share your updated CV"</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="split">
  <div>
    <section class="card" id="waiting">
      <div class="card-head"><h2><?= icon('flag') ?> Waiting on you</h2><?php if ($todo): ?><span class="badge"><?= count($todo) ?></span><?php endif; ?></div>
      <?php if (!$todo): ?>
        <div class="empty" style="padding:22px 10px"><?= icon('check') ?><h3>You're all caught up</h3><p class="small">Client replies, interview outcomes and screening answers will show up here.</p></div>
      <?php else: ?>
        <?php // Grouped by kind, each with its count (audit UI 7.1), most urgent kinds first.
          $kinds = ['star' => 'Client decisions', 'clipboard' => 'Interviews', 'clock' => 'Interviews', 'send' => 'Shortlists to send',
              'alert' => 'Messages', 'chat' => 'Messages', 'check' => 'Applications to review', 'list' => 'Applications to review',
              'mail' => 'Contact changes to approve', 'x' => 'No longer interested'];
          $groups = [];
          foreach ($todo as $t) { $groups[$kinds[$t['icon']] ?? 'Other'][] = $t; }
          $order = array_flip(['Client decisions', 'Interviews', 'Messages', 'Applications to review', 'Shortlists to send', 'Contact changes to approve', 'No longer interested', 'Other']);
          uksort($groups, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99)); ?>
        <div class="todo-tabs" role="tablist" aria-label="Kinds of things waiting">
          <button type="button" class="on" data-todo-tab="all">All <?= count($todo) ?></button>
          <?php foreach ($groups as $gName => $items): ?><button type="button" data-todo-tab="<?= e($gName) ?>"><?= e($gName) ?> <?= count($items) ?></button><?php endforeach; ?>
        </div>
        <?php foreach ($groups as $gName => $items): ?>
          <div data-todo-group="<?= e($gName) ?>" id="waiting-<?= e(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($gName)), '-')) ?>">
          <p class="todo-group"><?= e($gName) ?> <span class="badge"><?= count($items) ?></span></p>
          <ul class="attention">
            <?php foreach ($items as $t): ?>
              <li>
                <span class="ico <?= e($t['tone']) ?>"><?= icon($t['icon']) ?></span>
                <div class="txt"><b><?= e($t['title']) ?></b><span class="small muted"><?= e($t['sub']) ?></span></div>
                <a class="btn ghost small" href="<?= e($t['url']) ?>"><?= e($t['cta']) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <?php // A's colourful pipeline: every open position added together, by stage band.
    $tot = array_fill(0, count($bands), 0);
    foreach ($pipes as $p) { foreach ($p as $i => $c) { $tot[$i] = ($tot[$i] ?? 0) + (int) $c; } }
    $all = max(1, array_sum($tot)); ?>
    <?php if (array_sum($tot)): ?>
    <section class="card" id="pipeline">
      <div class="card-head"><h2><?= icon('list') ?> Pipeline</h2><span class="small muted"><?= ($view ?? 'all') === 'mine' ? 'your positions' : 'all open positions' ?></span></div>
      <div class="pipe-big"><?php foreach ($bands as $i => [$label, $color]): if (!empty($tot[$i])): ?><i style="width:<?= max(3, round($tot[$i] / $all * 100, 1)) ?>%;background:<?= $color ?>" title="<?= e($label) ?>: <?= (int) $tot[$i] ?>"></i><?php endif; endforeach; ?></div>
      <div class="pipe-legend"><?php foreach ($bands as $i => [$label, $color]): ?><span><i style="background:<?= $color ?>"></i><b><?= number_format((int) ($tot[$i] ?? 0)) ?></b><?= e($label) ?></span><?php endforeach; ?></div>
    </section>
    <?php endif; ?>

    <section class="card" id="open-roles" style="padding:0;overflow:hidden">
      <div class="card-head" style="padding:16px 20px 0"><h2><?= icon('briefcase') ?> Open roles</h2><a class="small" href="/roles">All roles <?= icon('chev-right', 'sm') ?></a></div>
      <?php if (!$roles): ?>
        <div class="empty"><?= icon('briefcase') ?><h3>No open roles yet</h3><p><a class="btn" href="/roles/new"><?= icon('plus') ?>Create your first role</a></p></div>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table class="list">
          <thead><tr><th>Role</th><th>Pipeline</th><th class="nowrap">Not reviewed</th><th class="nowrap">Open for</th></tr></thead>
          <tbody>
          <?php foreach ($roles as $r): $p = $pipes[(int) $r['id']] ?? []; $sum = array_sum($p); ?>
            <tr>
              <td><div style="display:flex;gap:10px;align-items:flex-start"><?= client_logo($r, 'sm') ?><div><a href="/roles/<?= $r['id'] ?>"><b><?= e($r['title']) ?></b></a>
                <?php if ($r['status'] !== 'open'): ?> <span class="badge <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span><?php endif; ?>
                <br><span class="small muted"><?= e($r['client']) ?><?= $r['location'] ? ' · ' . e($r['location']) : '' ?></span></div></div></td>
              <td style="min-width:170px">
                <div class="pipe" title="<?= e(implode(' · ', array_map(fn ($i) => $bands[$i][0] . ': ' . ($p[$i] ?? 0), array_keys($bands)))) ?>">
                  <?php foreach ($bands as $i => [$label, $color]): if (!empty($p[$i])): ?><i style="width:<?= round($p[$i] / max(1, $sum) * 100, 1) ?>%;background:<?= $color ?>"></i><?php endif; endforeach; ?>
                </div>
                <span class="small muted"><?= (int) $r['total'] ?> CV<?= (int) $r['total'] === 1 ? '' : 's' ?> · <?= (int) $r['above'] ?> above threshold</span>
              </td>
              <td><?= (int) $r['fresh'] ? '<span class="chip sand">' . (int) $r['fresh'] . '</span>' : '<span class="muted">0</span>' ?></td>
              <td class="nowrap muted"><?= (int) $r['age_days'] ?> days</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <div class="small muted" style="padding:10px 20px 14px;display:flex;gap:14px;flex-wrap:wrap;border-top:1px solid var(--line)">
          <?php foreach ($bands as [$label, $color]): ?><span><i style="display:inline-block;width:9px;height:9px;border-radius:3px;background:<?= $color ?>;margin-right:5px"></i><?= e($label) ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <aside>
    <?php // B's Today: today's interviews by time, then the first few things waiting on you. ?>
    <section class="card" id="today">
      <div class="card-head"><h2><?= icon('clock') ?> Today</h2><span class="small muted"><?= e(date('D j M')) ?></span></div>
      <?php prime_stages(array_merge(array_column($schedule, 'candidate_id'), array_column($recent ?? [], 'id'))); // stage tags on every list (17 Nov 2026) ?>
      <?php $todayIv = array_values(array_filter($schedule, fn ($s) => substr($s['starts_at'], 0, 10) === $today)); ?>
      <div class="timeline">
        <?php foreach ($todayIv as $s): ?>
          <div class="t"><time><?= e(date('g:i a', strtotime($s['starts_at']))) ?></time>Interview: <a href="/candidates/<?= (int) $s['candidate_id'] ?>#interviews"><b><?= e($s['full_name']) ?></b></a> <?= stage_tags($s['candidate_id']) ?> <span class="small muted">· <?= e($s['title']) ?>, round <?= (int) $s['round'] ?></span></div>
        <?php endforeach; ?>
        <?php foreach (array_slice($todo, 0, $todayIv ? 3 : 5) as $t): ?>
          <div class="t any"><time>Today</time><?= e($t['title']) ?> <a class="small" href="<?= e($t['url']) ?>"><?= e($t['cta']) ?></a></div>
        <?php endforeach; ?>
        <?php if (!$todayIv && !$todo): ?><p class="small muted" style="margin:0">Nothing booked or waiting today.</p><?php endif; ?>
      </div>
    </section>

    <section class="card" id="interviews">
      <div class="card-head"><h2><?= icon('calendar') ?> Interviews</h2><a class="small" href="/calendar">Calendar ›</a></div>
      <?php if (!$schedule): ?>
        <p class="muted small" style="margin:0">No interviews booked. When a candidate picks a time, it appears here.</p>
      <?php else: $lastDay = null; ?>
        <ul class="day">
          <?php foreach ($schedule as $s): $d = substr($s['starts_at'], 0, 10); ?>
            <?php if ($d !== $lastDay): $lastDay = $d; ?><li style="border:0;padding:8px 0 0"><span class="eyebrow" style="margin:0"><?= e($dayLabel($d)) ?></span></li><?php endif; ?>
            <li>
              <span class="t"><?= e(date('H:i', strtotime($s['starts_at']))) ?></span>
              <div style="flex:1;min-width:0">
                <a href="/candidates/<?= $s['candidate_id'] ?>#interviews"><b><?= e($s['full_name']) ?></b></a> <?= stage_tags($s['candidate_id']) ?>
                <div class="small muted"><?= icon($kindIcon[$s['kind']] ?? 'calendar', 'sm') ?> Round <?= (int) $s['round'] ?> · <?= e($s['title']) ?> · <?= e($s['client']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card" id="recent">
      <div class="card-head"><h2><?= icon('users') ?> Recently added</h2><a class="small" href="/candidates?sort=new">See all</a></div>
      <?php if (!$recent): ?>
        <p class="muted small" style="margin:0">No candidates yet. <a href="/upload">Upload some CVs</a>.</p>
      <?php endif; ?>
      <?php foreach ($recent as $c): $nm = $c['full_name'] ?: 'Name not found'; ?>
        <a href="/candidates/<?= $c['id'] ?>" style="display:flex;gap:10px;align-items:center;padding:7px 0;color:inherit;text-decoration:none">
          <span class="avatar sm" style="--h:<?= avatar_hue($nm) ?>"><?= e(initials($nm)) ?></span>
          <span style="flex:1;min-width:0"><b style="font-weight:600"><?= e($nm) ?></b><br>
            <span class="small muted"><?= e(experience(exp_now($c))) ?><?= $c['location'] ? ' · ' . e($c['location']) : '' ?></span><?= ($st = stage_tags($c['id'])) ? '<br>' . $st : '' ?></span>
          <span class="small muted nowrap"><?= e(nice_date($c['created_at'])) ?></span>
        </a>
      <?php endforeach; ?>
    </section>
  </aside>
</div>
