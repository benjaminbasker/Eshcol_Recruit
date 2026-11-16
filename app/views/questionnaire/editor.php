<?php
$labels = [
    'experience' => ['Total years of work experience', 'Always asked. Their own figure replaces the one read from the CV; flagged if it differs from the CV by more than two years.'],
    'ctc_current' => ['Current salary (CTC)', ''],
    'ctc_expected' => ['Expected salary (CTC)', $role['ctc_max_lpa'] !== null ? 'Flagged if above this role\'s budget of ' . lpa($role['ctc_max_lpa']) . '.' : 'Set a CTC budget on the role to flag answers above it.'],
    'notice' => ['How soon they could join', $role['notice_max_days'] !== null ? 'Flagged if longer than ' . (int) $role['notice_max_days'] . ' days, the role\'s maximum.' : 'Set a maximum notice on the role to flag longer notice.'],
    'location' => ['Which city they live in now', 'Always asked.'],
    'locality' => ['Which area or locality they live in', 'Always asked. Shown with their city, and for Pune how far they live from the client\'s office.'],
    'relocate' => ['Can they work in ' . ($role['location'] ?: 'the role\'s location') . '?', ($role['location'] ? 'Flagged if they answer No.' : 'Only asked once the role has a location.') . ' Always asked.'],
];
$blank = ['id' => 0, 'kind' => 'yesno', 'prompt' => '', 'options' => null, 'flag_answers' => null];
$rows = array_merge($custom, array_fill(0, max(0, min(2, 8 - count($custom))), $blank));
?>
<div class="page-head">
  <div><p class="eyebrow"><?= e($role['client']) ?> · <?= e($role['code']) ?></p><h1>Screening questions</h1>
    <p class="muted">For <a href="/roles/<?= (int) $role['id'] ?>"><?= e($role['title']) ?></a>. Candidates answer on their phone in about two minutes, through a personal link. Answers update their record and their score.</p></div>
  <a class="btn ghost" href="/roles/<?= (int) $role['id'] ?>">Back to the role</a>
</div>
<form method="post" action="/roles/<?= (int) $role['id'] ?>/questions"><?= csrf() ?>
<div class="split">
  <div>
    <section class="card">
      <h2>Standard questions</h2>
      <?php foreach ($labels as $k => [$label, $note]): ?>
        <p style="margin:0 0 10px"><label style="font-weight:normal"><input type="checkbox" name="std[]" value="<?= $k ?>"<?= in_array($k, $on, true) ? ' checked' : '' ?><?= in_array($k, \Eshcol\Ats\Services\Questionnaires::ALWAYS, true) ? ' disabled' : '' ?>> <b><?= e($label) ?></b></label>
          <?= $note ? '<br><span class="hint" style="margin-left:24px">' . e($note) . '</span>' : '' ?></p>
      <?php endforeach; ?>
    </section>
    <section class="card" id="new">
      <h2>Your own questions <span class="hint">up to 8</span></h2>
      <?php foreach ($rows as $i => $r): $opts = json_decode((string) $r['options'], true) ?: []; $flag = json_decode((string) $r['flag_answers'], true) ?: []; ?>
        <div class="qrow">
          <div class="row" style="align-items:flex-end">
            <div class="field" style="flex:3 1 280px;margin-bottom:8px"><label for="qp<?= $i ?>">Question <?= $i + 1 ?></label>
              <input type="text" id="qp<?= $i ?>" name="q[<?= $i ?>][prompt]" value="<?= e($r['prompt']) ?>" maxlength="255" placeholder="<?= $r['id'] ? '' : 'e.g. Have you filed GST returns yourself?' ?>"></div>
            <div class="field" style="flex:0 1 160px;margin-bottom:8px"><label for="qk<?= $i ?>">Answer type</label>
              <select id="qk<?= $i ?>" name="q[<?= $i ?>][kind]">
                <?php foreach (['yesno' => 'Yes / No', 'choice' => 'Choose one', 'text' => 'Short answer'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($r['kind'], $k) ?>><?= $v ?></option><?php endforeach; ?>
              </select></div>
            <div class="field" style="flex:0 1 150px;margin-bottom:8px"><label for="qf<?= $i ?>">For Yes / No: flag if</label>
              <select id="qf<?= $i ?>" name="q[<?= $i ?>][flag]"><option value="">Never</option><?php foreach (['Yes', 'No'] as $v): ?><option<?= $r['kind'] === 'yesno' && in_array($v, $flag, true) ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="field" style="margin-bottom:6px"><label for="qo<?= $i ?>">For "Choose one": the options, one per line <span class="hint">start a line with ! to flag that answer</span></label>
            <textarea id="qo<?= $i ?>" name="q[<?= $i ?>][options]" rows="3" placeholder="e.g.&#10;Tally Prime&#10;SAP&#10;!Neither"><?= e(implode("\n", array_map(fn ($o) => (in_array($o, $flag, true) ? '!' : '') . $o, $opts))) ?></textarea></div>
        </div>
      <?php endforeach; ?>
      <p class="hint">To remove a question, clear its text and save.</p>
    </section>
    <div class="actions"><button class="btn" type="submit">Save questions</button><?php if (count($custom) < 8): ?><button class="btn ghost" type="submit" name="add" value="1">Save and add more</button><?php endif; ?></div>
  </div>
  <aside>
    <div class="card sand">
      <h3>What the candidate sees</h3>
      <ol class="small" style="padding-left:18px;margin:0 0 10px">
        <?php foreach ($preview as $p): ?><li style="margin-bottom:6px"><?= e($p['prompt']) ?><?php if (in_array($p['kind'], ['choice', 'yesno', 'select'], true)): ?><br><span class="muted"><?= e(implode(' · ', $p['options'] ?? ['Yes', 'No'])) ?></span><?php endif; ?></li><?php endforeach; ?>
        <li>Keep my details on file for other roles? <span class="muted">(ticked unless they untick it)</span></li>
      </ol>
      <p class="hint" style="margin:0">Flagged answers are shown to you on the role page. Nobody is moved or rejected automatically.</p>
    </div>
  </aside>
</div>
</form>
