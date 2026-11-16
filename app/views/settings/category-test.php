<?php
/**
 * One run of the category test (13 Nov 2026).
 * @var array $test @var array $bench @var array $results @var int $waiting
 */
$app = \Eshcol\Ats\App::get();
$fn = fn (?string $c) => $c ? \Eshcol\Ats\Services\Categoriser::functionName($app, $c) : '–';
$readers = \Eshcol\Ats\Services\CategoryTest::READERS;
$sum = $results['summary'];
$pc = fn (?int $v) => $v === null ? '–' : $v . '%';
$res = \Eshcol\Ats\Services\FunctionRouting::RESULTS;
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings">Settings</a> › <a href="/settings/categories#test">Categories</a></p><h1>Category test</h1>
  <p class="muted"><?= e($test['name']) ?>. Made-up CVs with known answers; no real candidate is involved.</p></div>
  <form method="post" action="/settings/category-test/<?= (int) $test['id'] ?>/delete" data-confirm="Delete this test and its results?"><?= csrf() ?><button class="btn ghost small" type="submit">Delete</button></form></div>

<?php if ($waiting): ?>
  <section class="card sand"><p style="margin:0 0 8px"><b><?= $waiting ?></b> still to run. Keep this page open while it runs (Claude takes a few seconds for each).</p>
    <p class="actions" style="margin:0"><button class="btn small" type="button" id="cat-run" data-url="/settings/category-test/<?= (int) $test['id'] ?>/step" data-left="<?= $waiting ?>">Run it</button>
      <button class="btn ghost small" type="button" id="cat-stop" hidden>Stop</button><span class="small" id="cat-progress" role="status"></span></p></section>
<?php endif; ?>

<section class="card accent">
  <h2>Results</h2>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Reader</th><th class="num">CVs</th><th class="num">Right field</th><th class="num">Right or acceptable</th><th class="num">Seniority</th><th class="num">Where they'd work</th><th class="num">Quotes really in the CV</th><th class="num">Routing right on both positions</th><th class="num">Missed suitable</th><th class="num">False matches</th><th class="num">Cost per CV</th><th class="num">Time</th></tr></thead>
    <tbody><?php foreach ($sum as $r => $s): ?>
      <tr><td><b><?= e($readers[$r] ?? $r) ?></b><?= $s['failed'] ? '<br><span class="small err">' . $s['failed'] . ' failed</span>' : '' ?></td>
        <td class="num"><?= $s['n'] ?></td><td class="num"><?= $pc($s['function_exact']) ?></td><td class="num"><?= $pc($s['function_ok']) ?><br><span class="small muted">held out <?= $pc($s['function_ok_held']) ?></span></td>
        <td class="num"><?= $pc($s['seniority_ok']) ?></td><td class="num"><?= $pc($s['where_ok']) ?></td><td class="num"><?= $pc($s['evidence_real']) ?></td>
        <td class="num"><?= $pc($s['routing_ok']) ?><br><span class="small muted">held out <?= $pc($s['routing_ok_held']) ?></span></td>
        <td class="num"><?= $s['missed'] ?> of <?= $s['should_join'] ?></td><td class="num"><?= $s['false'] ?></td>
        <td class="num">₹<?= number_format($s['per_cv'] * INR_PER_USD, 2) ?></td><td class="num"><?= $s['ms'] ? number_format($s['ms'] / 1000, 1) . ' s' : '–' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p class="small muted" style="margin-bottom:0">"Held out": the <?= count(array_filter($bench['cases'], fn ($c) => !empty($c['held_out']))) ?> cases not looked at while the instructions were written. "Missed suitable": a person who should be on a position's pipeline but wasn't. "False matches": the other way round. The free guess has no quotes.</p>
</section>

<section class="card">
  <h2>Case by case</h2>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Case</th><th>Right answer</th><?php foreach (array_keys($sum) as $r): ?><th><?= e($readers[$r] ?? $r) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($bench['cases'] as $c): $row = $results['cases'][$c['key']] ?? []; ?>
      <tr><td><b><?= e($c['key']) ?></b><?= !empty($c['held_out']) ? ' <span class="chip">held out</span>' : '' ?><br><span class="small muted"><?= e($bench['groups'][$c['group']] ?? $c['group']) ?> · <?= e($c['title']) ?>, <?= e($c['city'] ?: 'city not given') ?>, <?= $c['years'] === null ? '?' : e($c['years'] + 0) ?> yrs</span>
          <details><summary class="small">CV</summary><pre class="cvtext" style="max-height:220px"><?= e($c['cv']) ?></pre></details></td>
        <td class="small"><?= e($fn($c['expect']['function'])) ?><?= !empty($c['expect']['also']) ? ' (or ' . e(implode(', ', array_map($fn, $c['expect']['also']))) . ')' : '' ?><br><?= e(implode('/', $c['expect']['seniority'])) ?> · relocation <?= e($c['expect']['relocation']) ?><br>Associate: <?= e($res[$c['route']['assoc']] ?? '') ?><br>Manager: <?= e($res[$c['route']['manager']] ?? '') ?></td>
        <?php foreach (array_keys($sum) as $r): $x = $row[$r] ?? null; ?>
          <td class="small"><?php if (!$x): ?>–<?php elseif (isset($x['error'])): ?><span class="err"><?= e((string) $x['error']) ?></span><?php else: $m = $x['mark']; $v = $x['res']; ?>
            <span class="<?= $m['function_ok'] ? '' : 'err' ?>"><?= $m['function_ok'] ? '✓' : '✗' ?> <?= e($fn($v['function'] ?? null)) ?><?= !empty($v['secondary_function']) ? ' + ' . e($fn($v['secondary_function'])) : '' ?></span><br>
            <span class="<?= $m['seniority_ok'] ? '' : 'err' ?>"><?= $m['seniority_ok'] ? '✓' : '✗' ?> <?= e((string) ($v['seniority'] ?? '')) ?></span> ·
            <span class="<?= $m['where_ok'] ? '' : 'err' ?>"><?= $m['where_ok'] ? '✓' : '✗' ?> <?= e((string) ($v['relocation'] ?? '')) ?><?= !empty($v['preferred_cities']) ? ' (' . e(implode(', ', (array) $v['preferred_cities'])) . ')' : '' ?></span><br>
            <span class="<?= $m['route_assoc_ok'] ? '' : 'err' ?>"><?= $m['route_assoc_ok'] ? '✓' : '✗' ?> A: <?= e($res[$m['route_assoc']] ?? '') ?></span><br>
            <span class="<?= $m['route_manager_ok'] ? '' : 'err' ?>"><?= $m['route_manager_ok'] ? '✓' : '✗' ?> M: <?= e($res[$m['route_manager']] ?? '') ?></span>
            <?php if ($r !== 'free'): ?><br><span class="<?= $m['evidence_real'] ? 'muted' : 'err' ?>"><?= $m['evidence_real'] ? 'quotes real' : 'a quote is not in the CV' ?></span><?php endif; ?>
          <?php endif; ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<script src="<?= asset('categories.js') ?>"></script>
