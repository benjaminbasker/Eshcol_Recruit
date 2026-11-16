<div class="page-head"><div><p class="eyebrow"><a href="/settings#ai">Settings</a> › Reader test</p><h1>Which reader gets CV details right?</h1></div></div>
<section class="card" style="max-width:820px">
  <p>This runs three readers on the same CVs already in the app, side by side, and scores each one:</p>
  <ul class="small">
    <li><b>Free reader</b>: what the app uses today (no AI, no cost).</li>
    <li><b>Claude Haiku</b>: the cheaper AI, about ₹0.20–0.50 a CV.</li>
    <li><b>Claude Sonnet</b>: the stronger AI, about ₹2–4 a CV.</li>
  </ul>
  <p class="small">The CVs are picked hardest first: scans, experience flagged "Check", CVs with details missing, then 10 that looked complete.
    Where the readers agree, the answer is filled in for you; where they disagree, you open the CV and decide. Only checked CVs are scored.</p>
  <p class="small"><b>Nothing changes</b> for any candidate: the test only records what each reader found. Spending stops at US$<?= number_format(\Eshcol\Ats\Services\ReaderTest::BUDGET_USD, 2) ?> per test whatever happens.</p>
  <?php if (!$hasKey): ?><div class="flash error">Add the Claude API key in <a href="/settings#ai">Settings › AI analysis</a> first.</div><?php endif; ?>
  <form method="post" action="/settings/reader-test" class="actions"><?= csrf() ?>
    <label class="small" style="font-weight:normal">CVs <input type="number" name="max" value="50" min="5" max="80" style="width:80px"></label>
    <button class="btn" type="submit">Create a test</button>
  </form>
</section>
<?php if ($tests): ?>
<section class="card" style="max-width:820px">
  <h2>Tests</h2>
  <table class="list">
    <thead><tr><th>Test</th><th>CVs</th><th>Checked</th><th>Spent</th></tr></thead>
    <tbody>
    <?php foreach ($tests as $t): ?>
      <tr><td><a href="/settings/reader-test/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a></td><td><?= (int) $t['cvs'] ?></td><td><?= (int) $t['checked'] ?></td>
        <td>US$<?= number_format((float) $t['cost'], 2) ?> (₹<?= number_format((float) $t['cost'] * INR_PER_USD) ?>)</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
