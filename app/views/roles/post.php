<?php $q = fn (array $x) => '/roles/' . (int) $role['id'] . '/post?' . http_build_query(array_filter(['client' => $nameClient ? '1' : '', 'pay' => $showPay ? '1' : ''] + $x, fn ($v) => $v !== '')); ?>
<div class="page-head">
  <div><p class="eyebrow"><?= e($role['client']) ?> · <?= e($role['code']) ?></p><h1>Post this job</h1>
    <p class="muted">Copy-ready text for <a href="/roles/<?= (int) $role['id'] ?>"><?= e($role['title']) ?></a>. Paste it into each portal. Every version asks applicants to put <b><?= e($role['code']) ?></b> in the subject, so their emails can be matched to this role.</p></div>
  <a class="btn ghost" href="/roles/<?= (int) $role['id'] ?>">Back to the role</a>
</div>
<div class="card sand small">
  <b>Client's name:</b> <?= $nameClient ? 'shown' : 'hidden ("one of our clients")' ?> · <a href="<?= e($q(['client' => $nameClient ? '' : '1'])) ?>"><?= $nameClient ? 'Hide it' : 'Show it' ?></a>
  &nbsp;·&nbsp; <b>Salary:</b> <?= $showPay ? 'shown' : 'hidden' ?><?= $role['ctc_max_lpa'] === null ? ' (no budget set on the role)' : ' · <a href="' . e($q(['pay' => $showPay ? '' : '1'])) . '">' . ($showPay ? 'Hide it' : 'Show it') . '</a>' ?>
  <?php if (!$role['jd_text']): ?><br>Tip: paste the client's job description into the role (Edit) and it is added here too.<?php endif; ?>
</div>
<?php foreach ($blocks as $k => [$label, $text, $hint]): ?>
  <section class="card">
    <div class="actions" style="justify-content:space-between;align-items:center;margin-bottom:8px">
      <h2 style="margin:0;font-size:18px"><?= e($label) ?></h2>
      <button class="btn small" type="button" data-copy="post-<?= e($k) ?>">Copy</button>
    </div>
    <textarea id="post-<?= e($k) ?>" class="post-text" rows="<?= max(2, min(26, substr_count($text, "\n") + 2)) ?>" readonly><?= e($text) ?></textarea>
    <p class="hint" style="margin:6px 0 0"><?= e($hint) ?></p>
  </section>
<?php endforeach; ?>
