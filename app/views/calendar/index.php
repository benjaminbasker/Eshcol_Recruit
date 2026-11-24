<?php /** @var string $monday @var int $clientId @var array $clients @var array $week @var array $people @var array $kinds */
$q = fn (array $p) => '/calendar?' . http_build_query(array_filter(['week' => $p['week'] ?? $monday, 'client' => $p['client'] ?? ($clientId ?: null)]));
$here = $q([]);
$days = [];
for ($i = 0; $i < 6; $i++) { $days[] = date('Y-m-d', strtotime("$monday +$i days")); }
$sun = date('Y-m-d', strtotime("$monday +6 days"));
$byDay = [];
foreach ($week['booked'] as $b) { $byDay[substr($b['starts_at'], 0, 10)][] = ['t' => $b['starts_at'], 'type' => 'booked', 'x' => $b]; }
foreach ($week['open'] as $o) { $byDay[substr($o['starts_at'], 0, 10)][] = ['t' => $o['starts_at'], 'type' => 'open', 'x' => $o]; }
foreach ($byDay as &$list) { usort($list, fn ($a, $b) => [$a['t'], $a['type']] <=> [$b['t'], $b['type']]); }
unset($list);
$hasSunday = !empty($byDay[$sun]);
if ($hasSunday) { $days[] = $sun; }
$today = date('Y-m-d'); ?>
<div class="page-head">
  <div><p class="eyebrow">Interviews</p><h1>Interview calendar</h1>
    <p class="muted">Booked interviews and each client's free times. A time the client gives is offered on all its positions; once someone has it, it's gone for everyone. Give a free time to a candidate with <b>Allot</b>: the call letter and the client's confirmation go out as with Book.</p></div>
</div>

<div class="cal-bar">
  <div class="seg" role="group" aria-label="Week">
    <a href="<?= e($q(['week' => date('Y-m-d', strtotime("$monday -7 days"))])) ?>">‹ Previous</a>
    <a href="<?= e($q(['week' => date('Y-m-d')])) ?>" class="<?= $monday === \Eshcol\Ats\Controllers\CalendarController::monday('') ? 'on' : '' ?>">This week</a>
    <a href="<?= e($q(['week' => date('Y-m-d', strtotime("$monday +7 days"))])) ?>">Next ›</a>
  </div>
  <b class="cal-range"><?= e(date('j M', strtotime($monday))) ?> – <?= e(date('j M Y', strtotime("$monday +5 days"))) ?></b>
  <form method="get" action="/calendar" class="row" style="margin:0;gap:6px;align-items:center">
    <input type="hidden" name="week" value="<?= e($monday) ?>">
    <label for="cal-client" class="small muted">Client</label>
    <select id="cal-client" name="client" onchange="this.form.submit()">
      <option value="">All clients</option>
      <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($clientId, $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn small ghost">Show</button></noscript>
  </form>
</div>

<div class="cal-week" style="--days:<?= count($days) ?>">
  <?php foreach ($days as $d): $items = $byDay[$d] ?? []; ?>
    <section id="day-<?= e($d) ?>" class="cal-day<?= $d === $today ? ' today' : '' ?><?= $d < $today ? ' past' : '' ?>" aria-label="<?= e(date('l j F', strtotime($d))) ?>">
      <h2><span><?= e(date('D', strtotime($d))) ?></span> <?= e(date('j M', strtotime($d))) ?></h2>
      <?php if (!$items): ?><p class="cal-none">Nothing</p><?php endif; ?>
      <?php foreach ($items as $it): $x = $it['x']; $t = strtotime($it['t']); $end = $t + 60 * (int) $x['duration_min']; ?>
        <?php if ($it['type'] === 'booked'): ?>
          <div id="iv-<?= (int) ($x['id'] ?? 0) ?>" class="cal-item booked<?= $x['status'] === 'done' ? ' done' : '' ?>">
            <div class="cal-time"><?= e(date('g:i', $t)) ?>–<?= e(date('g:i A', $end)) ?></div>
            <a href="/candidates/<?= (int) $x['candidate_id'] ?>#interviews"><b><?= e($x['full_name']) ?></b></a>
            <div class="small"><?= e($x['title']) ?> · <?= e($x['client']) ?></div>
            <div class="small muted">Round <?= (int) $x['round'] ?> · <?= e($kinds[$x['kind']] ?? $x['kind']) ?><?= $x['status'] === 'done' ? ' · done' : '' ?></div>
            <?php if ($x['kind'] === 'virtual' && $x['meeting_link']): ?><a class="small" href="<?= e($x['meeting_link']) ?>" target="_blank" rel="noopener">Join link</a><?php endif; ?>
          </div>
        <?php else: $who = $people[(int) $x['client_id']] ?? []; ?>
          <div class="cal-item open">
            <div class="cal-time"><?= e(date('g:i', $t)) ?>–<?= e(date('g:i A', $end)) ?> <span class="chip">Free</span></div>
            <div class="small"><b><?= e($x['client']) ?></b> · <?= e($kinds[$x['kind']] ?? $x['kind']) ?> · round <?= (int) $x['round'] ?></div>
            <div class="small muted">For <?= e(implode(', ', $x['positions'])) ?></div>
            <?php if ($who): ?>
              <form method="post" action="/calendar/allot" class="cal-allot"><?= csrf() ?><input type="hidden" name="slot" value="<?= (int) $x['slot_id'] ?>"><input type="hidden" name="back" value="<?= e($here) ?>">
                <label class="visually-hidden" for="al-<?= (int) $x['slot_id'] ?>">Candidate</label>
                <select id="al-<?= (int) $x['slot_id'] ?>" name="application" required>
                  <option value="">Allot to…</option>
                  <?php foreach ($who as $title => $list): ?><optgroup label="<?= e($title) ?>"><?php foreach ($list as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['full_name']) ?> (<?= e($p['stage']) ?>)</option><?php endforeach; ?></optgroup><?php endforeach; ?>
                </select>
                <button class="btn small" type="submit" data-confirm-click="Book this time? The call letter goes to the candidate and the confirmation to the client now.">Allot</button>
              </form>
            <?php else: ?><p class="small muted" style="margin:4px 0 0">Nobody shortlisted to give it to.</p><?php endif; ?>
            <form method="post" action="/calendar/remove" style="margin:4px 0 0"><?= csrf() ?><input type="hidden" name="slot" value="<?= (int) $x['slot_id'] ?>"><input type="hidden" name="back" value="<?= e($here) ?>">
              <button class="btn link small" type="submit" data-confirm-click="Remove this free time from all of <?= e($x['client']) ?>'s positions?">Remove time</button></form>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
</div>

<div class="cal-lower">
  <section class="card">
    <h2>Add times the client gave you</h2>
    <p class="small muted" style="margin-top:0">Entered once for the client and offered on all its open positions.</p>
    <form method="post" action="/calendar/times" class="cal-add"><?= csrf() ?><input type="hidden" name="back" value="<?= e($here) ?>">
      <div class="field"><label for="ct-client">Client</label><select id="ct-client" name="client" required><option value="">Choose…</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($clientId, $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <?php $tpId = 'ct'; include __DIR__ . '/../partials/time_picker.php'; ?>
      <div class="row" style="gap:10px;flex-wrap:wrap">
        <div class="field"><label for="ct-round">Round</label><select id="ct-round" name="round"><?php for ($r = 1; $r <= 3; $r++): ?><option value="<?= $r ?>"><?= $r ?></option><?php endfor; ?></select></div>
        <div class="field"><label for="ct-kind">Type</label><select id="ct-kind" name="kind"><?php foreach ($kinds as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="ct-len">Length</label><select id="ct-len" name="duration"><?php foreach ([30, 45, 60, 90] as $m): ?><option value="<?= $m ?>"><?= $m ?> min</option><?php endforeach; ?></select></div>
      </div>
      <button class="btn" type="submit">Add these times</button>
    </form>
  </section>
  <section class="card">
    <h2>Invited, choosing a time (<?= count($week['choosing']) ?>)</h2>
    <?php if (!$week['choosing']): ?><p class="small muted" style="margin:0">Nobody is choosing right now.</p><?php endif; ?>
    <ul class="plain small">
      <?php foreach ($week['choosing'] as $c): ?><li><a href="/candidates/<?= (int) $c['candidate_id'] ?>#interviews"><b><?= e($c['full_name']) ?></b></a> · <?= e($c['title']) ?> · <?= e($c['client']) ?>, round <?= (int) $c['round'] ?></li><?php endforeach; ?>
    </ul>
  </section>
</div>
