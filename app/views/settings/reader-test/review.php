<?php
use Eshcol\Ats\Services\ReaderTest;

$readers = ReaderTest::READERS;
$tid = (int) $test['id'];
$truth = $item['truth'];
$values = $truth ?? $suggest['values'];
$decide = $truth !== null ? [] : $suggest['decide'];
$jobs = $item['readers']['sonnet']['raw'] ?? $item['readers']['haiku']['raw'] ?? null;
$jobs = $jobs ? (json_decode((string) $jobs, true)['jobs'] ?? []) : [];
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings/reader-test">Reader test</a> › <a href="/settings/reader-test/<?= $tid ?>">Results</a> › CV <?= (int) $pos ?> of <?= (int) $total ?></p>
  <h1><span class="ref"><?= ref_candidate($cid) ?></span> <?= e($item['name'] ?: 'Name not found') ?></h1>
  <p class="small muted" style="margin:4px 0 0">Picked as: <?= e(ReaderTest::GROUPS[$item['grp']] ?? '') ?> · <a href="/candidates/<?= $cid ?>">candidate page</a>
    <?= $prev ? ' · <a href="/settings/reader-test/' . $tid . '/cv/' . $prev . '">‹ previous</a>' : '' ?><?= $next ? ' · <a href="/settings/reader-test/' . $tid . '/cv/' . $next . '">next ›</a>' : '' ?></p></div></div>

<style>.rt-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:18px;align-items:start}.rt-scroll{overflow-x:auto}
@media (min-width:1280px){.rt-grid{grid-template-columns:minmax(0,1fr) minmax(0,1.25fr)}}</style>
<div class="rt-grid">
  <section class="card" style="padding:0;overflow:hidden">
    <?php if ($item['cv_file_id'] && in_array($item['ext'], ['pdf', 'jpg', 'jpeg', 'png'], true)): ?>
      <iframe src="/cv/<?= (int) $item['cv_file_id'] ?>?view=1" title="The CV" style="width:100%;height:82vh;border:0;display:block"></iframe>
    <?php elseif ($item['cv_file_id']): ?>
      <p style="padding:18px">This CV is a <?= e(strtoupper((string) $item['ext'])) ?> file: <a href="/cv/<?= (int) $item['cv_file_id'] ?>">download it</a> to check.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2 style="margin-top:0">What each reader found</h2>
    <p class="small">The <b>Answer</b> column is what the CV really says. Agreed values are filled in; <span class="chip no" style="text-decoration:none">red rows</span> need you.
      Leave a box empty when the CV doesn't state it. Several acceptable answers: separate them with <b>|</b>. Experience in years (e.g. 5.5), as the CV states it, or else added up from its job dates without internships.</p>
    <?php foreach ($readers as $r => $label): $err = $item['readers'][$r]['error'] ?? null; if ($err): ?>
      <div class="flash error small"><?= e($label) ?> failed: <?= e($err) ?></div>
    <?php endif; endforeach; ?>
    <form method="post" action="/settings/reader-test/<?= $tid ?>/cv/<?= $cid ?>"><?= csrf() ?>
      <div class="rt-scroll"><table class="list small">
        <thead><tr><th>Detail</th><?php foreach ($readers as $label): ?><th><?= e($label) ?></th><?php endforeach; ?><th style="min-width:170px">Answer</th></tr></thead>
        <tbody>
        <?php foreach (ReaderTest::FIELDS as $k => $label): $hot = in_array($k, $decide, true); ?>
          <tr<?= $hot ? ' style="background:var(--red-tint)"' : '' ?>><td><b><?= e($label) ?></b></td>
            <?php foreach ($readers as $r => $_): $v = ReaderTest::display($k, $item['readers'][$r]['fields'][$k] ?? null); ?>
              <td><?= $v === '' ? '<span class="muted">–</span>' : e($v) ?></td>
            <?php endforeach; ?>
            <td><input type="text" name="truth[<?= e($k) ?>]" value="<?= e(ReaderTest::display($k, $values[$k] ?? '')) ?>" aria-label="Answer: <?= e($label) ?>" style="width:100%"></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="actions" style="margin-top:12px"><button class="btn" type="submit"><?= $truth !== null ? 'Save changes' : 'Save and check the next CV' ?></button></div>
    </form>
    <?php if ($jobs): ?>
      <h3 style="margin-top:20px">Jobs Claude read (to check experience)</h3>
      <table class="list small">
        <thead><tr><th>Position</th><th>Employer</th><th>From</th><th>To</th></tr></thead>
        <tbody><?php foreach ($jobs as $j): ?>
          <tr><td><?= e($j['title'] ?? '') ?><?= !empty($j['internship']) ? ' <span class="chip sand">internship</span>' : '' ?></td><td><?= e($j['company'] ?? '') ?></td><td><?= e($j['start'] ?? '') ?></td><td><?= e($j['end'] ?? '') ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    <?php endif; ?>
  </section>
</div>
