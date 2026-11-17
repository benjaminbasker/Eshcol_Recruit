<div class="page-head"><div><p class="eyebrow">Owner only</p><h1>Settings</h1></div></div>

<?php if ($newPassword): ?>
  <div class="card sand">
    <h3>New account created</h3>
    <p>Give these to <b><?= e($newPassword['email']) ?></b> in person or by phone, not by email. This password is shown only once. On first sign-in they will set up their authenticator app, and they can change the password under <i>Your account</i>.</p>
    <p>Temporary password: <span class="secret"><?= e($newPassword['pass']) ?></span></p>
    <form method="post" action="/settings/users/done"><?= csrf() ?><button class="btn small" type="submit">I've noted it down</button></form>
  </div>
<?php endif; ?>

<p class="small" style="margin:-8px 0 16px"><a class="btn small ghost" href="/settings/staffing">Staffing: statutory rates, minimum wages, licence</a></p>

<div class="grid g2">
  <section class="card" id="stages">
    <h2>Pipeline stages</h2>
    <?php $missingCodes = (new \Eshcol\Ats\Services\Migrator(\Eshcol\Ats\App::get()))->pending() ? [] : \Eshcol\Ats\Services\Stages::missing(\Eshcol\Ats\App::get()); ?>
    <?php if ($missingCodes): // audit Q1 follow-up (17 Nov 2026): a stage the rules depend on has no fixed code ?>
      <div class="flash error" role="alert">The app can't tell which stage is <b><?= e(implode(', ', array_map(fn ($c) => ucfirst(str_replace('_', ' ', $c)), $missingCodes))) ?></b>
        (probably renamed before 16 Nov). Offer approval, joining records and reports rely on it. Tell your developer the stage's current name so it can be linked.</div>
    <?php endif; ?>
    <p class="small muted">Rename them in your own words. Order decides the dropdown order. A "success" stage counts as a placement; "fail" stages drop to the bottom of a role's list.</p>
    <form method="post" action="/settings/stages"><?= csrf() ?>
      <table class="list" style="font-size:14px">
        <thead><tr><th>Name</th><th>Order</th><th>Type</th><th>On</th></tr></thead>
        <tbody><?php foreach ($stages as $s): ?>
          <tr>
            <td><input type="text" name="stage[<?= $s['id'] ?>][name]" value="<?= e($s['name']) ?>" aria-label="Stage name"><?= $s['used'] ? '<span class="small muted">' . (int) $s['used'] . ' in use</span>' : '' ?></td>
            <td style="width:80px"><input type="number" name="stage[<?= $s['id'] ?>][sort]" value="<?= (int) $s['sort'] ?>" aria-label="Order"></td>
            <td style="width:120px"><select name="stage[<?= $s['id'] ?>][kind]" aria-label="Type">
              <?php foreach (['active' => 'In progress', 'success' => 'Success', 'fail' => 'Fail', 'hold' => 'Kept for later'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($s['kind'], $k) ?>><?= $v ?></option><?php endforeach; ?></select></td>
            <td style="width:44px"><input type="checkbox" name="stage[<?= $s['id'] ?>][is_active]" value="1"<?= $s['is_active'] ? ' checked' : '' ?> aria-label="Active"></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table>
      <div class="field" style="margin-top:12px"><label for="new_stage">Add a stage</label><input type="text" id="new_stage" name="new_stage" placeholder="e.g. Second client interview"></div>
      <button class="btn" type="submit">Save stages</button>
    </form>
  </section>

  <section class="card" id="users">
    <h2>People</h2>
    <table class="list" style="font-size:14px">
      <thead><tr><th>Name</th><th>Role</th><th>Two-step</th><th>Last sign-in</th></tr></thead>
      <tbody><?php $roleAccess = $roleAccess ?? []; $assignable = $assignable ?? []; $byId = array_column($assignable, null, 'id');
        foreach ($users as $u): $isMe = (int) $u['id'] === (int) ($user['id'] ?? 0); ?>
        <tr<?= $u['is_active'] ? '' : ' style="opacity:.55"' ?>><td><?= e($u['name']) ?><?= $u['is_active'] ? '' : ' <span class="chip">switched off</span>' ?><br><span class="small muted"><?= e($u['email']) ?></span>
            <?php if ($u['role'] === 'contractor'): $mine = $roleAccess[(int) $u['id']] ?? []; ?>
              <br><span class="small"><b>Positions:</b> <?= $mine ? e(implode(', ', array_map(fn ($rid) => isset($byId[$rid]) ? $byId[$rid]['title'] . ' (' . $byId[$rid]['client'] . ')' : '#' . $rid, $mine))) : '<span style="color:var(--red)">none: they see nothing</span>' ?></span>
              <details class="small"><summary>Change their positions</summary>
                <form method="post" action="/settings/users/<?= (int) $u['id'] ?>/positions" style="margin:6px 0"><?= csrf() ?>
                  <?php foreach ($assignable as $r): ?><label style="display:block;font-weight:normal"><input type="checkbox" name="positions[]" value="<?= (int) $r['id'] ?>"<?= in_array((int) $r['id'], $mine, true) ? ' checked' : '' ?>> <?= e($r['title']) ?> <span class="muted">· <?= e($r['client']) ?> · <?= e(str_replace('_', ' ', $r['status'])) ?></span></label><?php endforeach; ?>
                  <button class="btn ghost small" type="submit" style="margin-top:6px">Save positions</button>
                </form></details>
            <?php endif; ?></td>
          <td><?= e(ucfirst($u['role'])) ?></td>
          <td><?= $u['totp_enabled'] ? '<span class="badge open">on</span>' : '<span class="badge on_hold">not yet</span>' ?></td>
          <td class="small"><?= e(nice_date($u['last_login_at'], true)) ?>
            <?php if (!$isMe): ?><form method="post" action="/settings/users/<?= (int) $u['id'] ?>/active" style="margin:4px 0 0"><?= csrf() ?><input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
              <button class="btn link small" type="submit"<?= $u['is_active'] ? ' data-confirm-click="Switch off ' . e($u['name']) . '\'s account? They can no longer sign in; their history stays."' : '' ?>><?= $u['is_active'] ? 'Switch off' : 'Switch on again' ?></button></form><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
    <form method="post" action="/settings/users" style="margin-top:16px"><?= csrf() ?>
      <h3>Add a person</h3>
      <div class="row">
        <div class="field"><label for="u_name">Name</label><input type="text" id="u_name" name="name" required></div>
        <div class="field"><label for="u_email">Email</label><input type="email" id="u_email" name="email" required></div>
        <div class="field" style="flex:0 1 150px"><label for="u_role">Access</label><select id="u_role" name="role"><option value="recruiter">Recruiter</option><option value="contractor">Contractor</option><option value="owner">Owner</option></select></div>
      </div>
      <?php if ($assignable): ?>
        <fieldset class="field"><legend>For a contractor: the position(s) they work on</legend>
          <?php foreach ($assignable as $r): ?><label style="display:block;font-weight:normal"><input type="checkbox" name="positions[]" value="<?= (int) $r['id'] ?>"> <?= e($r['title']) ?> <span class="muted small">· <?= e($r['client']) ?> · <?= e(str_replace('_', ' ', $r['status'])) ?></span></label><?php endforeach; ?>
        </fieldset>
      <?php endif; ?>
      <button class="btn" type="submit">Create account</button>
      <p class="hint"><b>Recruiters</b> can do everything except Settings. <b>Owners</b> can also manage stages, the dictionary and people.
        <b>Contractors</b> see only the positions you tick, and the candidates on them: they can move stages, add notes, arrange interviews, upload CVs to their positions and send shortlists, but never see your database, clients, other positions, emails, messages or Settings.</p>
    </form>
  </section>
</div>

<div class="grid g2">
  <section class="card" id="retention">
    <h2>Keeping candidate data</h2>
    <p class="small">Candidates are kept for <b><?= (int) $retentionMonths ?> months after their last contact</b> with you: applying, sending a new CV, or having their details confirmed. Anyone in an active stage on an open role is never due. Nothing is deleted without your say-so.</p>
    <form method="post" action="/settings/retention" class="row" style="align-items:flex-end"><?= csrf() ?>
      <div class="field" style="flex:0 1 160px;margin:0"><label for="months">Months</label><input type="number" id="months" name="months" min="6" max="120" value="<?= (int) $retentionMonths ?>"></div>
      <div><button class="btn ghost" type="submit">Save</button></div>
    </form>
    <p class="hint" style="margin-top:8px">Your privacy policy and the acknowledgement email both promise <?= (int) $retentionMonths ?> months. If you change this, change them too, so what you say and what you do match.</p>

    <?php if (!empty($consent)): ?>
      <h3 style="margin-top:18px">Consent to keep their details</h3>
      <p class="small" style="margin:0 0 4px">Where each person stands, from the thank-you email (told, with "Please remove me" to opt out), the details form's "Keep my details on file", and removal requests:</p>
      <p class="small" style="margin:0"><?php foreach (['given' => 'agreed', 'notice_sent' => 'told, no answer yet', 'withdrawn' => 'withdrawn', 'none' => 'nothing recorded'] as $k => $l): ?><span class="chip<?= $k === 'none' ? ' flag' : '' ?>"><?= (int) ($consent[$k] ?? 0) ?> <?= e($l) ?></span><?php endforeach; ?></p>
      <p class="hint" style="margin-top:6px">"Nothing recorded" covers CVs added before 14 November 2026 and old CVs uploaded from your own files. Under the DPDP Act they should be told before they are contacted for another role: the "send your latest CV" email will do that. The candidate page has "Download their data" for anyone who asks what you hold.</p>
    <?php endif; ?>

    <h3 style="margin-top:18px">Due for deletion (<?= count($due) ?>)</h3>
    <?php if (!$due): ?>
      <p class="muted small">Nobody is due.</p>
    <?php else: ?>
      <form method="post" action="/settings/retention/action"><?= csrf() ?>
        <div class="table-wrap" style="max-height:300px;overflow:auto"><table class="list" style="font-size:14px">
          <thead><tr><th><input type="checkbox" checked data-all aria-label="Select all"></th><th>Candidate</th><th>Last contact</th><th>Due</th></tr></thead>
          <tbody><?php foreach ($due as $d): ?>
            <tr><td><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" checked aria-label="Select"></td>
              <td><a href="/candidates/<?= (int) $d['id'] ?>"><?= e($d['full_name'] ?: 'Name not found') ?></a></td>
              <td class="small"><?= e(nice_date($d['last_activity_at'] ?? $d['created_at'])) ?></td>
              <td class="small"><?= e(nice_date($d['purge_after'])) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <div class="row" style="margin-top:12px;align-items:flex-end">
          <div class="field" style="margin:0"><label for="keep-reason">Reason, if keeping</label><input type="text" id="keep-reason" name="reason" placeholder="e.g. They asked to stay on file"></div>
          <div class="actions"><button class="btn ghost" type="submit" name="do" value="extend">Keep selected</button>
            <button class="btn" type="submit" name="do" value="delete" style="background:var(--red);border-color:var(--red)" data-confirm-click="Delete the selected candidates and their CV files permanently?">Delete selected</button></div>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card" id="system">
    <h2>System</h2>
    <p class="small"><b>App updates:</b> <?= $pending ? count($pending) . ' waiting (' . e(implode(', ', $pending)) . ')' : 'up to date' ?></p>
    <?php if ($pending): ?><form method="post" action="/settings/migrate"><?= csrf() ?><button class="btn small" type="submit">Apply updates</button> <span class="small muted">a copy of the database is saved first</span><?php if (!empty($_SESSION['offer_without_copy'])): ?> <button class="btn ghost small" type="submit" name="without_copy" value="1" data-confirm-click="Apply the updates without a fresh copy of the database? Only if the nightly backup is recent.">Apply without a copy</button><?php endif; ?></form><?php endif; ?>
    <p class="small"><b>Scheduled jobs:</b> <?= $cron ? 'last ran ' . e(nice_date($cron, true)) : '<span style="color:var(--red)">have never run. Set up the cron job (see the deployment guide).</span>' ?></p>
    <?php if (!empty($jobs)): $bad = array_filter($jobs['tasks'], fn ($t) => $t['failing'] >= 1); ?>
      <details id="jobs"<?= $bad ? ' open' : '' ?> style="margin:-4px 0 12px"><summary class="small"><b>Each job, and what is waiting</b><?= $bad ? ' <span class="chip no">' . count($bad) . ' failing</span>' : ' <span class="chip yes">all running</span>' ?></summary>
        <div class="table-wrap" style="margin-top:8px"><table class="list compact">
          <thead><tr><th>Job</th><th>Last ran</th><th class="num">Took</th><th>Result</th></tr></thead>
          <tbody><?php foreach ($jobs['tasks'] as $t): $l = $t['last']; ?>
            <tr><td><?= e($t['name']) ?><br><span class="small muted"><?= (int) $t['runs_24h'] ?> runs in 24 h<?= $t['slowest_24h'] > 30 ? ', slowest ' . round($t['slowest_24h']) . ' s' : '' ?></span></td>
              <td class="small"><?= e(nice_date($l['started_at'], true)) ?></td><td class="num small"><?= e(number_format((float) $l['seconds'], 1)) ?> s</td>
              <td class="small"><?php if ($t['failing']): ?><span style="color:var(--red)"><b>Failed<?= $t['failing'] > 1 ? ' ' . $t['failing'] . ' times in a row' : '' ?>:</b> <?= e((string) $l['message']) ?></span>
                <?php else: ?><?= e($l['message'] ? mb_strimwidth((string) $l['message'], 0, 120, '…') : 'nothing to do') ?><?= $t['last_fail'] ? '<br><span class="muted">last failure ' . e(nice_date($t['last_fail']['started_at'], true)) . '</span>' : '' ?><?php endif; ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
        <p class="small" style="margin:10px 0 0"><?php foreach ($jobs['queues'] as $label => $n): ?><span class="chip<?= $n ? '' : ' muted' ?>"><?= e($label) ?>: <?= (int) $n ?></span><?php endforeach; ?></p>
        <?php if (!$jobs['tasks']): ?><p class="small muted">Nothing recorded yet: each run is listed here from the next five-minute run.</p><?php endif; ?>
      </details>
    <?php endif; ?>
    <p class="small"><b>Last backup:</b> <?= $backupLast ? e(nice_date($backupLast, true)) : 'none yet' ?> · <b>Off-site copy:</b> <?= $backupRemote ? 'on (Cloudflare R2' . ($offsite ? ', bucket ' . e($offsite['bucket']) : '') . ')' . ' · ' . $cvSynced . ' of ' . $cvTotal . ' CV files copied, plus ' . (int) ($filesSynced ?? 0) . ' job description, profile and logo files' : '<span style="color:var(--warn)">not set up yet, so backups stay on the server only</span>' ?></p>
    <form method="post" action="/settings/backup-now" style="margin:0 0 12px"><?= csrf() ?><button class="btn ghost small" type="submit">Back up now</button> <span class="hint">Normally runs by itself at 2 am.</span></form>
    <form method="post" action="/settings/reread" style="margin:0 0 12px"><?= csrf() ?><button class="btn ghost small" type="submit" data-confirm-click="Re-read every CV now? Only empty details are filled in; nothing already entered is changed. It can take a minute.">Re-read all CVs</button> <span class="hint">After the reader improves (new job titles, places, name rules), fills in what was missing for candidates already here. Never overwrites anything.</span></form>
    <details<?= $backupRemote ? '' : ' open' ?>>
      <summary class="small"><b><?= $offsite ? 'Change the off-site backup details' : 'Set up the off-site backup (Cloudflare R2)' ?></b></summary>
      <form method="post" action="/settings/offsite" autocomplete="off" style="margin-top:10px"><?= csrf() ?>
        <div class="field"><label for="r2acct">Account ID <span class="hint">or the whole S3 endpoint address</span></label><input type="text" id="r2acct" name="account_id" value="<?= e($offsite['account_id'] ?? '') ?>" placeholder="32 letters and numbers"></div>
        <div class="field"><label for="r2bucket">Bucket name</label><input type="text" id="r2bucket" name="bucket" value="<?= e($offsite['bucket'] ?? 'eshcol-recruit-backups') ?>"></div>
        <div class="field"><label for="r2access">Access Key ID</label><input type="text" id="r2access" name="access_key" value="" placeholder="<?= e($offsite['access_key'] ?? '') ?>" autocomplete="off"></div>
        <div class="field"><label for="r2secret">Secret Access Key <span class="hint"><?= $offsite ? 'saved, encrypted; leave blank to keep it' : '' ?></span></label><input type="password" id="r2secret" name="secret_key" value="" autocomplete="new-password"></div>
        <div class="actions"><button class="btn small" type="submit">Test and save</button>
          <?php if ($offsite): ?><button class="btn ghost small" type="submit" name="forget" value="1" data-confirm-click="Remove the off-site backup details? Backups will stay on the server only.">Remove</button><?php endif; ?></div>
        <p class="hint" style="margin-top:8px">Nothing is saved unless a test file can be written to the bucket and removed again. Backups are encrypted before they leave the server, so Cloudflare can't read them.</p>
      </form>
    </details>
  </section>
</div>

<section class="card" id="routing">
  <h2>Sending CVs to the right role</h2>
  <form method="post" action="/settings/routing"><?= csrf() ?>
    <p><label style="font-weight:normal"><input type="checkbox" name="routing_enabled" value="1"<?= $routingEnabled ? ' checked' : '' ?>>
      <b>Route by experience and field.</b> When someone applies for a role their experience doesn't fit (say 10 years for a 2–5 year post), or one in a different field, also add them to any open role they do fit: its band first, its allowance if no band fits. They must match at least half its must-haves and reach its threshold. They always stay on the role they applied for, with a note.</label></p>
    <p><label style="font-weight:normal"><input type="checkbox" name="auto_sort_unassigned" value="1"<?= $autoSort ? ' checked' : '' ?>>
      <b>Sort CVs that arrive with no role</b> into every open role they fit, by the same rules.</label></p>
    <p><label style="font-weight:normal"><input type="checkbox" name="talent_pools_enabled" value="1"<?= $pools ? ' checked' : '' ?>>
      <b>Talent pools by position.</b> A CV from a different field (say HR, sent for an accounts role), or outside a role's allowance, that fits no open role is filed in a pool named after the candidate's own position, such as <i>HR Executive</i> or <i>HR Manager</i>. Pools are made the first time they're needed and are listed under Roles › Talent pools.</label></p>
    <div class="row" style="align-items:flex-end">
      <div class="field" style="flex:0 1 190px"><label for="allow_below">Default allowance below <span class="hint">years</span></label><input type="number" id="allow_below" name="exp_allow_below_default" min="0" max="20" step="0.5" value="<?= e($allowBelow) ?>"></div>
      <div class="field" style="flex:0 1 190px"><label for="allow_above">Default allowance above <span class="hint">years</span></label><input type="number" id="allow_above" name="exp_allow_above_default" min="0" max="20" step="0.5" value="<?= e($allowAbove) ?>"></div>
    </div>
    <p class="hint" style="margin-top:-6px">A 2–5 year role with the defaults (1 below, 5 above) takes 1–10 years: 2–5 is a fit, the rest is flagged as a stretch. New roles start with these figures; change them on any role.</p>
    <p class="small muted">Levels used in search: <?php foreach (level_bands() as [$n, $f, $t]): ?><span class="chip"><?= e($n) ?>: <?= round($f / 12, 1) + 0 ?><?= $t >= 9999 ? '+' : '–' . (round($t / 12, 1) + 0) ?> yrs</span><?php endforeach; ?></p>
    <button class="btn ghost small" type="submit">Save</button>
  </form>
  <?php $catMode = \Eshcol\Ats\Services\Categoriser::mode(\Eshcol\Ats\App::get()); ?>
  <p class="small" style="margin:14px 0 0"><a href="/settings/categories"><b>Categories</b></a> (<?= e(\Eshcol\Ats\Services\Categoriser::MODES[$catMode]) ?>): every CV filed by the person's field of work, seniority and where they'd work; positions take CVs by field, experience and location<?= $catMode === 'on' ? ' (switched on: this replaces the must-have words above)' : '' ?>.</p>
</section>

<section class="card" id="inbox">
  <div class="card-head"><h2><?= icon('inbox') ?> CV inbox (resume@)</h2>
    <?php if ($inbox['cfg']): ?><span class="badge <?= $inbox['on'] ? 'open' : 'on_hold' ?>"><?= $inbox['on'] ? 'On' : 'Paused' ?></span><?php endif; ?></div>
  <p class="small muted" style="margin-top:0">CVs emailed to this mailbox come in by themselves every few minutes: read, scored, sent to every open role they fit, and thanked. Put the role code in the job posting's subject line (Post job does this) and the CV lands on that role. Nothing in the mailbox is moved or deleted; handled emails get the Outlook category "<?= e(\Eshcol\Ats\Services\MailIntake::CATEGORY) ?>".</p>
  <?php if ($inbox['cfg']): ?>
    <p class="small"><b>Mailbox:</b> <?= e($inbox['cfg']['mailbox']) ?> · <b>Last checked:</b> <?= $inbox['lastOk'] ? e(nice_date($inbox['lastOk'], true)) : 'not yet' ?> · <b>CVs in today:</b> <?= $inbox['today'] ?></p>
    <?php if ($inbox['lastError']): ?><div class="flash error"><?= icon('alert', 'sm') ?><div><?= e($inbox['lastError']) ?></div></div><?php endif; ?>
    <form method="post" action="/settings/inbox/action" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px"><?= csrf() ?>
      <button class="btn small" name="do" value="check"><?= icon('mail', 'sm') ?>Check now</button>
      <?php if ($inbox['on']): ?><button class="btn ghost small" name="do" value="pause">Pause</button><?php else: ?><button class="btn ghost small" name="do" value="resume">Switch on</button><?php endif; ?>
      <button class="btn ghost small" name="do" value="remove" data-confirm-click="Disconnect the CV inbox? Emails will stay in the mailbox.">Disconnect</button>
    </form>
    <?php if ($inbox['recent']): ?>
      <div style="overflow-x:auto"><table class="list small">
        <thead><tr><th>Received</th><th>From</th><th>Subject</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($inbox['recent'] as $m): ?>
          <tr>
            <td class="nowrap"><?= $m['received_at'] ? e(nice_date($m['received_at'], true)) : '' ?></td>
            <td><?= e($m['from_name'] ?: $m['from_email']) ?></td>
            <td><?= e(mb_strimwidth((string) $m['subject'], 0, 60, '…')) ?><?= $m['role_title'] ? '<br><span class="muted">→ ' . e($m['role_title']) . '</span>' : '' ?></td>
            <td title="<?= e((string) $m['detail']) ?>">
              <?php if ($m['outcome'] === 'imported'): foreach (array_filter(explode(',', (string) $m['candidate_ids'])) as $cid): ?><a href="/candidates/<?= (int) $cid ?>" class="chip"><?= e(ref_candidate($cid)) ?></a> <?php endforeach;
                elseif ($m['outcome'] === 'message'): ?><a href="/candidates/<?= (int) $m['candidate_ids'] ?>#messages" class="chip"><?= e(ref_candidate((int) $m['candidate_ids'])) ?></a> <span class="muted">message from the candidate</span>
              <?php elseif ($m['outcome'] === 'no_cv'): ?><span class="muted">No CV attached</span>
              <?php else: ?><span style="color:var(--red)">Could not read the CV</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  <?php endif; ?>
  <details<?= $inbox['cfg'] ? '' : ' open' ?> style="margin-top:12px">
    <summary class="small"><b><?= $inbox['cfg'] ? 'Change the connection details' : 'Connect resume@ (Microsoft 365)' ?></b></summary>
    <form method="post" action="/settings/inbox" autocomplete="off" style="margin-top:10px"><?= csrf() ?>
      <div class="row">
        <div class="field"><label for="mi_tenant">Directory (tenant) ID</label><input type="text" id="mi_tenant" name="tenant_id" value="<?= e($inbox['cfg']['tenant_id'] ?? '') ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
        <div class="field"><label for="mi_client">Application (client) ID</label><input type="text" id="mi_client" name="client_id" value="<?= e($inbox['cfg']['client_id'] ?? '') ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
      </div>
      <div class="row">
        <div class="field"><label for="mi_secret">Client secret value <span class="hint"><?= $inbox['cfg'] ? 'saved, encrypted; leave blank to keep it' : 'the Value column, not the Secret ID' ?></span></label><input type="password" id="mi_secret" name="client_secret" value="" autocomplete="new-password"></div>
        <div class="field"><label for="mi_box">Mailbox</label><input type="email" id="mi_box" name="mailbox" value="<?= e($inbox['cfg']['mailbox'] ?? 'resume@eshcolglobal.com') ?>"></div>
      </div>
      <div class="row">
        <div class="field"><label for="mi_from">Bring in emails from <span class="hint"><?= $inbox['cfg'] ? 'leave blank to carry on where it is' : 'blank = the last 7 days' ?></span></label><input type="date" id="mi_from" name="start_from" value=""></div>
        <div class="field"><label style="font-weight:normal;margin-top:24px"><input type="checkbox" name="ack" value="1"<?= $inbox['ack'] ? ' checked' : '' ?>> Send the usual thank-you email to people whose CV comes in this way</label></div>
      </div>
      <button class="btn" type="submit">Save and test</button>
      <p class="hint" style="margin-top:8px">Saving signs in to Microsoft and reads the Inbox once, so you know it works before anything is relied on.</p>
    </form>
  </details>
</section>

<section class="card" id="ai">
  <div class="card-head"><h2><?= icon('sparkles') ?> AI analysis (Claude)</h2>
    <?php if ($ai['hasKey']): ?><span class="badge <?= $ai['on'] ? 'open' : 'on_hold' ?>"><?= $ai['on'] ? 'On' : 'Off' ?></span><?php endif; ?></div>
  <p class="small muted" style="margin-top:0">Adds an <b>Analyse with AI</b> button to each candidate's CV report. Only when you click it, Claude (Sonnet 5.5) reads that CV's text and your open roles and writes a recruiter's note: about ₹2–4 per CV. The free summary never needs this.</p>
  <?php if ($ai['hasKey']): ?>
    <p class="small"><b>This month:</b> <?= (int) $ai['used'] ?> of <?= (int) $ai['limit'] ?> analyses · about US$<?= number_format($ai['costMonth'], 2) ?> (₹<?= number_format($ai['costMonth'] * INR_PER_USD) ?>)</p>
  <?php endif; ?>
  <form method="post" action="/settings/ai" autocomplete="off"><?= csrf() ?>
    <div class="row">
      <div class="field"><label for="ai_key">Claude API key <span class="hint"><?= $ai['hasKey'] ? 'saved, encrypted; leave blank to keep it' : 'from platform.claude.com › API keys; starts with sk-ant-' ?></span></label><input type="password" id="ai_key" name="api_key" value="" autocomplete="new-password"></div>
      <div class="field" style="max-width:220px"><label for="ai_limit">Most analyses per month</label><input type="number" id="ai_limit" name="limit" min="0" max="5000" value="<?= (int) $ai['limit'] ?>"></div>
    </div>
    <p><label style="font-weight:normal"><input type="checkbox" name="enabled" value="1"<?= $ai['on'] ? ' checked' : '' ?>> Show the "Analyse with AI" button</label></p>
    <div class="actions"><button class="btn" type="submit">Save</button>
      <?php if ($ai['hasKey']): ?><button class="btn ghost small" type="submit" name="forget" value="1" data-confirm-click="Remove the Claude API key?">Remove key</button><?php endif; ?></div>
    <p class="hint" style="margin-top:8px">Also set a monthly spending limit in the Claude Console (Settings › Limits), so the bill can never surprise you.</p>
  </form>
  <p class="small" style="margin:14px 0 0"><a href="/settings/ai-reading"><b>AI reading</b></a>: Claude reads every CV (new ones automatically; re-read the ones already here and approve the changes).<br>
    <a href="/settings/reader-test">Reader test</a>: compare the free reader, Claude Haiku and Claude Sonnet on the same CVs.</p>
</section>
<section class="card" id="email">
  <h2>Email</h2>
  <p class="small">Status: <?= $mail['reallyLive'] ? '<span class="badge open">live</span> emails go out through Brevo.' : '<span class="badge on_hold">test mode</span> emails are written to the server and listed under Emails, but not sent.' ?>
    <?= $mail['local'] ? ' On your own PC the app is always in test mode.' : '' ?>
    <?= $mail['lastOk'] ? ' Last accepted by Brevo: ' . e(nice_date($mail['lastOk'], true)) . '.' : '' ?></p>
  <?php if ($mail['problem']): ?><div class="flash error" style="margin:0 0 14px"><b>Paused since <?= e(nice_date($mail['problem'][0], true)) ?>:</b> Brevo refused the API key: <?= e($mail['problem'][1]) ?>. Paste a new key below; if Brevo mentions an IP address, add 162.0.217.16 under Brevo › Security › Authorized IPs. Emails wait meanwhile; none are lost.</div><?php endif; ?>
  <form method="post" action="/settings/email" autocomplete="off"><?= csrf() ?>
    <p><label style="font-weight:normal"><input type="checkbox" name="ack_enabled" value="1"<?= $mail['ack'] ? ' checked' : '' ?>>
      <b>Thank every applicant.</b> Offered on each upload (you can untick it per batch). One email per candidate per role; doubtful addresses wait for you under Emails.</label></p>
    <p><label style="font-weight:normal"><input type="checkbox" name="ack_with_questions" value="1"<?= $mail['ackQuestions'] ? ' checked' : '' ?>>
      <b>Put the screening questions in the thank-you email</b>, as a "one quick step" button, saying the answers are critical to a decision on their candidature. Anyone who hasn't answered after 24 hours gets one follow-up.</label></p>
    <p><label style="font-weight:normal"><input type="checkbox" name="digest_enabled" value="1"<?= $mail['digest'] ? ' checked' : '' ?>>
      <b>Monday summary</b> to the owners: the week in numbers, what waits for you, and for each open role the best people already in your database.</label></p>
    <div class="row">
      <div class="field"><label for="mail_from_name">Sent as</label><input type="text" id="mail_from_name" name="mail_from_name" value="<?= e($mail['fromName']) ?>"></div>
      <div class="field"><label for="mail_from_email">From and reply-to address</label><input type="email" id="mail_from_email" name="mail_from_email" value="<?= e($mail['fromEmail']) ?>" required></div>
      <div class="field" style="flex:0 1 150px"><label for="mail_daily_cap">Daily ceiling</label><input type="number" id="mail_daily_cap" name="mail_daily_cap" min="1" max="5000" value="<?= e($mail['cap']) ?>"></div>
    </div>
    <p class="hint" style="margin-top:-8px">Brevo's free plan allows 300 a day and your website forms share it, so 250 leaves the website room. Raise it if you move to a paid Brevo plan.</p>
    <div class="field"><label for="mail_own_addresses">Your own forwarding addresses <span class="hint">never thanked; comma-separated</span></label>
      <input type="text" id="mail_own_addresses" name="mail_own_addresses" value="<?= e($mail['own']) ?>" placeholder="e.g. your Yahoo and Gmail addresses that forward Indeed mail"></div>
    <div class="field"><label for="brevo_api_key">Brevo API key <span class="hint"><?= $mail['hasKey'] ? 'saved (stored encrypted); leave blank to keep it' : 'not added yet' ?></span></label>
      <input type="password" id="brevo_api_key" name="brevo_api_key" value="" placeholder="xkeysib-…" autocomplete="new-password"></div>
    <p class="hint" style="margin-top:-8px">Create one in Brevo under SMTP &amp; API › API keys, named "Eshcol Recruit", so it can be switched off on its own without touching the website's key.</p>
    <p><label style="font-weight:normal"><input type="checkbox" name="mail_live" value="1"<?= $mail['live'] ? ' checked' : '' ?><?= $mail['local'] ? ' disabled' : '' ?>>
      <b>Send for real.</b> Leave this off until a test email to yourself looks right.</label></p>
    <button class="btn ghost small" type="submit">Save</button>
  </form>
  <form method="post" action="/settings/email/test" style="margin-top:12px" class="actions"><?= csrf() ?>
    <button class="btn small" type="submit">Send a test thank-you email to me</button>
    <button class="btn ghost small" type="submit" formaction="/settings/email/digest">Send me this week's summary now</button>
  </form>
  <?php if (!empty($emailHealth)): $eh = $emailHealth; $ev = $eh['events']; ?>
    <h3 style="margin:18px 0 4px" id="bounces">Bounces and complaints</h3>
    <p class="small" style="margin-top:0">Brevo tells the app when an email bounces, is blocked, is marked as spam or unsubscribed, and the app stops emailing that address.
      A bounce rate above about 5% puts Brevo's sending at risk, so this matters before emailing old applicants.</p>
    <?php if ($eh['connected']): ?>
      <p class="small"><span class="badge open">connected</span> since <?= e(nice_date($eh['connected'], true)) ?><?= $eh['last_event'] ? ' · last event ' . e(nice_date($eh['last_event'], true)) : ' · no events yet' ?>.</p>
    <?php else: ?>
      <form method="post" action="/settings/email/brevo-hook" style="margin:0 0 8px"><?= csrf() ?><button class="btn small" type="submit">Connect Brevo</button>
        <span class="hint">Asks Brevo (with the key above) to report bounces to this app. One click, once.</span></form>
    <?php endif; ?>
    <p class="small" style="margin:0">Last 30 days: <?= (int) $eh['sent'] ?> sent · <?= (int) ($ev['delivered'] ?? 0) ?> delivered · <?= (int) ($ev['hard_bounce'] ?? 0) ?> bounced for good · <?= (int) ($ev['soft_bounce'] ?? 0) ?> bounced once ·
      <?= (int) ($ev['blocked'] ?? 0) ?> blocked · <?= (int) ($ev['complained'] ?? 0) ?> spam complaints · <?= (int) ($ev['unsubscribed'] ?? 0) ?> unsubscribed<?= $eh['bounce_rate'] !== null ? ' · <b>bounce rate ' . e($eh['bounce_rate']) . '%</b>' : '' ?>.
      <?= (int) $eh['stopped'] ?> address<?= (int) $eh['stopped'] === 1 ? ' is' : 'es are' ?> no longer emailed.</p>
    <details style="margin-top:6px"><summary class="small">Connect by hand instead</summary><p class="small" style="margin:6px 0 0">In Brevo › Settings › Webhooks › Transactional, add this address with the events hard bounce, soft bounce, blocked, spam, invalid, unsubscribed and delivered:<br><code style="word-break:break-all"><?= e($eh['url']) ?></code><br>Keep it private: it is the key to this webhook.</p></details>
  <?php endif; ?>
</section>

<section class="card" id="dictionary">
  <h2>Skills dictionary</h2>
  <p class="small muted">How the app recognises skills, qualifications and places in CVs: <?= array_sum($areas) ?> skills and qualifications in <?= count($areas) ?> areas, plus places and job titles. Each line is one name with every way people write it. When the app misses something, add it here.</p>
  <p class="small" style="margin-top:-4px"><a class="btn small<?= $newWords ? '' : ' ghost' ?>" href="/settings/new-words">New words from CVs<?= $newWords ? ' (' . (int) $newWords . ')' : '' ?></a>
    <span class="muted">Job titles and software Claude found in CVs that the dictionary doesn't know yet: add them, or ignore.</span></p>
  <?php if ($onet['titles'] || $onet['software']): ?>
    <p class="small muted">Also <?= number_format($onet['titles']) ?> job titles and <?= number_format($onet['software']) ?> software names from O*NET, for engineering, plant and construction, HR, admin, procurement, quality, IT, finance and sales. They show when you search. <span title="Includes information from the O*NET 30.3 Database by the U.S. Department of Labor, Employment and Training Administration (USDOL/ETA). Used under the CC BY 4.0 licence. O*NET® is a trademark of USDOL/ETA. Eshcol has selected and modified part of this information; USDOL/ETA has not approved, endorsed or tested these modifications.">Credit: O*NET 30.3 Database, USDOL/ETA, CC BY 4.0.</span></p>
  <?php endif; ?>
  <form method="post" action="/settings/skills" class="row" style="align-items:flex-end"><?= csrf() ?>
    <div class="field"><label for="canonical">Name to show</label><input type="text" id="canonical" name="canonical" placeholder="e.g. Tally" required></div>
    <div class="field" style="flex:2"><label for="terms">Ways it's written <span class="hint">comma-separated</span></label><input type="text" id="terms" name="terms" placeholder="e.g. tally erp 9, tallyprime, tally prime"></div>
    <div class="field" style="flex:0 1 200px"><label for="darea">Area <span class="hint">optional</span></label><select id="darea" name="area"><option value="">–</option><?php foreach ($areas as $a => $n): ?><option><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="flex:0 1 150px"><label for="category">Type</label><select id="category" name="category"><option value="skill">Skill</option><option value="qualification">Qualification</option><option value="location">Place</option><option value="designation">Job title</option></select></div>
    <div class="field"><button class="btn" type="submit">Add</button></div>
  </form>
  <form method="get" action="/settings" class="row" style="align-items:flex-end;margin-top:8px">
    <div class="field" style="flex:3;margin:0"><label for="dq">Find</label><input type="search" id="dq" name="q" value="<?= e($q) ?>" placeholder="Search the dictionary"></div>
    <div class="field" style="flex:2;margin:0"><label for="dfa">Area</label><select id="dfa" name="area" data-autosubmit><option value="">All areas</option><?php foreach ($areas as $a => $n): ?><option value="<?= e($a) ?>"<?= $a === $area ? ' selected' : '' ?>><?= e($a) ?> (<?= (int) $n ?>)</option><?php endforeach; ?></select></div>
    <div><button class="btn ghost" type="submit">Search</button><?php if ($q !== '' || $area !== ''): ?> <a class="small" href="/settings#dictionary">Show all</a><?php endif; ?></div>
  </form>
  <div class="table-wrap" style="margin-top:14px;max-height:460px;overflow:auto"><table class="list" style="font-size:14px">
    <thead><tr><th>Name</th><th>Area</th><th>Type</th><th>Also matches</th></tr></thead>
    <tbody><?php foreach ($dict as $d): ?><tr><td><b><?= e($d['canonical']) ?></b></td><td class="small"><?= e((string) $d['area']) ?></td><td><?= e(ucfirst($d['category'])) ?></td><td class="small muted"><?= e($d['terms']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
