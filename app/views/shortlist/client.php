<?php $labels = \Eshcol\Ats\Services\Shortlists::DECISIONS; $first = trim((string) strtok((string) $s['to_name'], ' ')); ?>
<section class="pub-card">
  <?php $cl = client_logo($s, 'pub', '/s/' . $token . '/logo'); if ($cl): ?><div class="pub-for"><span class="small muted">Prepared for</span><?= $cl ?></div><?php endif; ?>
  <p class="eyebrow">Shortlist for your review</p>
  <h1><?= e($s['title']) ?></h1>
  <p><?= $first !== '' ? 'Dear ' . e($first) . ', here' : 'Here' ?> are <?= count($items) ?> candidate<?= count($items) === 1 ? '' : 's' ?> we have screened for this role. Mark each one <b>Shortlist</b>, <b>Hold</b> or <b>Not suitable</b>, add a comment if you like, and press <b>Send my feedback</b>. You can come back and change your answers until <?= e(nice_date($s['expires_at'])) ?>.</p>
  <p><?php if ($s['mode'] === 'cvs'): ?><a class="btn ghost small" href="/s/<?= e($token) ?>/zip">Download all CVs (ZIP)</a><?php else: ?><a class="btn ghost small" href="/s/<?= e($token) ?>/pdf" target="_blank">Download all profiles (PDF)</a><?php endif; ?></p>
  <?php if ($saved !== null): ?><div class="flash ok" role="status">Thank you: your feedback has reached us<?= $saved ? ' (' . $saved . ' of ' . count($items) . ' marked)' : '' ?>. We will be in touch about next steps.</div><?php endif; ?>
</section>
<form method="post" action="/s/<?= e($token) ?>"><?= csrf() ?>
<?php foreach ($items as $it): $p = $it['p']; ?>
  <section class="pub-card sl-card">
    <h2><?= e($p['name'] ?? 'Candidate') ?></h2>
    <?php if (!empty($p['title'])): ?><p class="muted" style="margin-top:-6px"><?= e($p['title']) ?><?= !empty($p['company']) ? ', ' . e($p['company']) : '' ?></p><?php endif; ?>
    <?php if ($s['mode'] === 'cvs'): ?><p style="margin:4px 0 10px"><a class="btn small" href="/s/<?= e($token) ?>/cv/<?= (int) $it['id'] ?>" target="_blank">Open CV</a> <span class="ref small"><?= e($p['ref'] ?? '') ?></span></p><?php endif; ?>
    <?php if ($it['summary']): ?><div class="sl-view"><span class="eyebrow">Our remarks</span><br><?= nl2br(e($it['summary'])) ?></div><?php endif; ?>
    <table class="qa">
      <?php foreach (['email' => 'Email', 'phone' => 'Phone', 'experience' => 'Experience', 'location' => 'Location', 'distance' => 'Distance', 'qualifications' => 'Qualification', 'notice' => 'Can join', 'ctc_current' => 'Current CTC', 'ctc_expected' => 'Expected CTC', 'ctc_min' => 'Lowest acceptable CTC'] as $k => $label): if (empty($p[$k])) { continue; } ?>
        <tr><td><?= e($label) ?></td><td><?= e($p[$k]) ?></td></tr>
      <?php endforeach; ?>
      <?php foreach ($p['answers'] ?? [] as $a): ?><tr><td><?= e($a['q']) ?></td><td><?= e($a['a']) ?></td></tr><?php endforeach; ?>
    </table>
    <?php if (!empty($p['matched'])): ?><p class="small" style="margin:12px 0 4px"><b>Matches your requirements:</b></p><p><?php foreach ($p['matched'] as $m): ?><span class="chip yes"><?= e($m) ?></span><?php endforeach; ?></p><?php endif; ?>
    <?php if (!empty($p['skills'])): ?><p class="small muted">Other skills: <?= e(implode(', ', $p['skills'])) ?></p><?php endif; ?>
    <fieldset class="pub-q">
      <legend>Your decision</legend>
      <div class="pub-opts">
        <?php foreach ($labels as $k => $label): ?>
          <label class="pub-opt"><input type="radio" name="decision[<?= (int) $it['id'] ?>]" value="<?= $k ?>"<?= $it['decision'] === $k ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
      <label for="cm<?= (int) $it['id'] ?>" class="small" style="display:block;margin-top:10px">Comment <span class="hint">optional</span></label>
      <textarea id="cm<?= (int) $it['id'] ?>" name="comment[<?= (int) $it['id'] ?>]" rows="2" maxlength="2000" placeholder="e.g. Please arrange a call on Thursday"><?= e((string) $it['comment']) ?></textarea>
    </fieldset>
  </section>
<?php endforeach; ?>
  <button class="btn pub-submit" type="submit">Send my feedback</button>
</form>
<section class="pub-card" style="margin-top:16px" id="times">
  <h2>Suggest interview times</h2>
  <p class="small">Give us the times when you are free, on one day or several, and choose how long each interview should be. We split each window into interview times, the candidates you shortlist each pick one, and we confirm every interview with you, with a calendar invite. For example, 5:00 to 6:00 pm with 20-minute interviews gives three times: 5:00, 5:20 and 5:40.</p>
  <form method="post" action="/s/<?= e($token) ?>/slots"><?= csrf() ?>
    <?php for ($d = 0; $d < 3; $d++): ?>
    <div class="row">
      <div class="field" style="flex:1 1 150px"><label for="ct-date<?= $d ?>">Day <?= $d + 1 ?><?= $d ? ' <span class="muted">(optional)</span>' : '' ?></label><input type="date" id="ct-date<?= $d ?>" name="date[]"<?= $d ? '' : ' required' ?> min="<?= date('Y-m-d') ?>"></div>
      <div class="field" style="flex:1 1 110px"><label for="ct-from<?= $d ?>">From</label><input type="time" id="ct-from<?= $d ?>" name="from[]"<?= $d ? '' : ' required' ?> step="300"></div>
      <div class="field" style="flex:1 1 110px"><label for="ct-to<?= $d ?>">To</label><input type="time" id="ct-to<?= $d ?>" name="to[]"<?= $d ? '' : ' required' ?> step="300"></div>
    </div>
    <?php endfor; ?>
    <div class="row">
      <div class="field" style="flex:1 1 150px"><label for="ct-dur">Each interview lasts</label><select id="ct-dur" name="duration">
        <?php foreach (\Eshcol\Ats\Services\ClientPortal::LENGTHS as $l): ?><option value="<?= $l ?>"<?= $l === 30 ? ' selected' : '' ?>><?= $l === 60 ? '1 hour' : $l . ' minutes' ?></option><?php endforeach; ?></select></div>
      <div class="field" style="flex:1 1 140px"><label for="ct-kind">Type</label><select id="ct-kind" name="kind"><option value="virtual">Virtual</option><option value="in_person">In person</option><option value="phone">Phone</option></select></div>
      <div class="field" style="flex:1 1 100px"><label for="ct-round">Round</label><input type="number" id="ct-round" name="round" min="1" max="9" value="1"></div>
    </div>
    <button class="btn" type="submit">Offer these times</button>
    <span class="small muted" style="margin-left:8px">Need more than three days? Send the form again.</span>
  </form>
</section>
  <p class="hint" style="text-align:center;margin-top:10px"><?= $s['mode'] === 'cvs' ? 'These CVs are shared in confidence for this role. Please quote the reference (EG-…) when you discuss a candidate with us.' : 'These profiles are shared in confidence for this role: please don\'t forward them outside your organisation. Please quote the reference (EG-…) when you discuss a candidate with us.' ?></p>
