<?php /** @var array $s @var array $items @var string $subject */ $cvs = $s['mode'] === 'cvs'; ?>
<div class="page-head">
  <div><p class="eyebrow"><?= e($s['code']) ?> · <?= ref_shortlist($s['id']) ?> · draft</p><h1>Check and send</h1>
    <p class="muted"><?= e($s['title']) ?> · to <?= e($s['to_name'] ? $s['to_name'] . ' <' . $s['to_email'] . '>' : $s['to_email']) ?><?= $s['cc'] ? ' · cc ' . e($s['cc']) : '' ?></p></div>
  <form method="post" action="/shortlists/<?= (int) $s['id'] ?>/discard"><?= csrf() ?><button class="btn ghost" type="submit" data-confirm-click="Discard this draft? Nothing has been sent.">Discard draft</button></form>
</div>

<div class="split">
  <div>
    <?php if ($cvs): ?>
    <section class="card" id="cv-builder">
      <div class="actions" style="justify-content:space-between;align-items:center"><h2 style="margin:0">Branded CVs</h2><span class="small muted" id="cv-count"></span></div>
      <div class="progress" style="margin:10px 0 14px"><i id="cv-bar" style="width:0"></i></div>
      <?php foreach ($items as $it): $done = (bool) $it['branded_path']; ?>
        <div class="cvb-row<?= $done ? ' ready' : '' ?>" data-item data-base="/shortlists/<?= (int) $s['id'] ?>/items/<?= (int) $it['id'] ?>">
          <span class="avatar sm" aria-hidden="true"><?= e(initials($it['p']['name'] ?? '')) ?></span>
          <div class="cvb-who"><b><?= e($it['p']['name'] ?? 'Candidate') ?></b> <span class="ref small"><?= e($it['p']['ref'] ?? '') ?></span></div>
          <div class="cvb-status small" data-status><?= $done ? 'Ready · <a href="/shortlists/' . (int) $s['id'] . '/items/' . (int) $it['id'] . '/cv" target="_blank">open</a>' : 'Waiting…' ?></div>
        </div>
      <?php endforeach; ?>
      <p class="hint" style="margin:12px 0 0">Each CV gets an Eshcol cover page (reference, salary, notice, your remarks and your contact details), and our reference and contact line on every page. PDF CVs keep their own layout; Word CVs are converted. This happens in your browser and takes a few seconds per CV.</p>
    </section>
    <?php endif; ?>
    <section class="card">
      <div class="actions" style="justify-content:space-between;align-items:center"><h2 style="margin:0">The email</h2><span class="small muted">Subject: <b><?= e($subject) ?></b></span></div>
      <div class="email-frame"><iframe src="/shortlists/<?= (int) $s['id'] ?>/email" sandbox="allow-same-origin" title="Email preview"></iframe></div>
    </section>
  </div>
  <aside>
    <div class="card sand sticky-card">
      <h3>Ready to send?</h3>
      <p class="small"><?= count($items) ?> candidate<?= count($items) === 1 ? '' : 's' ?> to <?= e($s['to_email']) ?>. When you send, they move to "Submitted to client", and you get an email as soon as the client gives feedback.</p>
      <form method="post" action="/shortlists/<?= (int) $s['id'] ?>/send"><?= csrf() ?>
        <?php if (!empty($checks)):
          $names = array_column($items, null, 'application_id');
          $confirm = (bool) array_filter($checks, fn ($l) => (bool) array_filter($l, fn ($x) => $x[1])); ?>
          <div id="checks" class="check-box">
            <p class="small" style="margin:0 0 6px"><b><?= count($checks) ?> candidate<?= count($checks) > 1 ? 's need' : ' needs' ?> a second look</b></p>
            <ul class="small">
              <?php foreach ($checks as $appId => $list): $it = $names[$appId] ?? null; ?>
                <li><b><?= e((string) ($it['p']['name'] ?? 'Candidate')) ?></b>
                  <?php if ($it && !empty($it['candidate_id'])): ?> <a href="/candidates/<?= (int) $it['candidate_id'] ?>" class="muted">open</a><?php endif; ?>
                  <ul><?php foreach ($list as [$text, $mustConfirm]): ?><li<?= $mustConfirm ? ' class="text-bad"' : '' ?>><?= e($text) ?></li><?php endforeach; ?></ul></li>
              <?php endforeach; ?>
            </ul>
            <label for="check_reason" class="small" style="margin-top:6px">Your reason for sending anyway</label>
            <input type="text" id="check_reason" name="check_reason" maxlength="300" placeholder="e.g. Client asked to see all profiles; salary to be discussed">
            <?php if ($confirm): ?><label class="small" style="font-weight:normal;margin-top:6px"><input type="checkbox" name="confirm_resend" value="1"> Yes, send again someone this client has already seen</label><?php endif; ?>
          </div>
        <?php endif; ?>
        <button class="btn" id="send-btn" type="submit" style="width:100%"<?= $cvs && array_filter($items, fn ($i) => !$i['branded_path']) ? ' disabled' : '' ?>>Send to client</button>
      </form>
      <?php if ($cvs): ?><p class="hint" style="margin:8px 0 0">The button turns on when every CV is ready.</p><?php endif; ?>
    </div>
  </aside>
</div>
<?php if ($cvs): ?><script src="<?= asset('shortlist-cv.js') ?>"></script><?php endif; ?>
