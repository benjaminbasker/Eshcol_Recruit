<?php
/**
 * Settings › Categories (13 Nov 2026).
 * @var string $mode @var bool $hasKey @var array $counts @var int $limit @var int $used @var float $cost
 * @var array $review @var array $failed @var array $byField @var array $trial @var array $real @var array $tests
 */
$app = \Eshcol\Ats\App::get();
$fn = fn (?string $c) => \Eshcol\Ats\Services\Categoriser::functionName($app, $c);
$sen = \Eshcol\Ats\Services\Categoriser::SENIORITY;
$rupees = fn (float $usd) => '₹' . number_format($usd * INR_PER_USD, $usd * INR_PER_USD < 10 ? 1 : 0);
$missing = max(0, $counts['candidates'] - $counts['categorised']);
$results = \Eshcol\Ats\Services\FunctionRouting::RESULTS;
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings">Settings</a></p><h1>Categories</h1>
  <p class="muted">Every CV is filed by the person's own field of work, seniority and where they'd work, whatever job they applied for, so they can be found for future positions. Positions then take CVs by field, experience and location instead of by matching words in the CV.</p></div></div>

<section class="card accent">
  <h2>How it runs</h2>
  <form method="post" action="/settings/categories"><?= csrf() ?>
    <?php foreach (['off' => 'Off: nothing is categorised.', 'trial' => 'Trial: every new CV is categorised (Claude Haiku, about ₹0.03 each) and what the new routing would do is recorded below. The old rules still decide; nothing on the pipelines changes.',
            'on' => 'On: positions take CVs by field, experience and location. Someone from another city who applies for a local-only position is moved to "Future requirement" on it and filed under their field and city. Claude assesses the rest as before (half-price batches).'] as $k => $txt): ?>
      <label style="display:block;font-weight:normal;margin:6px 0"><input type="radio" name="mode" value="<?= $k ?>"<?= $mode === $k ? ' checked' : '' ?>> <b><?= e(\Eshcol\Ats\Services\Categoriser::MODES[$k]) ?></b>: <?= e(substr($txt, strpos($txt, ':') + 2)) ?></label>
    <?php endforeach; ?>
    <div class="row" style="align-items:flex-end;margin-top:10px">
      <div class="field" style="flex:0 1 220px"><label for="limit">Monthly limit <span class="hint">categorisations</span></label><input type="number" id="limit" name="limit" min="0" max="100000" value="<?= (int) $limit ?>"></div>
      <div class="field"><button class="btn" type="submit">Save</button></div>
    </div>
  </form>
  <p class="small muted" style="margin-bottom:0">This month: <?= (int) $used ?> categorised, <?= e($rupees($cost)) ?>.<?= $hasKey ? '' : ' <b>No Claude key:</b> the free guess from the job title is used instead.' ?></p>
</section>

<section class="card">
  <h2>Everyone on file</h2>
  <div class="grid g4" style="margin-bottom:12px">
    <div class="stat"><b><?= (int) $counts['categorised'] ?></b><span>of <?= (int) $counts['candidates'] ?> categorised</span></div>
    <div class="stat"><b><?= (int) $counts['pending'] ?></b><span>waiting in the queue</span></div>
    <div class="stat gold"><b><?= (int) $counts['review'] ?></b><span>unsure, for you to check</span></div>
    <div class="stat"><b><?= (int) $counts['failed'] ?></b><span>could not be categorised</span></div>
  </div>
  <?php if ($missing && $mode !== 'off'): ?>
    <form method="post" action="/settings/categories/queue-all" style="display:inline"><?= csrf() ?><button class="btn ghost small" type="submit">Queue the <?= $missing ?> not categorised yet</button></form>
    <span class="small muted">About <?= e($rupees($missing * 0.0004)) ?> in all. Nothing about them changes in trial; they are only filed.</span>
  <?php endif; ?>
  <?php if ($counts['pending']): ?>
    <p class="actions" style="margin-top:10px"><button class="btn small" type="button" id="cat-run" data-url="/settings/categories/step" data-left="<?= (int) $counts['pending'] ?>">Categorise them now</button>
      <button class="btn ghost small" type="button" id="cat-stop" hidden>Stop</button><span class="small" id="cat-progress" role="status"></span></p>
  <?php endif; ?>
  <?php if ($failed): ?>
    <details style="margin-top:10px"><summary class="small">Could not be categorised (<?= count($failed) ?>)</summary>
      <ul class="small"><?php foreach ($failed as $f): ?><li><a href="/candidates/<?= (int) $f['candidate_id'] ?>#category"><?= e($f['full_name'] ?: 'Candidate') ?></a>: <?= e((string) $f['error']) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <details style="margin-top:10px"><summary class="small">By field of work</summary>
    <div class="table-wrap" style="margin-top:8px"><table class="list compact"><thead><tr><th>Field</th><th class="num">People</th><th class="num">With a second field</th></tr></thead><tbody>
      <?php foreach ($byField as $b): ?><tr><td><a href="/candidates?function=<?= e($b['code']) ?>"><?= e($b['name']) ?></a></td><td class="num"><?= (int) $b['n'] ?></td><td class="num"><?= (int) $b['with_second'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div></details>
</section>

<section class="card" id="check">
  <h2>Unsure: please check</h2>
  <?php if (!$review): ?>
    <p class="small muted" style="margin:0">Nothing waiting. Categories Claude is unsure of (or files under "Other") appear here.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="list compact"><thead><tr><th>Candidate</th><th>Claude's category</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
      <?php foreach ($review as $r): ?>
        <tr><td><a href="/candidates/<?= (int) $r['candidate_id'] ?>?from=categories#category"><b><?= e($r['full_name'] ?: 'Candidate') ?></b></a><br><span class="small muted"><?= e((string) $r['current_title']) ?></span></td>
          <td class="small"><?= e($fn($r['function_code'])) ?><?= $r['secondary_code'] ? ' + ' . e($fn($r['secondary_code'])) : '' ?><?= $r['seniority'] ? ' · ' . e($sen[$r['seniority']] ?? '') : '' ?> · <?= e($r['confidence']) ?> confidence</td>
          <td><form method="post" action="/candidates/<?= (int) $r['candidate_id'] ?>/category/confirm" style="margin:0;display:inline"><?= csrf() ?><input type="hidden" name="back" value="settings"><button class="btn ghost small" type="submit">Looks right</button></form>
            <a class="btn ghost small" href="/candidates/<?= (int) $r['candidate_id'] ?>?from=categories#category">Correct it</a></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="card" id="trial">
  <div class="card-head"><h2>The trial: old rules against new</h2>
    <form method="post" action="/settings/categories/trial" style="margin:0"><?= csrf() ?><button class="btn ghost small" type="submit">Work it out again</button></form></div>
  <p class="small muted" style="margin-top:0">For everyone categorised, on each open position: are they on the pipeline and sent to Claude under today's rules (the must-have words), and would they be under the new ones (field, experience, location)? Free. Press "Work it out again" after changing a position.</p>
  <?php foreach ($trial as $t): $r = $t['role']; ?>
    <h3 style="margin:14px 0 4px"><a href="/roles/<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a> <span class="small muted"><?= e($r['location']) ?> · <?= e(\Eshcol\Ats\Services\FunctionRouting::SCOPES[\Eshcol\Ats\Services\FunctionRouting::scopeOf($r)]) ?></span></h3>
    <p class="small" style="margin:0 0 6px"><b><?= $t['both'] ?></b> on the pipeline both ways · <b><?= $t['neither'] ?></b> off it both ways · <b><?= $t['join_n'] ?></b> would join under the new rules · <b><?= $t['leave_n'] ?></b> would leave<?= $t['by_reason'] ? ' (' . e(implode(', ', array_map(fn ($k, $n) => "$n " . mb_strtolower($results[$k] ?? $k), array_keys($t['by_reason']), $t['by_reason']))) . ')' : '' ?>.</p>
    <?php if ($t['leave'] || $t['join']): ?>
      <details><summary class="small">Who differs</summary>
        <div class="table-wrap" style="margin-top:8px"><table class="list compact"><thead><tr><th>Candidate</th><th>Today</th><th>New rules</th></tr></thead><tbody>
          <?php foreach (array_merge($t['leave'], $t['join']) as $d): ?>
            <tr><td><a href="/candidates/<?= (int) $d['candidate_id'] ?>"><?= e($d['full_name'] ?: 'Candidate') ?></a><br><span class="small muted"><?= e((string) $d['location']) ?></span></td>
              <td class="small"><?= $d['old_result'] === 'pipeline' ? 'On the pipeline' : e(($d['old_result'] === 'not_on_role' ? 'Not on it' : 'On it, not sent to Claude') . ($d['old_reason'] ? ': ' . $d['old_reason'] : '')) ?></td>
              <td class="small"><b><?= e($results[$d['new_result']] ?? $d['new_result']) ?></b><?= $d['new_reason'] ? ': ' . e($d['new_reason']) : '' ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></details>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$trial): ?><p class="small muted">No open positions.</p><?php endif; ?>
</section>

<section class="card" id="test">
  <h2>How accurate is it?</h2>
  <h3 style="margin-bottom:4px">On your own candidates</h3>
  <p class="small" style="margin-top:0"><?php if ($real['checked']): ?>You have checked <b><?= $real['checked'] ?></b> of Claude's categories and left <b><?= $real['kept'] ?></b> as they were (<b><?= $real['pct'] ?>%</b>); you corrected <?= $real['changed'] ?>.<?php else: ?>None checked yet.<?php endif; ?>
    To measure it properly, open about 60 of these (a few from every field), and press "Looks right" or correct them. Their CVs stay where they are; nothing is sent anywhere.</p>
  <?php if ($real['sample']): ?>
    <details><summary class="small">The next <?= count($real['sample']) ?> to check</summary>
      <ul class="small" style="columns:2;margin-top:8px"><?php foreach ($real['sample'] as $s): ?><li><a href="/candidates/<?= (int) $s['candidate_id'] ?>?from=categories#category"><?= e($s['full_name'] ?: 'Candidate') ?></a>: <?= e($fn($s['function_code'])) ?><?= $s['confidence'] !== 'high' ? ' (' . e($s['confidence']) . ')' : '' ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <h3 style="margin-bottom:4px">The category test</h3>
  <p class="small" style="margin-top:0">38 made-up CVs with known answers: poorly written but suitable, keyword-rich but unsuitable, the wrong field, other cities, unclear titles and career changes, awkward layouts, and two with instructions hidden in them. Each is categorised and marked, and the new routing is checked on two positions shaped like AdvantEdge's. No real candidate is involved.</p>
  <form method="post" action="/settings/category-test" class="actions"><?= csrf() ?>
    <label style="font-weight:normal"><input type="checkbox" name="readers[]" value="free" checked> Free guess (₹0)</label>
    <label style="font-weight:normal"><input type="checkbox" name="readers[]" value="haiku" checked> Claude Haiku (about ₹2)</label>
    <label style="font-weight:normal"><input type="checkbox" name="readers[]" value="sonnet" checked> Claude Sonnet (about ₹35)</label>
    <button class="btn small" type="submit">Start a test</button>
  </form>
  <?php if ($tests): ?><ul class="small"><?php foreach ($tests as $t): ?><li><a href="/settings/category-test/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a><?= $t['waiting'] ? ' · ' . (int) $t['waiting'] . ' still to run' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<script src="<?= asset('categories.js') ?>"></script>
