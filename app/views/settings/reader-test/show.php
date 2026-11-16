<?php
use Eshcol\Ats\Services\ReaderTest;

$readers = ReaderTest::READERS;
$inr = fn (float $usd) => '₹' . number_format($usd * INR_PER_USD, $usd * INR_PER_USD < 10 ? 2 : 0);
$pct = fn ($v) => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
$aiRuns = count($pending);
$toCheck = array_filter($items, fn ($it) => $it['truth'] === null && count($it['readers']) === count($readers));
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings#ai">Settings</a> › <a href="/settings/reader-test">Reader test</a></p><h1><?= e($test['name']) ?></h1></div></div>

<section class="card">
  <h2>1. Run the AI readers</h2>
  <?php if ($aiRuns): ?>
    <p class="small"><?= $aiRuns ?> runs to do (<?= count($items) ?> CVs × Haiku and Sonnet). Expected cost about <?= $inr($aiRuns / 2 * (0.004 + 0.035)) ?>; spent so far <?= $inr($spent) ?>. Takes roughly <?= max(1, (int) round($aiRuns * 15 / 60)) ?> minutes: keep this page open.</p>
    <?php if ($hasKey): ?>
      <div class="actions">
        <button class="btn" type="button" id="rt-run" data-url="/settings/reader-test/<?= (int) $test['id'] ?>/run"
          data-jobs="<?= e(json_encode(array_map(fn ($j) => ['c' => $j[0], 'r' => $j[1]], $pending))) ?>">Run Claude Haiku and Sonnet</button>
        <button class="btn ghost small" type="button" id="rt-stop" hidden>Stop</button>
      </div>
      <p class="small" id="rt-progress" role="status" aria-live="polite"></p>
    <?php else: ?><div class="flash error">Add the Claude API key in <a href="/settings#ai">Settings › AI analysis</a> first.</div><?php endif; ?>
  <?php else: ?>
    <p class="small">Done: every CV has been read by all three. Spent <?= $inr($spent) ?> (US$<?= number_format($spent, 2) ?>).</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>2. Check the answers</h2>
  <p class="small"><?= (int) $score['checked'] ?> of <?= count($items) ?> CVs checked.
    <?php if ($toCheck): ?><a class="btn small" href="/settings/reader-test/<?= (int) $test['id'] ?>/cv/<?= array_key_first($toCheck) ?>">Check the next CV</a><?php endif; ?></p>
  <div style="overflow-x:auto"><table class="list small">
    <thead><tr><th>CV</th><th>Why picked</th><?php foreach ($readers as $r => $label): ?><th><?= e($label) ?></th><?php endforeach; ?><th>Checked</th></tr></thead>
    <tbody>
    <?php foreach ($items as $cid => $it): ?>
      <tr>
        <td><a href="/settings/reader-test/<?= (int) $test['id'] ?>/cv/<?= $cid ?>"><span class="ref"><?= ref_candidate($cid) ?></span> <?= e($it['name'] ?: 'Name not found') ?></a></td>
        <td><?= e(ReaderTest::GROUPS[$it['grp']] ?? '') ?></td>
        <?php foreach ($readers as $r => $label): $row = $it['readers'][$r] ?? null; ?>
          <td><?php if (!$row): ?><span class="muted">–</span><?php elseif ($row['error']): ?><span class="chip no" title="<?= e($row['error']) ?>">failed</span>
            <?php else: ?><?= count(array_intersect_key((array) $row['fields'], ReaderTest::FIELDS)) ?> of <?= count(ReaderTest::FIELDS) ?> found<?php endif; ?></td>
        <?php endforeach; ?>
        <td><?= $it['truth'] !== null ? '<span class="chip yes">checked</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="card" id="score">
  <h2>3. Scorecard</h2>
  <?php if (!$score['checked']): ?>
    <p class="small muted">Appears once CVs are checked.</p>
  <?php else: ?>
    <p class="small">Over <?= (int) $score['checked'] ?> checked CVs and <?= count(ReaderTest::FIELDS) ?> details each. <b>Right</b> and <b>missed</b> are shares of the details the CV actually states.
      <b>Wrong or invented</b> is the share of all details where the reader gave a value that is not in the CV, or not what the CV says. Pass mark: at least 98% right and no wrong names, emails or phones.</p>
    <div style="overflow-x:auto"><table class="list">
      <thead><tr><th></th><?php foreach ($readers as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <tr><td>Details right</td><?php foreach ($readers as $r => $_): ?><td><b><?= $pct($score['readers'][$r]['right_pct']) ?></b></td><?php endforeach; ?></tr>
        <tr><td>Missed (left blank)</td><?php foreach ($readers as $r => $_): ?><td><?= $pct($score['readers'][$r]['missed_pct']) ?></td><?php endforeach; ?></tr>
        <tr><td>Wrong or invented</td><?php foreach ($readers as $r => $_): ?><td><?= $pct($score['readers'][$r]['wrong_pct']) ?> <span class="small muted">(<?= $score['readers'][$r]['wrong'] + $score['readers'][$r]['invented'] ?>)</span></td><?php endforeach; ?></tr>
        <tr><td>Wrong name, email or phone</td><?php foreach ($readers as $r => $_):
            $n = count(array_filter($score['mistakes'], fn ($m) => $m['reader'] === $r && in_array($m['field'], ['full_name', 'email', 'phone'], true) && $m['kind'] !== 'missed')); ?>
            <td><?= $n ? '<span class="chip no">' . $n . '</span>' : '0' ?></td><?php endforeach; ?></tr>
        <tr><td>Cost per CV</td><?php foreach ($readers as $r => $_): ?><td><?= $r === 'free' ? '₹0' : $inr($score['readers'][$r]['cost_per_cv']) ?></td><?php endforeach; ?></tr>
        <tr><td>Time per CV</td><?php foreach ($readers as $r => $_): ?><td><?= $r === 'free' ? 'instant' : e($score['readers'][$r]['secs_per_cv']) . ' s' ?></td><?php endforeach; ?></tr>
        <tr><td>Runs that failed</td><?php foreach ($readers as $r => $_): ?><td><?= (int) $score['readers'][$r]['errors'] ?></td><?php endforeach; ?></tr>
      </tbody>
    </table></div>
    <h3 style="margin-top:22px">Detail by detail (right / stated in the CV · wrong or invented)</h3>
    <div style="overflow-x:auto"><table class="list small">
      <thead><tr><th></th><?php foreach ($readers as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (ReaderTest::FIELDS as $k => $label): ?>
        <tr><td><?= e($label) ?></td>
        <?php foreach ($readers as $r => $_): $t = $score['fields'][$k][$r] ?? null; ?>
          <td><?php if ($t): $stated = $t['right'] + $t['missed'] + $t['wrong']; $bad = $t['wrong'] + $t['invented']; ?>
            <?= $t['right'] ?> / <?= $stated ?><?= $bad ? ' · <span class="chip no" style="text-decoration:none">' . $bad . ' wrong</span>' : '' ?><?php endif; ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php $bad = array_filter($score['mistakes'], fn ($m) => $m['kind'] !== 'missed'); ?>
    <details style="margin-top:16px"><summary><b><?= count($bad) ?> wrong or invented details</b>, each with the right answer</summary>
      <div style="overflow-x:auto"><table class="list small">
        <thead><tr><th>CV</th><th>Reader</th><th>Detail</th><th>Read as</th><th>Right answer</th></tr></thead>
        <tbody>
        <?php foreach ($bad as $m): ?>
          <tr><td><a href="/settings/reader-test/<?= (int) $test['id'] ?>/cv/<?= (int) $m['candidate_id'] ?>"><?= ref_candidate($m['candidate_id']) ?></a></td><td><?= e($readers[$m['reader']]) ?></td>
            <td><?= e(ReaderTest::FIELDS[$m['field']]) ?></td><td><?= e($m['got']) ?></td><td><?= $m['truth'] === '' ? '<span class="muted">not in the CV</span>' : e($m['truth']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
  <?php endif; ?>
</section>

<form method="post" action="/settings/reader-test/<?= (int) $test['id'] ?>/delete" style="margin:8px 0 30px"><?= csrf() ?>
  <button class="btn ghost small" type="submit" data-confirm-click="Delete this test and its results? No candidate is affected.">Delete this test</button>
</form>
<script src="<?= asset('reader-test.js') ?>"></script>
