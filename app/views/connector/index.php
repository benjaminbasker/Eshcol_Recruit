<?php /** @var bool $on @var ?string $key */ ?>
<div class="page-head"><div><p class="eyebrow">Owner only</p><h1>Connect Claude (read only)</h1>
  <p class="muted">Lets Claude on your own computer ask Recruit questions: which positions are open, who is on them, what Claude thinks of each CV. It can only <b>read</b>. It cannot move, delete, email or change anything, and it never sees email addresses, phone numbers or street addresses. People who asked to be removed never appear.</p></div></div>

<section class="card">
  <h2>Status: <?= $on ? 'on' : 'off' ?></h2>
  <?php if ($key): ?>
    <div class="flash ok" style="margin:0 0 12px"><b>Your key (shown only now; copy it into the setup file, then leave this page):</b>
      <p style="margin:8px 0;font-family:monospace;word-break:break-all;font-size:15px;user-select:all"><?= e($key) ?></p></div>
  <?php endif; ?>
  <form method="post" action="/connector" class="actions"><?= csrf() ?>
    <button class="btn" type="submit" data-confirm-click="<?= $on ? 'Make a NEW key? The old one stops working at once.' : 'Switch the link on and make a key?' ?>"><?= $on ? 'Make a new key' : 'Switch on and make a key' ?></button>
  </form>
  <?php if ($on): ?><form method="post" action="/connector" style="margin-top:8px"><?= csrf() ?><input type="hidden" name="remove" value="1"><button class="btn ghost small" type="submit">Switch the link off</button></form><?php endif; ?>
</section>

<section class="card">
  <h2>What Claude can ask</h2>
  <ul class="small">
    <li>The open positions and how many people are at each stage.</li>
    <li>One position: the job description and the kind-of-work counts.</li>
    <li>The people on a position, by stage or by Claude's kind-of-work answer.</li>
    <li>One person: facts, applications and the CV with name and contact details removed.</li>
    <li>A search by title, company, qualification or town.</li>
  </ul>
  <p class="small muted">Every request is signed with your key and must be under five minutes old. The key is stored encrypted here. Switching the link off or making a new key stops the old one at once.</p>
</section>
