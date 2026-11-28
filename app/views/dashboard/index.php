<?php
/**
 * My day, simplified 28 Nov 2026 (Benjamin: "make it simple and easier to attend to the pending things").
 * Left: the to-do list, grouped, one line per item with one button; applications to review collapsed into one line;
 * anything that is not a task as a short "Also worth a look" list. Right: interviews coming up, responses (three
 * lines; "the responded number is critical", 14 Nov) and open positions. Stats tiles, the pipeline, the Today
 * timeline (a repeat of the to-do list) and Recently added are gone: Reports, Roles and Candidates still have them.
 */
$hour = (int) date('G');
$first = e(explode(' ', trim($user['name'] ?? ''))[0] ?? '');
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayLabel = fn (string $d) => $d === $today ? 'Today' : ($d === $tomorrow ? 'Tomorrow' : date('D j M', strtotime($d)));

// The to-do list, grouped (most urgent kinds first). Applications to review become one line.
$kinds = ['star' => 'Client decisions', 'clipboard' => 'Interviews', 'clock' => 'Interviews', 'alert' => 'Messages', 'chat' => 'Messages',
    'check' => 'Applications to review', 'list' => 'Applications to review', 'send' => 'Shortlists to send',
    'mail' => 'Contact changes to approve', 'x' => 'No longer interested'];
$groups = [];
foreach ($todo as $t) {
    $groups[$kinds[$t['icon']] ?? 'Other'][] = $t;
}
$reviewCount = 0;
if (!empty($groups['Applications to review'])) {
    $items = $groups['Applications to review'];
    $reviewCount = count(array_filter($items, fn ($t) => $t['icon'] === 'check'));
    foreach ($items as $t) {
        if ($t['icon'] === 'list' && preg_match('/^(\d+)/', $t['title'], $m)) {
            $reviewCount += (int) $m[1];
        }
    }
    $flagged = count(array_filter($items, fn ($t) => $t['icon'] === 'check' && str_contains($t['sub'], 'to check')));
    $groups['Applications to review'] = [['icon' => 'check', 'title' => ($reviewCount >= 200 ? '200+' : $reviewCount) . ' completed application' . ($reviewCount === 1 ? '' : 's') . ' waiting for your review',
        'sub' => 'One after another, oldest first' . ($flagged ? " · $flagged of the first 10 have points to check" : ''), 'url' => $items[0]['url'], 'cta' => 'Start reviewing', 'count' => $reviewCount]];
}
$order = array_flip(['Client decisions', 'Interviews', 'Messages', 'Applications to review', 'Shortlists to send', 'Contact changes to approve', 'No longer interested', 'Other']);
uksort($groups, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
$waiting = count($todo) - count(array_filter($todo, fn ($t) => $t['icon'] === 'check' || $t['icon'] === 'list'));
$reviewLabel = $reviewCount >= 200 ? '200+' : (string) $reviewCount; // the list is capped at 200

// Problems stay at the top in red; everything else is a short list under the to-do.
$errors = array_values(array_filter($alerts, fn ($a) => $a[0] === 'error'));
$notes = array_merge(array_values(array_filter($alerts, fn ($a) => $a[0] !== 'error')), $scanAlerts ?? []);
$upcoming = array_slice($schedule, 0, 6);
?>
<div class="md-head">
  <div>
    <h1>Good <?= $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening') ?>, <?= $first ?></h1>
    <p class="muted"><?= e(date('l j F')) ?> · <?= $waiting ? "<b>$waiting</b> thing" . ($waiting > 1 ? 's' : '') . ' waiting on you' : 'nothing else waiting on you' ?><?= $reviewCount ? ", and <b>$reviewLabel</b> application" . ($reviewCount > 1 ? 's' : '') . ' to review' : '' ?></p>
  </div>
  <div class="md-head-actions">
    <span class="seg" role="group" aria-label="Whose positions">
      <a class="btn small<?= ($view ?? 'all') === 'mine' ? '' : ' ghost' ?>" href="/?view=mine">Mine</a>
      <a class="btn small<?= ($view ?? 'all') === 'all' ? '' : ' ghost' ?>" href="/?view=all">Everyone</a>
    </span>
    <a class="btn gold small" href="/upload"><?= icon('upload', 'sm') ?>Upload CVs</a>
  </div>
</div>

<?php foreach ($errors as [$type, $html]): ?>
  <div class="flash error"><?= icon('alert', 'sm') ?><div><?= $html /* fixed text built in DashboardController */ ?></div></div>
<?php endforeach; ?>

<div class="md-split">
  <section class="card md-todo" id="waiting">
    <h2>To do</h2>
    <?php if (!$groups): ?>
      <p class="md-empty"><?= icon('check') ?> You're all caught up. Client replies, interview outcomes, messages and completed applications will show up here.</p>
    <?php endif; ?>
    <?php foreach ($groups as $gName => $items): $gCount = $gName === 'Applications to review' ? ($reviewCount >= 200 ? '200+' : $reviewCount) : count($items); ?>
      <div class="md-group" id="waiting-<?= e(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($gName)), '-')) ?>">
        <p class="md-group-name"><?= e($gName) ?> <span><?= $gCount ?></span></p>
        <?php foreach ($items as $t): ?>
          <div class="md-item">
            <div class="md-item-text"><b><?= e($t['title']) ?></b><span><?= e($t['sub']) ?></span></div>
            <a class="btn small<?= $gName === 'Applications to review' ? '' : ' ghost' ?>" href="<?= e($t['url']) ?>"><?= e($t['cta']) ?></a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($notes): ?>
      <div class="md-notes">
        <p class="md-group-name">Also worth a look</p>
        <ul><?php foreach ($notes as [$type, $html]): ?><li class="<?= $type === 'warn' ? 'is-warn' : '' ?>"><?= $html /* fixed text built in DashboardController / AiReading */ ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  </section>

  <aside class="md-side">
    <section class="card" id="interviews">
      <div class="card-head"><h2>Interviews coming up</h2><a class="small" href="/calendar">Calendar</a></div>
      <?php if (!$upcoming): ?>
        <p class="small muted" style="margin:0">None booked.</p>
      <?php else: ?>
        <?php foreach ($upcoming as $s): $d = substr($s['starts_at'], 0, 10); ?>
          <a class="md-iv" href="/candidates/<?= (int) $s['candidate_id'] ?>#interviews">
            <span class="md-iv-when"><b><?= e($dayLabel($d)) ?></b><?= e(date('g:i a', strtotime($s['starts_at']))) ?></span>
            <span class="md-iv-who"><b><?= e($s['full_name']) ?></b><span><?= e($s['title']) ?> · round <?= (int) $s['round'] ?></span></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <?php if (!empty($resp)): ?>
    <section class="card" id="responses">
      <div class="card-head"><h2>Responses</h2><a class="small" href="/details-requests">See them</a></div>
      <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $k => $label): $r = $resp[$k]; ?>
        <div class="md-resp"><span><?= e($label) ?></span><span><b><?= number_format($r['answered']) ?></b> of <?= number_format($r['asked']) ?> mailed<?= $r['rate'] !== null ? ' · ' . (int) $r['rate'] . '%' : '' ?></span></div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="card" id="open-roles">
      <div class="card-head"><h2>Open positions</h2><a class="small" href="/roles">All</a></div>
      <?php if (!$roles): ?>
        <p class="small muted" style="margin:0">No open positions. <a href="/roles/new">Create one</a>.</p>
      <?php endif; ?>
      <?php foreach ($roles as $r): ?>
        <a class="md-role" href="/roles/<?= (int) $r['id'] ?>">
          <span class="md-role-name"><b><?= e($r['title']) ?></b><span><?= e($r['client']) ?></span></span>
          <span class="md-role-new"><?= (int) $r['fresh'] ? '<b>' . (int) $r['fresh'] . '</b> new' : '<span class="muted">0 new</span>' ?></span>
        </a>
      <?php endforeach; ?>
    </section>
  </aside>
</div>
