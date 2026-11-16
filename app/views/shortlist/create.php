<div class="page-head">
  <div><p class="eyebrow"><?= e($role['client']) ?> · <?= e($role['code']) ?></p><h1>Send a shortlist</h1>
    <p class="muted">For <a href="/roles/<?= (int) $role['id'] ?>"><?= e($role['title']) ?></a>. Tick the candidates and add your remarks. Next you'll see the branded CVs being prepared and the exact email, before anything is sent.</p></div>
  <a class="btn ghost" href="/roles/<?= (int) $role['id'] ?>">Back to the role</a>
</div>
<?php if (!$apps): ?>
  <div class="card empty">Nobody on this role is in an active stage yet.</div>
<?php else: ?>
<form method="post" action="/roles/<?= (int) $role['id'] ?>/shortlist"><?= csrf() ?>
  <section class="card">
    <h2>Candidates</h2>
    <p class="hint">Ticked: everyone in the "Shortlisted" stage who hasn't been sent before. Your <b>remarks</b> go in the email table and on the CV's cover page, e.g. "Recently moved to Pune" or "Average communication, otherwise good".</p>
    <?php foreach ($apps as $a): ?>
      <div class="sl-row">
        <label class="sl-pick"><input type="checkbox" name="ids[]" value="<?= (int) $a['id'] ?>"<?= $a['preselect'] ? ' checked' : '' ?>>
          <b><?= e($a['full_name'] ?: 'Name not found') ?></b>
          <span class="small muted"> · <?= e($a['stage']) ?> · score <?= $a['eff_score'] ?? '–' ?><?= $a['current_title'] ? ' · ' . e($a['current_title']) : '' ?><?= $a['sent_before'] ? ' · <b>sent before' . ($a['last_decision'] ? ' (' . e(\Eshcol\Ats\Services\Shortlists::DECISIONS[$a['last_decision']]) . ')' : '') . '</b>' : '' ?></span></label>
        <textarea name="summary[<?= (int) $a['id'] ?>]" rows="2" aria-label="Remarks about <?= e($a['full_name']) ?>" placeholder="Remarks (optional)"><?= e($a['summary']) ?></textarea>
      </div>
    <?php endforeach; ?>
  </section>
  <section class="card">
    <h2>To the client</h2>
    <div class="row">
      <div class="field"><label for="to_name">Name</label><input type="text" id="to_name" name="to_name" value="<?= e($contact['name']) ?>"></div>
      <div class="field"><label for="to_email">Email</label><input type="email" id="to_email" name="to_email" value="<?= e($contact['email']) ?>" required></div>
      <div class="field"><label for="cc">CC <span class="hint">optional, comma-separated</span></label><input type="text" id="cc" name="cc" value=""></div>
    </div>
    <div class="field"><label for="note">Your message <span class="hint">leave empty for: "I am attaching four profiles for the <?= e($role['title']) ?> position. Kindly review the same and let me know your feedback."</span></label><textarea id="note" name="note" rows="3"></textarea></div>
    <p style="margin:0 0 6px"><b>What to send</b></p>
    <p style="margin:0 0 4px"><label style="font-weight:normal"><input type="radio" name="mode" value="cvs" checked> <b>Branded CVs</b>, with full contact details (your usual way)</label></p>
    <p style="margin:0 0 10px"><label style="font-weight:normal"><input type="radio" name="mode" value="profiles"> <b>Profiles only</b>, without email or phone, for clients who should come through you</label></p>
    <p><label style="font-weight:normal"><input type="checkbox" name="show_salary" value="1" checked> Show current and expected CTC</label></p>
    <div class="actions">
      <button class="btn" type="submit">Next: prepare CVs and email</button>
    </div>
  </section>
</form>
<?php endif; ?>
