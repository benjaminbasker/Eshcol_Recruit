<?php $err = fn ($k) => isset($errors[$k]) ? '<p class="hint" style="color:var(--red)">' . e($errors[$k]) . '</p>' : ''; ?>
<div class="page-head"><div><p class="eyebrow">Candidate</p><h1>Edit and confirm details</h1>
  <p class="muted">Whatever you save here is marked as confirmed by you, and is never overwritten by a later CV.</p></div></div>
<form method="post" action="/candidates/<?= (int) $c['id'] ?>" class="card" style="max-width:860px">
  <?= csrf() ?>
  <div class="row">
    <div class="field"><label for="full_name">Full name</label><input type="text" id="full_name" name="full_name" value="<?= e($c['full_name']) ?>"></div>
    <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($c['email']) ?>"><?= $err('email') ?></div>
    <div class="field"><label for="phone">Phone</label><input type="text" id="phone" name="phone" value="<?= e($c['phone']) ?>"></div>
  </div>
  <div class="field"><label for="linkedin_url">LinkedIn profile <span class="hint">optional</span></label><input type="url" id="linkedin_url" name="linkedin_url" value="<?= e($c['linkedin_url'] ?? '') ?>" placeholder="https://www.linkedin.com/in/their-name"><?= $err('linkedin_url') ?></div>
  <div class="row">
    <div class="field"><label for="current_title">Current job title</label><input type="text" id="current_title" name="current_title" value="<?= e($c['current_title']) ?>"></div>
    <div class="field"><label for="current_company">Current employer</label><input type="text" id="current_company" name="current_company" value="<?= e($c['current_company']) ?>"></div>
    <div class="field"><label for="location">City</label><input type="text" id="location" name="location" value="<?= e($c['location']) ?>"></div>
    <div class="field"><label for="locality">Locality <span class="hint">area they live in</span></label><input type="text" id="locality" name="locality" value="<?= e($c['locality'] ?? '') ?>" list="locality_list">
      <?php $locMap = locality_map(); $locCity = locality_city((string) ($c['location'] ?? ''), $locMap) ?: 'Pune'; // the list follows the City box (17 Nov 2026) ?>
      <datalist id="locality_list"><?php foreach ($locMap['cities'][$locCity] ?? [] as $lo): ?><option value="<?= e($lo) ?>"><?php endforeach; ?></datalist>
      <script type="application/json" id="locality-map"><?= json_encode($locMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script></div>
  </div>
  <div class="row">
    <div class="field"><label for="exp_years">Experience today <span class="hint">years</span></label><input type="number" step="0.1" min="0" id="exp_years" name="exp_years" value="<?= exp_now($c) !== null ? e(round(exp_now($c) / 12, 1)) : '' ?>">
      <?php if (exp_is_estimate($c)): ?><p class="hint">Estimated: <?= e(experience($c['exp_months'])) ?> on <?= e(nice_date($c['exp_as_of'])) ?>, plus the time since.</p><?php endif; ?></div>
    <div class="field"><label for="ctc_current_lpa">Current CTC <span class="hint">LPA</span></label><input type="text" inputmode="decimal" id="ctc_current_lpa" name="ctc_current_lpa" value="<?= e($c['ctc_current_lpa']) ?>"><?= $err('ctc_current_lpa') ?></div>
    <div class="field"><label for="ctc_expected_lpa">Expected CTC <span class="hint">LPA</span></label><input type="text" inputmode="decimal" id="ctc_expected_lpa" name="ctc_expected_lpa" value="<?= e($c['ctc_expected_lpa']) ?>"><?= $err('ctc_expected_lpa') ?></div>
    <div class="field"><label for="ctc_min_lpa">Lowest they'd accept <span class="hint">LPA</span></label><input type="text" inputmode="decimal" id="ctc_min_lpa" name="ctc_min_lpa" value="<?= e($c['ctc_min_lpa']) ?>"><?= $err('ctc_min_lpa') ?></div>
    <div class="field"><label for="notice_days">Notice <span class="hint">days, 0 = immediate</span></label><input type="number" min="0" id="notice_days" name="notice_days" value="<?= e($c['notice_days']) ?>"></div>
  </div>
  <div class="field"><label for="qualifications">Qualifications</label><input type="text" id="qualifications" name="qualifications" value="<?= e($c['qualifications']) ?>"></div>
  <div class="field"><label for="channel">Came from <span class="hint">where we first got their CV</span></label><select id="channel" name="channel">
    <option value=""<?= empty($c['channel']) ? ' selected' : '' ?>>Not recorded</option>
    <?php foreach (CHANNELS as $ck => $cl): ?><option value="<?= e($ck) ?>"<?= ($c['channel'] ?? '') === $ck ? ' selected' : '' ?>><?= e($cl) ?></option><?php endforeach; ?>
  </select></div>
  <div class="field"><label for="keep_note">Keep in mind <span class="hint">e.g. "Strong, wrong role: good for senior accounts roles in Chennai"</span></label><input type="text" id="keep_note" name="keep_note" value="<?= e($c['keep_note']) ?>"></div>
  <div class="field"><label><input type="checkbox" name="do_not_contact" value="1"<?= $c['do_not_contact'] ? ' checked' : '' ?>> Do not contact <span class="hint">e.g. they asked to be removed. They will never be suggested for a role.</span></label></div>
  <div class="actions"><button class="btn" type="submit">Save and confirm</button><a href="/candidates/<?= (int) $c['id'] ?>">Cancel</a></div>
</form>
