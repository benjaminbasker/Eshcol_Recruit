<?php
$r = $role;
$editing = !empty($r['id']);
$err = fn ($k) => isset($errors[$k]) ? '<p class="hint err">' . e($errors[$k]) . '</p>' : '';
$isPool = !empty($r['is_pool']);
$req = fn (string $k) => $isPool ? '' : ' data-required';
$star = $isPool ? '' : ' <span class="req" title="Needed to open the role">*</span>';
$miss = fn (string $k) => isset($errors[$k]) ? ' missing' : '';
$jdFile = $jdFile ?? null;
$lines = function (string $kind) use ($criteria): string {
    return implode("\n", array_column(array_filter($criteria, fn ($c) => $c['kind'] === $kind), 'value'));
};
$wLabels = ['must' => 'Must-haves', 'experience' => 'Experience', 'qualification' => 'Qualification', 'location' => 'Location', 'notice' => 'Notice period', 'ctc' => 'CTC'];
?>
<div class="page-head">
  <div><p class="eyebrow">Roles</p><h1><?= $editing ? 'Edit role' : 'New role' ?></h1></div>
</div>
<?php if (!empty($r['requested_at'])): $rqBy = \Eshcol\Ats\App::get()->db->col('SELECT name FROM client_contacts WHERE id = ?', [(int) ($r['requested_by_contact_id'] ?? 0)]); ?>
  <div class="card sand"><b>Asked for in the client portal</b><?= $rqBy ? ' by ' . e($rqBy) : '' ?> on <?= e(nice_date($r['requested_at'], true)) ?>.
    <?= !empty($r['request_note']) ? '<br><span class="small">Their note: ' . nl2br(e($r['request_note'])) . '</span>' : '' ?>
    <br><span class="small muted">It is a draft: check the details, add the must-haves and the field of work, then set the status to Open. The client sees it as "Requested" until then.</span></div>
<?php endif; ?>
<?php if ($errors): ?><div class="flash error"><?= icon('alert', 'sm') ?><div>Please check the fields outlined in red. Nothing has been saved yet. <?= $isPool ? '' : 'To save without them, set the status to <b>Draft</b>.' ?></div></div><?php endif; ?>
<form method="post" action="<?= $editing ? '/roles/' . (int) $r['id'] : '/roles' ?>" id="role-form" enctype="multipart/form-data" novalidate>
  <?= csrf() ?>
  <input type="hidden" name="jd_upload" id="jd_upload" value="<?= e($jdFile['token'] ?? '') ?>">
  <div class="split">
    <div>
      <?php if (!$isPool): ?>
      <label class="drop jd-drop" id="jd-drop" for="jd_file">
        <?= icon('file') ?>
        <b><?= $editing ? 'Replace the JD file' : 'Start from the JD file' ?></b>
        <span class="small muted" id="jd-msg"><?= $jdFile ? 'Attached: ' . e($jdFile['name']) . '. It is saved with the role.' : 'Drop the client\'s job description here (PDF or Word), or click to choose. We fill in the role from it; anything we can\'t find is outlined in red.' ?></span>
        <input type="file" id="jd_file" name="jd_file" accept=".pdf,.doc,.docx" class="visually-hidden">
      </label>
      <?php endif; ?>
      <div class="card">
        <p class="small muted" style="margin:0 0 10px"><?= $isPool ? '' : '<span class="req">*</span> needed to open the role. A <b>Draft</b> needs only the title and client.' ?></p>
        <?php if (!$isPool): $eng = \Eshcol\Ats\Services\Engagement::of($r); ?>
        <fieldset class="field" id="eng-fieldset">
          <legend>Who is this for?</legend>
          <?php foreach (\Eshcol\Ats\Services\Engagement::LONG as $ek => $el): ?>
            <label style="display:block;font-weight:normal;margin:2px 0"><input type="radio" name="engagement" value="<?= $ek ?>"<?= $eng === $ek ? ' checked' : '' ?>> <?= e($el) ?></label>
          <?php endforeach; ?>
          <p class="hint" data-eng-show="inhouse">Candidates see "Eshcol Global" from the first email. Only you, and anyone you give it to, can see this position and its salaries.</p>
          <p class="hint" data-eng-show="staffing">Candidates are told it is a contract role on Eshcol's payroll at the client's site, and asked whether they are open to it.</p>
        </fieldset>
        <?php endif; ?>
        <div class="row">
          <div class="field<?= $miss('title') ?>"><label for="title">Job title<?= $star ?></label><input type="text" id="title" name="title" value="<?= e($r['title'] ?? '') ?>" data-required data-always><?= $err('title') ?></div>
          <div class="field<?= $miss('client_id') ?>" data-eng-hide="inhouse"><label for="client_id">Client<?= $star ?></label>
            <select id="client_id" name="client_id" data-required data-always><option value="">Choose…</option>
              <?php foreach ($clients as $c): ?><option value="<?= $c['id'] ?>" data-city="<?= e($c['city'] ?? '') ?>"<?= selected($r['client_id'] ?? '', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?></div>
        </div>
        <div class="row">
          <div class="field<?= $miss('location') ?>"><label for="location">Location<?= $star ?></label><input type="text" id="location" name="location" value="<?= e($r['location'] ?? '') ?>" placeholder="e.g. Pune"<?= $req('location') ?>><?= $err('location') ?></div>
          <div class="field" style="flex:0 1 170px"><label for="work_mode">Work mode</label><select id="work_mode" name="work_mode">
            <option value=""<?= empty($r['work_mode']) ? ' selected' : '' ?>>Not set</option>
            <?php foreach (WORK_MODES as $wk => $wl): ?><option value="<?= $wk ?>"<?= ($r['work_mode'] ?? '') === $wk ? ' selected' : '' ?>><?= e($wl) ?></option><?php endforeach; ?>
          </select><span class="hint">Shown to candidates next to the title</span></div>
          <div class="field" style="flex:0 1 110px"><label for="positions">Positions</label><input type="number" min="1" id="positions" name="positions" value="<?= e($r['positions'] ?? 1) ?>"></div>
        </div>
        <?php if (!$isPool):
            $catSvc = new \Eshcol\Ats\Services\Categoriser(\Eshcol\Ats\App::get());
            $fnList = \Eshcol\Ats\Services\Categoriser::functions(\Eshcol\Ats\App::get());
            $ticked = !empty($r['function_codes']) ? explode(',', (string) $r['function_codes']) : (!empty($r['title']) ? $catSvc->roleFunctions($r) : []);
            $scopeSet = $r['location_scope'] ?? null;
            $suggested = \Eshcol\Ats\Services\FunctionRouting::defaultScope(isset($r['exp_min']) && $r['exp_min'] !== '' ? (float) $r['exp_min'] : null, isset($r['exp_max']) && $r['exp_max'] !== '' ? (float) $r['exp_max'] : null, $r['work_mode'] ?? null);
            $scope = $scopeSet ?: $suggested; ?>
        <fieldset class="field" id="who-fieldset">
          <legend>Who can we consider? <span class="hint">where the candidates live</span></legend>
          <?php foreach (\Eshcol\Ats\Services\FunctionRouting::SCOPES as $sk => $sl): ?>
            <label style="display:block;font-weight:normal;margin:2px 0"><input type="radio" name="location_scope" value="<?= $sk ?>"<?= $scope === $sk ? ' checked' : '' ?><?= $scopeSet ? ' data-touched="1"' : '' ?>> <?= e($sk === 'local' ? 'Local candidates only (the job\'s city, its localities and the other acceptable locations below)' : ($sk === 'open' ? 'Open to candidates from other cities' : 'Remote: anywhere')) ?></label>
          <?php endforeach; ?>
          <p class="hint" id="scope-hint">Suggested from the experience band: junior posts take local candidates only (a move rarely pays for itself), senior posts are open to other cities. Change it if the client will relocate someone. Someone from another city on a local-only post is kept for a future requirement, not added to its pipeline.</p>
        </fieldset>
        <fieldset class="field">
          <legend>Field of work <span class="hint">CVs in these fields are considered; tick a second one when the job straddles two</span></legend>
          <div style="display:flex;flex-wrap:wrap;gap:4px 14px">
            <?php foreach ($fnList as $code => $fn): ?>
              <label style="font-weight:normal;margin:0;white-space:nowrap"><input type="checkbox" name="functions[]" value="<?= e($code) ?>"<?= in_array($code, $ticked, true) ? ' checked' : '' ?>> <?= e($fn['name']) ?></label>
            <?php endforeach; ?>
          </div>
          <?php if (empty($r['function_codes']) && $ticked): ?><p class="hint">Ticked from the job title. Check it and save.</p><?php endif; ?>
        </fieldset>
        <?php endif; ?>
        <div class="field">
          <label for="jd_text">Job description <span class="hint">filled from the JD file, or paste it</span></label>
          <textarea id="jd_text" name="jd_text" rows="9"><?= e($r['jd_text'] ?? '') ?></textarea>
          <p class="actions" style="margin-top:8px"><button type="button" class="btn ghost small" id="suggest-btn">Suggest requirements from the JD</button><span class="small muted" id="suggest-msg"></span></p>
        </div>
      </div>

      <div class="card accent">
        <h2>Requirements</h2>
        <p class="small muted" style="margin-top:-4px">One per line. Synonyms are understood: "Tally" also finds TallyPrime and Tally ERP 9.</p>
        <div class="row">
          <div class="field<?= $miss('must_haves') ?>"><label for="must_haves">Must-haves<?= $star ?></label><textarea id="must_haves" name="must_haves" rows="6"<?= $req('must_haves') ?>><?= e($lines('must')) ?></textarea><?= $err('must_haves') ?></div>
          <div class="field"><label for="nice_to_haves">Nice-to-haves <span class="hint">break ties</span></label><textarea id="nice_to_haves" name="nice_to_haves" rows="6"><?= e($lines('nice')) ?></textarea></div>
        </div>
        <div class="row">
          <div class="field"><label for="qualifications">Qualifications <span class="hint">any one is enough</span></label><textarea id="qualifications" name="qualifications" rows="3"><?= e($lines('qualification')) ?></textarea></div>
          <div class="field"><label for="extra_locations">Other acceptable locations</label><textarea id="extra_locations" name="extra_locations" rows="3"><?= e($lines('location')) ?></textarea></div>
        </div>
        <div class="row">
          <div class="field<?= $miss('exp_min') ?>"><label for="exp_min">Experience from<?= $star ?> <span class="hint">years</span></label><input type="number" step="0.5" min="0" id="exp_min" name="exp_min" value="<?= e($r['exp_min'] ?? '') ?>"<?= $req('exp_min') ?>><?= $err('exp_min') ?></div>
          <div class="field<?= $miss('exp_max') ?>"><label for="exp_max">to<?= $star ?> <span class="hint">years</span></label><input type="number" step="0.5" min="0" id="exp_max" name="exp_max" value="<?= e($r['exp_max'] ?? '') ?>"<?= $req('exp_max') ?>><?= $err('exp_max') ?></div>
          <div class="field"><label for="exp_allow_below">Allow below <span class="hint">years</span></label><input type="number" step="0.5" min="0" max="20" id="exp_allow_below" name="exp_allow_below" value="<?= e(($r['exp_allow_below'] ?? 1) + 0) ?>"></div>
          <div class="field"><label for="exp_allow_above">Allow above <span class="hint">years</span></label><input type="number" step="0.5" min="0" max="20" id="exp_allow_above" name="exp_allow_above" value="<?= e(($r['exp_allow_above'] ?? 5) + 0) ?>"></div>
          <div class="field<?= $miss('ctc_min_lpa') ?>"><label for="ctc_min_lpa">CTC from <span class="hint">LPA</span></label><input type="text" inputmode="decimal" placeholder="e.g. 4.5" id="ctc_min_lpa" name="ctc_min_lpa" value="<?= e($r['ctc_min_lpa'] ?? '') ?>"><?= $err('ctc_min_lpa') ?></div>
          <div class="field<?= $miss('ctc_max_lpa') ?>"><label for="ctc_max_lpa">to (budget)<?= $star ?> <span class="hint">LPA</span></label><input type="text" inputmode="decimal" placeholder="e.g. 6.5" id="ctc_max_lpa" name="ctc_max_lpa" value="<?= e($r['ctc_max_lpa'] ?? '') ?>"<?= $req('ctc_max_lpa') ?>><?= $err('ctc_max_lpa') ?></div>
          <div class="field<?= $miss('notice_max_days') ?>"><label for="notice_max_days">Max notice<?= $star ?> <span class="hint">days</span></label><input type="number" min="0" id="notice_max_days" name="notice_max_days" value="<?= e($r['notice_max_days'] ?? '') ?>"<?= $req('notice_max_days') ?>><?= $err('notice_max_days') ?></div>
          <div class="field"><label for="interview_rounds">Interview rounds</label><input type="number" min="1" max="9" id="interview_rounds" name="interview_rounds" value="<?= e((string) ($r['interview_rounds'] ?? '')) ?>" placeholder="e.g. 2"></div>
          <div class="field"><label for="decision_maker">Who at the client decides</label><input type="text" maxlength="160" id="decision_maker" name="decision_maker" value="<?= e((string) ($r['decision_maker'] ?? '')) ?>" placeholder="e.g. Finance Head, Mr. Rao"></div>
        </div>
      </div>
    </div>

    <aside>
      <div class="card">
        <div class="field"><label for="status">Status</label>
          <select id="status" name="status">
            <?php foreach (['draft' => 'Draft', 'open' => 'Open', 'on_hold' => 'On hold', 'filled' => 'Filled', 'cancelled' => 'Cancelled'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= selected($r['status'] ?? 'open', $k) ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="status_reason">Reason <span class="hint">if on hold, filled or cancelled</span></label><input type="text" id="status_reason" name="status_reason" value="<?= e($r['status_reason'] ?? '') ?>"></div>
        <?php if (!$isPool): ?>
        <div class="field" data-eng-show="inhouse"><label for="hiring_manager_id">Hiring manager <span class="hint">who decides; they can see this position too</span></label>
          <select id="hiring_manager_id" name="hiring_manager_id"><option value="">You (the owner)</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"<?= selected($r['hiring_manager_id'] ?? '', $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" data-eng-show="staffing"><label for="contract_months">Contract length <span class="hint">months; told to candidates</span></label>
          <input type="number" min="1" max="60" id="contract_months" name="contract_months" value="<?= e($r['contract_months'] ?? '') ?>" placeholder="e.g. 12"></div>
        <div data-eng-show="staffing">
          <div class="row">
            <div class="field"><label for="work_kind">Scheduled employment <span class="hint">the client's industry, for the Tamil Nadu minimum wage</span></label><select id="work_kind" name="work_kind"><option value="">Choose…</option>
              <?php foreach (\Eshcol\Ats\Services\Staffing::WORK_KINDS as $wk => $wl): ?><option value="<?= $wk ?>"<?= selected($r['work_kind'] ?? '', $wk) ?>><?= e($wl) ?></option><?php endforeach; ?></select><?= $err('work_kind') ?></div>
            <div class="field"><label for="skill_level">Class of employee</label><select id="skill_level" name="skill_level"><option value="">Choose…</option>
              <?php foreach (\Eshcol\Ats\Services\Staffing::SKILLS as $sk => $sl): ?><option value="<?= $sk ?>"<?= selected($r['skill_level'] ?? '', $sk) ?>><?= e($sl) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="row">
            <div class="field"><label for="wage_zone">Minimum-wage zone <span class="hint">where the client's site is</span></label><select id="wage_zone" name="wage_zone"><option value="">Choose…</option>
              <?php foreach (\Eshcol\Ats\Services\Staffing::ZONES as $zk => $zl): ?><option value="<?= $zk ?>"<?= selected($r['wage_zone'] ?? '', $zk) ?>><?= e($zl) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="row">
            <div class="field"><label for="pay_min_month">Monthly gross from (₹)</label><input type="text" inputmode="decimal" id="pay_min_month" name="pay_min_month" value="<?= e($r['pay_min_month'] ?? '') ?>"></div>
            <div class="field"><label for="pay_max_month">to (₹)</label><input type="text" inputmode="decimal" id="pay_max_month" name="pay_max_month" value="<?= e($r['pay_max_month'] ?? '') ?>"></div>
            <div class="field"><label for="bill_rate_month">Bill rate ₹ <span class="hint">per person per month, only if billed a fixed rate</span></label><input type="text" inputmode="decimal" id="bill_rate_month" name="bill_rate_month" value="<?= e($r['bill_rate_month'] ?? '') ?>"></div>
          </div>
          <div class="row">
            <div class="field"><label for="terms_model">Fee for this position <span class="hint">the client's terms apply unless you set a different fee here</span></label>
              <select id="terms_model" name="terms_model"><option value="">Use the client's terms</option>
                <?php foreach (\Eshcol\Ats\Services\Staffing::BILLING as $bk => $bl): ?><option value="<?= $bk ?>"<?= selected($r['terms_model'] ?? '', $bk) ?>><?= e($bl) ?></option><?php endforeach; ?></select></div>
            <div class="field" style="flex:0 1 130px"><label for="terms_markup_pct">Fee %</label><input type="text" inputmode="decimal" id="terms_markup_pct" name="terms_markup_pct" value="<?= e($r['terms_markup_pct'] ?? '') ?>"></div>
            <div class="field" style="flex:0 1 160px"><label for="terms_fixed_fee">Fixed fee ₹ / month</label><input type="text" inputmode="decimal" id="terms_fixed_fee" name="terms_fixed_fee" value="<?= e($r['terms_fixed_fee'] ?? '') ?>"></div>
          </div>
        </div>
        <?php endif; ?>
        <div class="field"><label for="owner_user_id">Recruiter <span class="hint">whose position this is: it shows on their My day</span></label>
          <select id="owner_user_id" name="owner_user_id"><option value="">Unassigned</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"<?= selected($r['owner_user_id'] ?? '', $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label><input type="checkbox" name="accepts_routed" value="1"<?= ($r['accepts_routed'] ?? 1) ? ' checked' : '' ?>> Accept CVs sent for other roles</label>
          <p class="hint">When someone applies for a different role but their experience fits this one (and they match its must-haves), they're added here too, marked "routed".</p></div>
        <div class="row">
          <div class="field"><label for="opened_at">Opened</label><input type="date" id="opened_at" name="opened_at" value="<?= e($r['opened_at'] ?? '') ?>"></div>
          <div class="field"><label for="target_date">Target</label><input type="date" id="target_date" name="target_date" value="<?= e($r['target_date'] ?? '') ?>"></div>
        </div>
      </div>
      <details class="card sand">
        <summary><b>Scoring weights</b> <span class="small muted">optional</span></summary>
        <p class="small muted">How the 100 points split for this role.<?= $err('weights') ?></p>
        <?php foreach ($wLabels as $k => $label): ?>
          <div class="row" style="align-items:center;gap:10px"><label for="w_<?= $k ?>" style="flex:1;margin:0;font-weight:normal"><?= $label ?></label>
            <input type="number" min="0" max="100" id="w_<?= $k ?>" name="w_<?= $k ?>" value="<?= (int) ($weights[$k] ?? 0) ?>" style="width:80px;margin-bottom:6px"></div>
        <?php endforeach; ?>
        <div class="field" style="margin-top:10px"><label for="score_threshold">Shortlist threshold</label><input type="number" min="0" max="100" id="score_threshold" name="score_threshold" value="<?= (int) ($r['score_threshold'] ?? 50) ?>">
          <p class="hint">Candidates below this sit in a separate list you can still open. Nobody is rejected automatically.</p></div>
      </details>
      <p class="small err" id="form-msg" role="alert" hidden></p>
      <div class="actions"><button class="btn" type="submit"><?= $editing ? 'Save role' : 'Create role' ?></button><a href="<?= $editing ? '/roles/' . (int) $r['id'] : '/roles' ?>">Cancel</a></div>
    </aside>
  </div>
</form>
<script src="<?= asset('role-form.js') ?>"></script>
