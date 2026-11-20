<?php
$srcLabel = function (string $k) use ($sources, $c): string {
    if (($c[$k] ?? null) === null || $c[$k] === '') {
        return ' <span class="src">not found</span>';
    }
    return match ($sources[$k] ?? '') {
        'you' => ' <span class="src">confirmed</span>',
        'estimated' => ' <span class="confirm" title="Worked out from job dates. Please check.">estimated</span>',
        'filename' => ' <span class="confirm" title="Taken from the file name. Please check.">from file name</span>',
        'ocr' => ' <span class="confirm" title="Read from a scan. Please check.">from scan</span>',
        'candidate' => ' <span class="src" title="Given by the candidate in the screening questions">from candidate</span>',
        'email' => ' <span class="confirm" title="Taken from the email the CV came with. Please check.">from email</span>',
        'ai' => ' <span class="src" title="Read from the CV by Claude">read by Claude</span>',
        default => ' <span class="src">from CV</span>',
    };
};
$facts = [
    'Current position' => [e($c['current_title'] ?: '–'), 'current_title'],
    'Level' => [e(level_name(exp_now($c)) ?? '–'), 'exp_months'],
    'Email' => [$c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '–', 'email'],
    'Phone' => [$c['phone'] ? e($c['phone']) . ($c['phone_norm'] ? ' <a class="small" href="/candidates/' . (int) $c['id'] . '/whatsapp" target="_blank" rel="noopener">WhatsApp</a>' : '') : '–', 'phone'],
    'LinkedIn' => [!empty($c['linkedin_url']) ? '<a href="' . e($c['linkedin_url']) . '" target="_blank" rel="noopener noreferrer">' . e(preg_replace('#^https://www\.linkedin\.com/#', 'linkedin.com/', $c['linkedin_url'])) . '</a>' : '–', 'linkedin_url'],
    'Experience' => [e(experience(exp_now($c))) . (($xc = \Eshcol\Ats\Services\ExperienceCheck::assess($c))['label'] !== 'Unknown' ? ' <span class="chip ' . e($xc['tone']) . '" title="' . e($xc['note']) . '">' . e($xc['label']) . ($xc['old'] ? ' · old CV' : '') . '</span>' : '') . ($xc['check'] || $xc['old'] ? '<br><span class="small muted">' . e($xc['note']) . '</span>' : '') . (exp_is_estimate($c)
        ? ' <span class="confirm" title="Grown from ' . e(experience($c['exp_months'])) . ' on ' . e(nice_date($c['exp_as_of'])) . ', assuming they stayed in work">estimated today</span><br><span class="small muted">' . e(experience($c['exp_months'])) . ' as of ' . e(nice_date($c['exp_as_of'])) . '</span>'
        : ''), 'exp_months'],
    'Location' => [e($c['location'] ?: '–'), 'location'],
    'Locality' => [e($c['locality'] ?? '' ?: '–') . (!empty($c['pin_code']) ? ' <span class="muted">· PIN ' . e($c['pin_code']) . '</span>' : ''), 'locality'],
    'Current CTC' => [e(lpa($c['ctc_current_lpa'])), 'ctc_current_lpa'],
    'Expected CTC' => [e(lpa($c['ctc_expected_lpa'])), 'ctc_expected_lpa'],
    'Lowest they\'d accept' => [e(lpa($c['ctc_min_lpa'])), 'ctc_min_lpa'],
    'Notice' => [($c['notice_days'] === null ? '–' : ((int) $c['notice_days'] === 0 ? 'Immediate' : (int) $c['notice_days'] . ' days'))
        . (!empty($c['serving_notice']) ? ' <span class="chip yes" title="The CV says they are serving notice">serving notice' . (!empty($c['last_working_day']) ? ', last day ' . e(nice_date($c['last_working_day'])) : '') . '</span>' : ''), 'notice_days'],
    'Qualification' => [e($c['qualifications'] ?: '–'), 'qualifications'],
    'Current employer is' => [e($c['employer_type'] ?? '' ?: '–'), 'employer_type'],
    'Software' => [e($c['software'] ?? '' ?: '–'), 'software'],
    'Languages' => [e($c['languages'] ?? '' ?: '–'), 'languages'],
    'Came from' => [e(channel_name($c['channel'] ?? null)), 'channel'],
];
$backRole = (int) ($_GET['role'] ?? 0);
$wa = new \Eshcol\Ats\Services\WhatsApp(\Eshcol\Ats\App::get());
$waNumber = \Eshcol\Ats\Services\WhatsApp::number($c);
?>
<?php $nm = $c['full_name'] ?: 'Name not found'; ?>
<?php // Stays in view under the search bar while you scroll (16 Nov 2026), and names the position.
$backTitle = $backRole ? (string) \Eshcol\Ats\App::get()->db->col('SELECT title FROM roles WHERE id = ?', [$backRole]) : '';
// Back lands on their row in the list (17 Nov 2026), not the top of the position page: #app-{id} on the role page.
$backApp = $backRole ? (int) (array_values(array_filter($apps, fn ($x) => (int) $x['role_id'] === $backRole))[0]['id'] ?? 0) : 0; ?>
<div class="back-bar"><?= $backRole ? '<a class="back-btn" href="/roles/' . $backRole . ($backApp ? '#app-' . $backApp : '') . '" data-back-role="' . $backRole . '"' . ($backApp ? ' data-back-app="' . $backApp . '"' : '') . '>‹ Back to ' . e($backTitle !== '' ? $backTitle : 'the role') . '</a>' : (\Eshcol\Ats\Access::restricted(\Eshcol\Ats\Auth::user()) ? '<a class="back-btn" href="/roles">‹ Your positions</a>' : '<a class="back-btn" href="/candidates">‹ Candidates</a>') ?> <span class="ref"><?= ref_candidate($c['id']) ?></span> <span class="back-name"><?= e($nm) ?></span></div>
<div class="card" style="padding:22px 24px">
  <div class="profile-hero">
    <span class="avatar xl" style="--h:<?= avatar_hue($nm) ?>"><?= e(initials($nm)) ?></span>
    <div class="who">
      <h1><?= e($nm) ?><?= $srcLabel('full_name') ?>
        <?php if ($c['do_not_contact']): ?> <span class="badge cancelled" style="vertical-align:middle">do not contact</span><?php endif; ?>
        <?php if (!empty($c['unreliable_at'])): ?> <span class="badge cancelled" style="vertical-align:middle">unreliable</span><?php endif; ?>
        <?php if (!empty($c['red_listed_at'])): ?> <span class="badge cancelled" style="vertical-align:middle">red-listed</span><?php endif; ?></h1>
      <?php // Where they stand on every open position, at the top of their page (20 Nov 2026: Soham's showed nowhere up here).
        $hdrStages = stage_tags($c['id']);
        if ($hdrStages !== ''): ?><p class="small" style="margin:6px 0 0"><?= $hdrStages ?></p><?php endif; ?>
      <?php // What Claude is doing with this CV right now (audit UI 7.1: clear states rather than "within five minutes").
        $sdb = \Eshcol\Ats\App::get()->db;
        $readSt = (string) $sdb->col("SELECT status FROM cv_ai_reads WHERE candidate_id = ? ORDER BY id DESC LIMIT 1", [$c['id']]);
        $catSt = (string) $sdb->col('SELECT status FROM categorise_queue WHERE candidate_id = ?', [$c['id']]);
        $states = [];
        if (in_array($readSt, ['pending', 'reading'], true)) { $states[] = '<span class="chip state-busy">Claude is reading the CV</span>'; }
        elseif ($readSt === 'failed') { $states[] = '<span class="chip no" style="text-decoration:none">Claude could not read the CV</span>'; }
        if (in_array($catSt, ['pending', 'working'], true)) { $states[] = '<span class="chip state-busy">Being given a field of work</span>'; }
        if ($states): ?><p class="small" style="margin:4px 0 0"><?= implode(' ', $states) ?></p><?php endif; ?>
      <p class="sub job-line"><?= e($c['current_title'] ?: 'Position not found') ?><?= $c['current_company'] ? ' at ' . e($c['current_company']) : '' ?></p>
      <div class="facts-inline">
        <span><?= icon('pin', 'sm') ?><?= e(\Eshcol\Ats\Services\Commute::place($c['locality'] ?? null, $c['location']) ?: 'Location unknown') ?></span>
        <?php // How far from each client office they're put forward for (16 Nov 2026): the area guide and, where both map points are known, km.
        $hdrApp = \Eshcol\Ats\App::get();
        $hdrCm = new \Eshcol\Ats\Services\Commute(new \Eshcol\Ats\Services\Skills($hdrApp->db));
        foreach ($hdrApp->db->all("SELECT DISTINCT cl.id, cl.name, cl.interview_address, cl.maps_url FROM applications a JOIN roles r ON r.id = a.role_id JOIN clients cl ON cl.id = r.client_id
                WHERE a.candidate_id = ? AND r.is_pool = 0 AND r.status IN ('open','on_hold') AND cl.interview_address IS NOT NULL AND cl.interview_address <> ''", [(int) $c['id']]) as $hdrCl):
            $hdrOff = $hdrCm->localityIn((string) $hdrCl['interview_address'], $hdrCl['maps_url']);
            $hdrCom = $hdrOff ? $hdrCm->between($c['locality'] ?? null, $c['location'] ?? null, $hdrOff) : null;
            if (!$hdrCom) { continue; } ?>
          <span class="km k-<?= e($hdrCom['level']) ?>" title="From <?= e(\Eshcol\Ats\Services\Commute::place($c['locality'] ?? null, $c['location'])) ?> to <?= e($hdrCl['name']) ?>'s office at <?= e($hdrOff['locality']) ?>. A straight-line guide, not road distance or travel time."><?= icon('pin', 'sm') ?><?php $hdrWord = ['near' => 'Near', 'moderate' => 'Moderate', 'far' => 'Far'][$hdrCom['level']] ?? ''; ?><?= e(isset($hdrCom['km'])
            ? \Eshcol\Ats\Services\Commute::kmLabel($hdrCom['km']) . ' from ' . $hdrOff['locality'] . ($hdrWord !== '' ? " ($hdrWord)" : '')
            : $hdrCom['label'] . ' (office at ' . $hdrOff['locality'] . ')') ?></span>
        <?php endforeach; ?>
        <span><?= icon('award', 'sm') ?><?= e(experience(exp_now($c))) ?><?= exp_is_estimate($c) ? ' (est.)' : '' ?></span>
        <span><?= icon('clock', 'sm') ?>Notice <?= $c['notice_days'] === null ? '?' : ((int) $c['notice_days'] === 0 ? 'immediate' : (int) $c['notice_days'] . ' days') ?></span>
        <span>₹ <?= e(lpa($c['ctc_current_lpa'])) ?> → <?= e(lpa($c['ctc_expected_lpa'])) ?><?= $c['ctc_min_lpa'] !== null ? ' <span class="muted">(min ' . e(lpa($c['ctc_min_lpa'])) . ')</span>' : '' ?></span>
      </div>
      <p class="meta-pills"><span>On file since <?= e(nice_date($c['created_at'])) ?></span><span>Source: <?= e(!empty($c['channel']) ? channel_name($c['channel']) : $c['source']) ?></span><span>Last contact <?= e(nice_date($c['last_activity_at'] ?? $c['created_at'])) ?></span><span>Kept until <?= e(nice_date($c['purge_after'])) ?><?= $c['retention_note'] ? ' (' . e($c['retention_note']) . ')' : '' ?></span></p>
    </div>
    <div class="actions">
      <?php if ($c['phone']): ?><a class="btn ghost" href="<?= e(tel_link($c['phone'])) ?>" title="Call <?= e($c['phone']) ?> from this computer"><?= icon('phone') ?><span class="hide-sm">Call</span></a><?php endif; ?>
      <?php if ($waNumber && !$c['do_not_contact']): ?><a class="btn ghost" href="/candidates/<?= (int) $c['id'] ?>/whatsapp" target="_blank" rel="noopener"><?= icon('chat') ?><span class="hide-sm">WhatsApp</span></a><?php endif; ?>
      <?php if ($c['email']): ?><a class="btn ghost" href="mailto:<?= e($c['email']) ?>"><?= icon('mail') ?><span class="hide-sm">Email</span></a><?php endif; ?>
      <?php if (!\Eshcol\Ats\Access::restricted($user ?? null)): ?><a class="btn" href="/candidates/<?= $c['id'] ?>/edit"><?= icon('edit') ?>Edit</a><?php endif; ?>
    </div>
  </div>
  <?php if (!empty($c['red_listed_at'])): ?>
    <div class="flash error" style="margin:14px 0 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <div style="flex:1;min-width:220px"><b>Red-listed.</b> <?= e(\Eshcol\Ats\Services\RedList::describe($c)) ?><br><span class="small">A hard rejection on one position: left out of database searches, shortlists, screening and interview invitations for every position, with any client. Set automatically when an application is moved to "Rejected: insufficient information"; this clears it without reversing that stage.</span></div>
      <form method="post" action="/candidates/<?= (int) $c['id'] ?>/red-list"><?= csrf() ?><button class="btn small ghost" type="submit" data-confirm-click="Clear the red list mark and allow them to be put forward again?">Clear the mark</button></form>
    </div>
  <?php endif; ?>
  <?php if (!empty($c['unreliable_at'])): ?>
    <div class="flash error" style="margin:14px 0 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <div style="flex:1;min-width:220px"><b>Unreliable.</b> <?= e(\Eshcol\Ats\Services\Reliability::describe($c)) ?><br><span class="small">Left out of database searches, shortlists, screening and interview invitations. If they apply again, their CV is still saved with this mark.</span></div>
      <form method="post" action="/candidates/<?= (int) $c['id'] ?>/unreliable"><?= csrf() ?><input type="hidden" name="clear" value="1"><button class="btn small ghost" type="submit" data-confirm-click="Clear the mark and allow them to be approached again?">Clear the mark</button></form>
    </div>
  <?php else: ?>
    <details class="small" style="margin-top:12px"><summary>Mark as unreliable <span class="muted">(did not turn up, stopped responding, backed out)</span></summary>
      <form method="post" action="/candidates/<?= (int) $c['id'] ?>/unreliable" style="margin-top:8px"><?= csrf() ?>
        <div class="pub-opts"><?php foreach (\Eshcol\Ats\Services\Reliability::REASONS as $k => $v): ?><label class="pub-opt"><input type="radio" name="reason" value="<?= $k ?>" required> <span><?= e($v) ?></span></label><?php endforeach; ?></div>
        <input type="text" name="note" maxlength="300" placeholder="What happened (optional), e.g. no reply to 3 calls and 2 emails after the screening" style="width:100%;margin-top:6px">
        <button class="btn small" type="submit" style="margin-top:6px">Mark unreliable</button>
        <span class="hint">Keeps them out of searches, shortlists, screening and interview invitations. You can clear it later.</span>
      </form>
    </details>
  <?php endif; ?>
</div>
<?php if ($c['removal_requested_at']): ?>
  <div class="card" style="border-left:4px solid var(--red)">
    <b>They asked us not to keep their details</b> (<?= e(nice_date($c['removal_requested_at'], true)) ?>).
    <p class="small" style="margin:6px 0 0">They are not emailed again. Once you no longer need them for a role in progress, delete them under <a href="#delete">Delete</a>, choosing "they asked to be removed".</p>
  </div>
<?php endif; ?>
<?php $pc = json_decode((string) ($c['pending_contact'] ?? ''), true) ?: []; if ($pc): ?>
  <div class="card" style="border-left:4px solid var(--sand)">
    <b>They gave a new <?= e(implode(' and ', array_filter([!empty($pc['email']) ? 'email' : null, !empty($pc['phone']) ? 'mobile' : null]))) ?></b> when completing their application (<?= e(nice_date($pc['at'] ?? null, true)) ?>). It waits for you, because one typo would cut us off from them.
    <table class="qa small" style="margin:8px 0">
      <?php if (!empty($pc['email'])): ?><tr><td>Email</td><td><?= e((string) $c['email']) ?> &rarr; <b><?= e($pc['email']) ?></b></td></tr><?php endif; ?>
      <?php if (!empty($pc['phone'])): ?><tr><td>Mobile</td><td><?= e((string) $c['phone']) ?> &rarr; <b><?= e($pc['phone']) ?></b></td></tr><?php endif; ?>
    </table>
    <form method="post" action="/candidates/<?= (int) $c['id'] ?>/contact" class="actions"><?= csrf() ?>
      <button class="btn small" type="submit" name="decision" value="accept">Use the new details</button>
      <button class="btn ghost small" type="submit" name="decision" value="dismiss">Keep the old ones</button>
    </form>
  </div>
<?php endif; ?>
<?php
$canWrite = $c['email'] && !$c['do_not_contact'] && !$c['removal_requested_at'];
// Tabs (audit UI 7.1): the long page split into Overview, Positions, Messages, History & details and CV.
$hasMsgs = !empty($messages) || $canWrite;
$unreadMsgs = count(array_filter($messages ?? [], fn ($mm) => $mm['direction'] === 'in' && empty($mm['read_at'])));
$tabs = array_filter(['overview' => [$report ? 'Overview' : null, 'sparkles'], 'positions' => ['Positions', 'briefcase'], 'messages' => [$hasMsgs ? 'Messages' : null, 'chat'],
    'history' => ['History & details', 'clipboard'], 'cv' => ['CV', 'file']], fn ($t) => $t[0] !== null);
?>
<div class="split">
  <div>
<nav class="tabs" data-tabs data-default="<?= $report ? 'overview' : 'positions' ?>" aria-label="Candidate sections">
  <?php foreach ($tabs as $k => [$label, $ic]): ?><a href="#tab-<?= $k ?>" data-tab="<?= $k ?>"><?= icon($ic, 'sm') ?><?= e($label) ?><?= $k === 'positions' && $apps ? ' <span class="n">' . count($apps) . '</span>' : ($k === 'messages' && $unreadMsgs ? ' <span class="n">' . $unreadMsgs . ' new</span>' : '') ?></a><?php endforeach; ?>
</nav>
<?php
if ($hasMsgs):
  $lastIn = null;
  foreach ($messages as $mm) { if ($mm['direction'] === 'in' && $mm['source'] === 'email') { $lastIn = $mm; break; } }
  $replyId = (int) ($_GET['reply'] ?? 0);
  $replyTo = $replyId ? (array_values(array_filter($messages, fn ($mm) => (int) $mm['id'] === $replyId && $mm['direction'] === 'in'))[0] ?? $lastIn) : $lastIn;
  $draft = $_SESSION['message_draft'][(int) $c['id']] ?? null;
  unset($_SESSION['message_draft'][(int) $c['id']]);
  $subj = $draft['subject'] ?? ($replyTo && $replyTo['subject'] ? (preg_match('/^\s*re\s*:/i', $replyTo['subject']) ? $replyTo['subject'] : 'Re: ' . $replyTo['subject']) : '');
  $stateLabel = ['sent' => 'sent', 'pending' => 'queued', 'held' => 'waiting under Emails', 'failed' => 'not delivered', 'cancelled' => 'cancelled'];
?>
  <div class="tab-panel" data-panel="messages" id="tab-messages">
  <section class="card" id="messages">
    <h2 style="margin-bottom:10px"><?= icon('chat') ?> Messages with the candidate</h2>
    <?php if ($canWrite): ?>
      <details class="compose"<?= ($replyId || $draft || $lastIn) ? ' open' : '' ?>>
        <summary class="btn ghost small"><?= icon('mail', 'sm') ?><?= $replyTo ? 'Reply' : 'Write to ' . e(\Eshcol\Ats\Services\Questionnaires::firstName($c) ?: 'them') ?></summary>
        <form method="post" action="/candidates/<?= (int) $c['id'] ?>/messages" style="margin-top:10px" enctype="multipart/form-data"><?= csrf() ?>
          <?php if ($replyTo): ?><input type="hidden" name="reply_to" value="<?= (int) $replyTo['id'] ?>"><p class="small muted" style="margin:0 0 6px">Replying to their message of <?= e(nice_date($replyTo['received_at'], true)) ?>; it is quoted under your words.</p><?php endif; ?>
          <div class="field"><label for="msg_subject">Subject</label><input type="text" id="msg_subject" name="subject" maxlength="200" value="<?= e($subj) ?>" placeholder="A message from Eshcol Global"></div>
          <div class="field"><label for="msg_body">Message</label><textarea id="msg_body" name="body" rows="6" maxlength="5000" required placeholder="Dear <?= e(\Eshcol\Ats\Services\Questionnaires::firstName($c) ?: 'Candidate') ?>,"><?= e($draft['body'] ?? '') ?></textarea>
            <span class="hint">Goes from <?= e((string) \Eshcol\Ats\App::get()->setting('mail_from_email', 'resume@eshcolglobal.com')) ?> to <?= e($replyTo && $replyTo['from_email'] ? $replyTo['from_email'] : $c['email']) ?>, with your signature. Their answer comes back here.</span></div>
          <div class="field">
            <label>Attach <span class="hint">optional</span></label>
            <?php foreach (($attachDocs ?? []) as $d): ?>
              <label class="pub-opt" style="display:flex;gap:8px;align-items:center;margin:0 0 6px;font-weight:normal"><input type="checkbox" name="doc[]" value="<?= (int) $d['id'] ?>"> <span><?= icon('file', 'sm') ?> <?= e($d['label']) ?></span></label>
            <?php endforeach; ?>
            <label class="small muted" style="display:block;margin-top:<?= ($attachDocs ?? []) ? '6px' : '0' ?>">Or a file from your computer (PDF or Word, up to 10 MB): <input type="file" name="attachment" accept=".pdf,.doc,.docx"></label>
          </div>
          <p class="actions"><button class="btn small" type="submit"><?= icon('send', 'sm') ?>Send</button></p>
        </form>
      </details>
    <?php elseif ($c['email']): ?>
      <p class="small muted">They are marked do not contact (or asked us not to keep their details), so no messages can be sent.</p>
    <?php endif; ?>
    <?php if (!$messages): ?><p class="small muted" style="margin:10px 0 0">No messages yet.</p><?php endif; ?>
    <?php foreach ($messages as $msg): $out = $msg['direction'] === 'out'; ?>
      <div class="cand-msg<?= $out ? ' out' : '' ?><?= $msg['read_at'] ? '' : ' new' ?><?= $msg['removal'] === 'asked' ? ' removal' : '' ?>">
        <p class="small muted" style="margin:0 0 4px"><?= $msg['read_at'] ? '' : '<span class="chip yes">new</span> ' ?><?= e(nice_date($msg['received_at'], true)) ?> ·
          <?php if ($out): ?><b>You<?= $msg['sent_by_name'] ? ' (' . e($msg['sent_by_name']) . ')' : '' ?></b> wrote<?= $msg['email_status'] ? ' · <span class="' . ($msg['email_status'] === 'failed' ? 'text-bad' : '') . '" title="' . e((string) $msg['last_error']) . '">' . e($stateLabel[$msg['email_status']] ?? $msg['email_status']) . '</span>' : '' ?><?= $msg['subject'] ? ' · &ldquo;' . e(mb_strimwidth($msg['subject'], 0, 80, '…')) . '&rdquo;' : '' ?>
          <?php else: ?><?= $msg['source'] === 'form' ? 'On their application form' : 'By email' . ($msg['from_email'] ? ' from ' . e($msg['from_email']) : '') ?><?= $msg['subject'] && $msg['source'] === 'email' ? ' · &ldquo;' . e(mb_strimwidth($msg['subject'], 0, 80, '…')) . '&rdquo;' : '' ?>
            <?php if ($canWrite && $msg['source'] === 'email'): ?> · <a href="/candidates/<?= (int) $c['id'] ?>?reply=<?= (int) $msg['id'] ?>#messages">Reply to this</a><?php endif; ?><?php endif; ?></p>
        <div class="cand-msg-body"><?= nl2br(e($msg['body'])) ?></div>
        <?php if (!empty($msg['attachments'])): ?><p class="small muted" style="margin:6px 0 0"><?php foreach ($msg['attachments'] as $an): ?><span class="chip"><?= icon('file', 'sm') ?> <?= e($an) ?></span> <?php endforeach; ?></p><?php endif; ?>
        <?php if ($msg['removal'] === 'asked'): ?>
          <form method="post" action="/messages/<?= (int) $msg['id'] ?>/removal" class="actions" style="margin-top:8px;align-items:center"><?= csrf() ?>
            <span class="small"><b>This looks like a request to remove their details.</b></span>
            <button class="btn small" type="submit" name="decision" value="confirm">Yes, they asked to be removed</button>
            <button class="btn ghost small" type="submit" name="decision" value="dismiss">No, it isn't</button>
          </form>
        <?php elseif ($msg['removal'] === 'confirmed'): ?><p class="small" style="margin:6px 0 0"><span class="chip flag">Removal request confirmed</span></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
  </div>
<?php endif; ?>
<?php if ($report): $fitTone = ['strong' => 'yes', 'possible' => 'sand', 'weak' => '']; ?>
<div class="tab-panel" data-panel="overview" id="tab-overview">
<section class="card" id="report">
  <h2 style="margin-bottom:6px"><?= icon('sparkles') ?> CV report</h2>
  <nav class="tabs" data-tabs aria-label="CV report">
    <a href="#tab-rep-free" data-tab="rep-free">Summary</a>
    <a href="#tab-rep-ai" data-tab="rep-ai">AI analysis<?= $ai ? ' <span class="n">✓</span>' : '' ?></a>
  </nav>

  <div class="tab-panel" data-panel="rep-free" id="tab-rep-free">
    <?php if (!$report['readable']): ?><p class="small muted">The CV text could not be read well, so this report is thin. If it is a scan, Claude writes out its text when it reads the CV.</p><?php endif; ?>
    <?php // Option B (Benjamin, 20 Nov 2026): the deciding numbers first, then short headed sections, instead of one paragraph.
      $bf = $report['brief']; ?>
    <div class="brief">
      <div class="brief-tiles"><?php foreach ($bf['tiles'] as [$v, $l]): ?><div class="brief-tile"><b><?= e($v) ?></b><span><?= e($l) ?></span></div><?php endforeach; ?></div>
      <h4>Who they are</h4><p><?= e($bf['who']) ?></p>
      <?php if ($bf['jobs']): ?><h4>Track record</h4><ul><?php foreach ($bf['jobs'] as $j): ?><li><?= e($j) ?></li><?php endforeach; ?><?php if ($bf['more_jobs']): ?><li class="muted">and <?= (int) $bf['more_jobs'] ?> earlier</li><?php endif; ?></ul><?php endif; ?>
      <h4>Fit</h4><ul><?php foreach ($bf['fit'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
      <?php if ($bf['check']): ?><h4>To check on the call</h4><ul class="brief-check"><?php foreach ($bf['check'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>

    <?php if (!$report['roles'] && !empty($report['field_note'])): ?>
      <h3 style="margin-top:18px">Best roles</h3>
      <p class="small muted" style="margin:0"><?= e($report['field_note']) ?></p>
    <?php endif; ?>
    <?php if ($report['roles']): ?>
      <h3 style="margin-top:18px">Best roles</h3>
      <ul class="rep-roles">
        <?php foreach (array_slice($report['roles'], 0, 5) as $i => $r): ?>
          <li>
            <div class="rr-head">
              <span class="rr-rank">#<?= $i + 1 ?></span>
              <a href="/roles/<?= $r['id'] ?>"><b><?= e($r['title']) ?></b></a> <span class="small muted"><?= e($r['code']) ?> · <?= e($r['client']) ?></span>
              <?php if ($r['claude']): $cs = (int) $r['claude']['score']; ?>
                <span class="rr-bar" title="Claude's assessment"><i style="width:<?= $cs ?>%;background:<?= $cs >= 75 ? 'var(--green)' : ($cs >= 60 ? 'var(--gold)' : 'var(--red)') ?>"></i></span>
                <b class="rr-pct" title="Claude's assessment"><?= $cs ?></b> <span class="chip <?= ['Strong' => 'yes', 'Possible' => 'sand', 'Weak' => 'no'][$r['claude']['band']] ?? '' ?>" style="text-decoration:none">Claude: <?= e($r['claude']['band']) ?></span>
                <span class="small muted" title="The free check: must-have keywords, experience, qualification, location, notice and salary">keyword match <?= (int) $r['score'] ?>%</span>
              <?php else: ?>
                <span class="rr-bar" title="Keyword match (the free check); Claude has not assessed them for this role"><i style="width:<?= (int) $r['score'] ?>%;background:<?= $r['score'] >= 70 ? 'var(--green)' : ($r['score'] >= 50 ? 'var(--gold)' : 'var(--red)') ?>"></i></span>
                <b class="rr-pct" title="Keyword match (the free check)"><?= (int) $r['score'] ?>%</b> <span class="small muted">keyword match</span>
              <?php endif; ?>
              <?php if ($r['on_role']): ?><span class="chip">On this role</span>
              <?php else: ?><form method="post" action="/candidates/<?= (int) $c['id'] ?>/roles" style="display:inline"><?= csrf() ?><input type="hidden" name="role_id" value="<?= $r['id'] ?>"><button class="btn small ghost"><?= icon('plus', 'sm') ?>Add</button></form><?php endif; ?>
            </div>
            <div class="small rr-detail">
              <?php foreach ($r['matched'] as $m): ?><span class="tick ok">✓ <?= e($m) ?></span><?php endforeach; ?>
              <?php foreach ($r['missing'] as $m): ?><span class="tick no">✗ <?= e($m) ?></span><?php endforeach; ?>
              <span class="muted">· Experience: <?= e($r['experience']) ?></span>
              <?php if (!str_contains($r['ctc'], 'not set')): ?><span class="<?= $r['ctc_over'] ? 'flagged' : 'muted' ?>">· CTC: <?= e($r['ctc']) ?></span><?php endif; ?>
              <?php if (!str_contains($r['notice'], 'not set')): ?><span class="<?= $r['notice_over'] ? 'flagged' : 'muted' ?>">· Notice: <?= e($r['notice']) ?></span><?php endif; ?>
              <?php if (!empty($r['commute'])): ?><span class="chip <?= e($r['commute']['tone']) ?>" title="Office: <?= e((string) $r['office']) ?>. A rough guide from the localities, not a travel time."><?= icon('pin', 'sm') ?> <?= e($r['commute']['label']) ?></span><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="grid g2" style="margin-top:14px">
      <div>
        <h3><?= icon('flag', 'sm') ?> Red flags</h3>
        <?php if (!$report['flags']): ?><p class="small muted">None found.</p><?php else: ?>
          <ul class="small rep-list"><?php foreach ($report['flags'] as $f): ?><li><?= e($f['text']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
      <div>
        <h3><?= icon('chat', 'sm') ?> Ask on the call</h3>
        <?php if (!$report['questions']): ?><p class="small muted">Nothing obvious to ask.</p><?php else: ?>
          <ul class="small rep-list"><?php foreach ($report['questions'] as $q): ?><li><?= e($q) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($report['history']): ?>
      <details style="margin-top:10px"><summary class="small">Dates found in the CV (<?= count($report['history']) ?>)</summary>
        <ul class="small rep-list"><?php foreach ($report['history'] as $h): ?><li><?= e($h['from']) ?> – <?= e($h['to']) ?>: <?= e($h['label']) ?><?= $h['education'] ? ' <span class="muted">(course)</span>' : (!empty($h['break']) ? ' <span class="muted">(break)</span>' : '') ?></li><?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
    <p class="hint" style="margin-top:10px">Built from what the app read in the CV. Nothing leaves your server.</p>
  </div>

  <div class="tab-panel" data-panel="rep-ai" id="tab-rep-ai">
    <?php if ($ai): $r = $ai['report']; ?>
      <p style="line-height:1.6;margin-top:0"><?= e($r['summary'] ?? '') ?></p>
      <?php if (!empty($r['recommendation'])): ?><p><b>Overall:</b> <?= e($r['recommendation']) ?></p><?php endif; ?>
      <div class="grid g3" style="margin-top:6px">
        <div><h3>Strengths</h3><ul class="small rep-list"><?php foreach ($r['strengths'] ?? [] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
        <div><h3>Concerns</h3><ul class="small rep-list"><?php foreach ($r['concerns'] ?? [] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
        <div><h3>Ask on the call</h3><ul class="small rep-list"><?php foreach ($r['questions'] ?? [] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
      </div>
      <?php if (!empty($r['roles'])): ?>
        <h3 style="margin-top:12px">Fit for open roles</h3>
        <ul class="small rep-list"><?php foreach ($r['roles'] as $x): ?><li><span class="chip <?= $fitTone[$x['fit']] ?? '' ?>"><?= e(ucfirst($x['fit'])) ?></span> <b><?= e($x['code']) ?></b> <?= e($x['reason']) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if (!empty($r['filled'])): ?><p class="small" style="margin:10px 0 0"><?= icon('check', 'sm') ?> Filled in from the CV: <b><?= e(implode(', ', $r['filled'])) ?></b>. They're marked "read by Claude" on this page.</p><?php endif; ?>
      <p class="hint" style="margin-top:10px">Written by Claude (<?= e($ai['model']) ?>) on <?= e(nice_date($ai['created_at'], true)) ?> from <?= ($r['read_from'] ?? 'text') === 'text' ? "the CV's text" : 'the CV file itself' ?>. A first draft: check anything important against the CV.<?= !empty($r['cv_truncated']) ? ' The CV was very long, so only its first part was read.' : '' ?></p>
      <?php if ($aiStale && $aiOn): ?>
        <form method="post" action="/candidates/<?= (int) $c['id'] ?>/ai" data-ai-form><?= csrf() ?><button class="btn small ghost"><?= icon('sparkles', 'sm') ?>Analyse the new CV</button></form>
      <?php endif; ?>
    <?php elseif (!$aiOn): ?>
      <p class="small muted" style="margin-top:0">AI analysis is not set up.<?= \Eshcol\Ats\Access::restricted(\Eshcol\Ats\Auth::user()) ? '' : ' Add a Claude API key in <a href="/settings#ai">Settings › AI analysis</a>.' ?> The free summary works without it.</p>
    <?php elseif ($c['do_not_contact'] || $c['removal_requested_at']): ?>
      <p class="small muted" style="margin-top:0">Not available: this person is marked do not contact, or asked us not to keep their details.</p>
    <?php else: ?>
      <p class="small" style="margin-top:0">Claude reads this CV against your open roles and writes a recruiter's note: a summary, strengths, concerns, questions to ask and how each role fits.</p>
      <form method="post" action="/candidates/<?= (int) $c['id'] ?>/ai" data-ai-form><?= csrf() ?>
        <button class="btn"><?= icon('sparkles', 'sm') ?>Analyse with AI</button>
        <span class="small muted">About ₹2–6 · takes 20–40 seconds · sends the CV to Anthropic (Claude) · fills in any details the app couldn't read</span>
      </form>
    <?php endif; ?>
  </div>
</section>
</div>
<?php endif; ?>
    <?php foreach ($files as $f): if ($f['text_status'] === 'ok') { continue; } ?>
      <div class="card sand" id="ocr-card-<?= $f['id'] ?>">
        <h3><?= $f['text_status'] === 'needs_ocr' ? 'This CV is a scan or photo' : 'This CV could not be read' ?></h3>
        <p class="small" style="margin:0"><?php // Claude writes out scans when it reads the CV (15 Nov 2026: the in-browser reader is gone, audit B16). ?>
          <?php if (in_array($readSt, ['pending', 'reading'], true)): ?>Claude is reading it now and will write out its text; it can then be searched and scored like any other CV.
          <?php elseif ($readSt === 'failed'): ?>Claude could not read it. Try again from <a href="/settings/ai-reading">Settings › AI reading</a> (Retry), or ask the candidate for a Word or PDF copy with text.
          <?php else: ?>It has no text the server can read. Claude writes out the text of a scan when it reads the CV (Settings › AI reading); until then it can't be searched or scored.<?php endif; ?></p>
      </div>
    <?php endforeach; ?>

    <div class="tab-panel" data-panel="history" id="tab-history">
    <?php if (!empty($workHistory) || !empty($education)):
      $span = function (array $h): string {
          $m = max(1, (int) $h['end'] - (int) $h['start'] + 1);
          return ($m >= 12 ? intdiv($m, 12) . ' yr' . (intdiv($m, 12) > 1 ? 's' : '') : '') . ($m % 12 ? ($m >= 12 ? ' ' : '') . ($m % 12) . ' mo' . ($m % 12 > 1 ? 's' : '') : '');
      }; ?>
      <h2><?= icon('briefcase') ?> Work history and education</h2>
      <div class="card" id="history">
        <?php if (!empty($workHistory)): ?>
          <div class="table-wrap"><table class="list compact">
            <thead><tr><th>Job</th><th>From</th><th>To</th><th>Time</th></tr></thead>
            <tbody><?php foreach ($workHistory as $h): ?>
              <tr<?= $h['education'] ? ' class="muted"' : '' ?>><td><?= e($h['label']) ?><?= $h['current'] ? ' <span class="chip yes">now</span>' : '' ?></td>
                <td class="nowrap"><?= e($h['from']) ?></td><td class="nowrap"><?= e($h['to']) ?></td><td class="nowrap"><?= e($span($h)) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
          <p class="small muted" style="margin:6px 0 14px">Read from the CV by Claude. Internships and articleship are shown but not counted as work experience.</p>
        <?php endif; ?>
        <?php if (!empty($education)): ?>
          <div class="table-wrap"><table class="list compact">
            <thead><tr><th>Qualification</th><th>Institute</th><th>Year</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($education as $ed): ?>
              <tr><td><b><?= e($ed['degree']) ?></b></td><td><?= e($ed['institute'] ?: '–') ?></td><td class="nowrap"><?= e($ed['year'] ?: '–') ?></td><td><?= e($ed['status'] ? ucfirst($ed['status']) : '–') ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
        <?php elseif (!empty($workHistory)): ?>
          <p class="small muted" style="margin:0">Education, software, languages and employer type appear after the CV is next read by Claude (new CVs are read automatically).</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php $gq = $screening[0] ?? null; if ($gq && in_array($gq['status'], ['answered', 'sent', 'opened', 'declined'], true)): $gFlags = json_decode((string) $gq['flags'], true) ?: []; ?>
      <h2 id="their-details"><?= icon('clipboard') ?> Their details</h2>
      <?php $gPending = $gq['status'] === 'answered' && empty($gq['reviewed_at']); ?>
      <div class="card<?= $gPending ? ' to-review' : '' ?>">
        <?php if ($gq['status'] === 'answered'): ?>
          <div class="<?= $gPending ? 'review-panel' : '' ?>" style="<?= $gPending ? '' : 'margin:0' ?>">
          <p class="small" style="margin:0 0 6px"><b>Completed their application</b> · <?= e(nice_date($gq['answered_at'], true)) ?> <span class="muted">(from the thank-you email, no role named)</span><?= !empty($gq['reviewed_at']) ? ' · <span class="chip yes" style="text-decoration:none">reviewed ' . e(nice_date($gq['reviewed_at'])) . '</span>' : '' ?></p>
          <?php if ($gPending && $gFlags): ?><ul class="review-flags"><?php foreach ($gFlags as $fl): ?><li>⚑ <?= e($fl) ?></li><?php endforeach; ?></ul>
          <?php else: foreach ($gFlags as $fl): ?><span class="chip flag">⚑ <?= e($fl) ?></span><?php endforeach; endif; ?>
          <table class="qa<?= $gPending ? ' review-qa' : '' ?>"><?php foreach ($gq['answers'] as $ans): ?><tr<?= $ans['flagged'] ? ' class="flagged"' : '' ?>><td><?= e($ans['prompt']) ?></td><td><?= e((string) $ans['answer']) ?></td></tr><?php endforeach; ?></table>
          <?php if ($gPending): ?>
            <form method="post" action="/candidates/<?= (int) $c['id'] ?>/details-reviewed" class="actions" style="margin-top:10px"><?= csrf() ?>
              <?php if (($_GET['from'] ?? '') === 'dashboard'): ?><input type="hidden" name="from" value="dashboard"><?php endif; ?>
              <button class="btn small" type="submit"><?= icon('check', 'sm') ?>Mark as reviewed</button>
              <span class="small muted">No role is named, so there is nothing to approve. Add them to a role below if one fits.</span>
            </form>
          <?php endif; ?>
          </div>
        <?php elseif ($gq['status'] === 'declined'): ?>
          <p class="small" style="margin:0"><b>Said they are no longer interested</b> · <?= e(nice_date($gq['declined_at'], true)) ?></p>
        <?php else: ?>
          <p class="small muted" style="margin:0">Asked for their details <?= e(nice_date($gq['created_at'])) ?><?= $gq['status'] === 'opened' ? ' (opened)' : '' ?><?= $gq['reminded_at'] ? ', reminded ' . e(nice_date($gq['reminded_at'])) : '' ?><?= $gq['final_at'] ? ', final email ' . e(nice_date($gq['final_at'])) : '' ?>. No answer yet.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    </div>
    <div class="tab-panel" data-panel="positions" id="tab-positions">
    <datalist id="stage-reasons"><?php foreach (STAGE_REASONS as $sr): ?><option value="<?= e($sr) ?>"><?php endforeach; ?></datalist>
    <h2><?= icon('briefcase') ?> Roles</h2>
    <?php if (!$apps): ?><div class="card"><p class="muted" style="margin:0">Not on any role yet.</p></div><?php endif; ?>
    <?php foreach ($apps as $a): $bd = json_decode($a['score_breakdown'] ?? 'null', true);
      $sq = $screening[(int) $a['id']] ?? null;
      $sqFlags = $sq ? (json_decode((string) $sq['flags'], true) ?: []) : [];
      $toReview = $sq && $sq['status'] === 'answered' && empty($sq['reviewed_at']) && ($a['stage_kind'] ?? '') === 'active' && in_array($a['role_status'] ?? 'open', ['open', 'on_hold', 'draft'], true);
      $fromList = ($_GET['from'] ?? '') === 'dashboard'; ?>
      <?php $appShort = \Eshcol\Ats\Services\Shortlists::isShortlisted(\Eshcol\Ats\App::get(), (int) $a['stage_id']); ?>
      <div id="app-<?= (int) $a['id'] ?>" class="card<?= $toReview ? ' to-review' : '' ?><?= $appShort ? ' short-panel' : '' ?>">
        <div class="actions" style="justify-content:space-between;align-items:flex-start">
          <div><a href="/roles/<?= $a['role_id'] ?>"><b><?= e($a['title']) ?></b></a><?= $appShort ? ' <span class="chip shorted" style="text-decoration:none">✓ ' . e($a['stage'] ?? 'Shortlisted') . '</span>' : '' ?><br><span class="small muted"><?= e($a['client']) ?> · <?= e($a['code']) ?> · added <?= e(nice_date($a['applied_at'])) ?></span></div>
          <span class="score <?= score_class($a['eff_score'] !== null ? (int) $a['eff_score'] : null, (int) $a['score_threshold']) ?>"><?= $a['eff_score'] ?? '–' ?></span>
        </div>
        <?php if ($toReview):
          $needs = array_filter([
              $a['role_ctc_max'] !== null ? 'Budget up to ' . lpa($a['role_ctc_max']) : null,
              $a['role_notice_max'] !== null ? 'Joining within ' . (int) $a['role_notice_max'] . ' days' : null,
              $a['role_location'] ? 'Based in ' . $a['role_location'] . (isset(WORK_MODES[$a['role_work_mode'] ?? '']) ? ' (' . WORK_MODES[$a['role_work_mode']] . ')' : '') : null,
          ]);
          $key = ['ctc_current', 'ctc_min', 'ctc_expected', 'notice', 'relocate', 'location', 'locality', 'experience', 'qualifications'];
          $byKey = [];
          foreach ($sq['answers'] as $ans) { $byKey[$ans['prompt']] = $ans; } ?>
          <section class="review-panel" id="review-<?= (int) $a['id'] ?>" aria-label="Review their answers">
            <p class="eyebrow" style="margin:0 0 2px;color:#7E6114">Waiting on you</p>
            <h3 style="margin:0 0 6px">Review their answers</h3>
            <p class="small" style="margin:0 0 10px">They completed their application <?= e(nice_date($sq['answered_at'], true)) ?>. Check the points below against what the role needs, then decide.</p>
            <div class="review-grid">
              <div>
                <p class="small" style="margin:0 0 4px"><b>To check</b></p>
                <?php if ($sqFlags): ?><ul class="review-flags"><?php foreach ($sqFlags as $fl): ?><li>⚑ <?= e($fl) ?></li><?php endforeach; ?></ul>
                <?php else: ?><p class="small" style="margin:0;color:var(--green)">✓ Nothing flagged: within the budget, notice and location the role allows.</p><?php endif; ?>
              </div>
              <?php if ($needs): ?><div><p class="small" style="margin:0 0 4px"><b>The role needs</b></p><ul class="small" style="margin:0;padding-left:18px"><?php foreach ($needs as $nd): ?><li><?= e($nd) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            </div>
            <table class="qa review-qa"><?php foreach ($sq['answers'] as $ans): ?><tr<?= $ans['flagged'] ? ' class="flagged"' : '' ?>><td><?= e($ans['prompt']) ?></td><td><?= e((string) $ans['answer']) ?></td></tr><?php endforeach; ?></table>
            <form method="post" action="/applications/<?= (int) $a['id'] ?>/review" class="review-decide"><?= csrf() ?>
              <?php if ($fromList): ?><input type="hidden" name="from" value="dashboard"><?php endif; ?>
              <label for="rv<?= (int) $a['id'] ?>" class="small"><b>Your remark</b> <span class="muted">(needed for Future requirement and Not suitable)</span></label>
              <input type="text" id="rv<?= (int) $a['id'] ?>" name="reason" maxlength="255" list="stage-reasons" placeholder="e.g. Good accountant, expects 6 LPA against a 4.2 budget">
              <div class="actions" style="margin-top:8px">
                <button class="btn small" type="submit" name="decision" value="approve"><?= icon('check', 'sm') ?>Approve</button>
                <button class="btn small keep-btn" type="submit" name="decision" value="future">Future requirement</button>
                <button class="btn ghost small" type="submit" name="decision" value="unsuitable"><?= icon('x', 'sm') ?>Not suitable</button>
              </div>
              <p class="small muted" style="margin:6px 0 0">Approve moves them on from <?= e($a['stage']) ?> and takes them off your list. <?= $fromList ? 'You then go straight to the next one waiting.' : '' ?></p>
            </form>
          </section>
        <?php endif; ?>
        <?php if ($a['fit_note']): ?><p style="margin:10px 0 0"><span class="chip <?= $a['source'] === 'routed' ? 'sand' : '' ?>"><?= $a['source'] === 'routed' ? '↪ ' : '⚠ ' ?><?= e($a['fit_note']) ?></span></p><?php endif; ?>
        <?php $fitSvc ??= new \Eshcol\Ats\Services\FitAssessor(\Eshcol\Ats\App::get()); $f = $fitSvc->forApplication((int) $a['id']); ?>
        <?php if ($f && $f['status'] === 'done'): $reqs = $f['requirements']; ?>
          <div style="margin:12px 0 0;padding:10px 12px;border-radius:10px;background:var(--bg-soft,#F6F2FB)">
            <p style="margin:0"><b>Claude's assessment: <?= (int) $f['score'] ?></b> <span class="chip <?= ['Strong' => 'yes', 'Possible' => 'sand', 'Weak' => 'no'][$f['band']] ?? '' ?>" style="text-decoration:none"><?= e($f['band']) ?></span>
              <?= $f['outdated'] ? '<span class="chip sand" title="The role\'s requirements changed after this assessment">role changed</span>' : '' ?></p>
            <p class="small" style="margin:6px 0 0"><?= e($f['result']['summary'] ?? '') ?></p>
            <?php if (!empty($f['breakdown']['knockout'])): ?><p class="small" style="margin:6px 0 0"><span class="chip no" style="text-decoration:none"><?= e($f['breakdown']['knockout']) ?></span></p><?php endif; ?>
            <details style="margin-top:6px"><summary class="small">Why this score</summary><div style="padding-top:8px"><?php include __DIR__ . '/../partials/fit_detail.php'; ?></div></details>
          </div>
        <?php elseif ($f && in_array($f['status'], ['pending', 'reading'], true)): ?>
          <p class="small muted" style="margin:10px 0 0">Claude will assess this candidate for the role<?= \Eshcol\Ats\App::get()->setting('fit_batch', '0') === '1' ? ', usually within the hour' : ' within a few minutes' ?>.</p>
        <?php elseif ($f && $f['status'] === 'batched'): ?>
          <p class="small muted" style="margin:10px 0 0">Claude is assessing this candidate for the role in a half-price batch; the result usually arrives within the hour.</p>
        <?php elseif ($f && $f['status'] === 'skipped'): $sk = \Eshcol\Ats\App::get()->db->one('SELECT id, title FROM roles WHERE id = ?', [(int) $f['skipped_for']]); ?>
          <form method="post" action="/applications/<?= (int) $a['id'] ?>/fit" class="row" style="margin:10px 0 0;align-items:center;gap:8px"><?= csrf() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
            <span class="small muted">One assessment per client: Claude assesses them for <?= $sk ? e($sk['title']) : 'another role' ?> instead.</span>
            <button class="btn ghost small" type="submit">Assess for this role too</button>
          </form>
        <?php endif; ?>
        <form method="post" action="/applications/<?= $a['id'] ?>/stage" class="row" style="margin:12px 0;align-items:center"><?= csrf() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <label for="st<?= $a['id'] ?>" style="margin:0">Stage</label>
          <select id="st<?= $a['id'] ?>" name="stage_id" data-autosubmit style="max-width:240px">
            <?php foreach ($stages as $s): ?><option value="<?= $s['id'] ?>"<?= selected($a['stage_id'], $s['id']) ?><?= in_array($s['kind'], ['fail', 'hold'], true) ? ' data-closing="1"' . ($s['kind'] === 'hold' ? ' data-hold="1"' : '') : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
          </select><noscript><button class="btn small">Move</button></noscript>
        </form>
        <?php if (array_key_exists('permission', $a) && !($a['is_pool'] ?? false)): $perm = $a['permission']; ?>
          <form method="post" action="/applications/<?= (int) $a['id'] ?>/permission" class="row small" style="gap:8px;align-items:center;margin:0 0 10px"><?= csrf() ?>
            <span><b>Put forward to the client:</b>
              <?= $perm === 'yes' ? '<span class="chip yes">they agreed</span>' : ($perm === 'not_yet' ? '<span class="chip flag">check with them first</span>' : '<span class="muted">not asked yet</span>') ?>
              <?= $a['permission_at'] ? '<span class="muted">(' . e($a['permission_via'] === 'details_form' ? 'details form' : 'on a call') . ', ' . e(nice_date($a['permission_at'])) . ')</span>' : '' ?></span>
            <?php if ($perm !== 'yes'): ?><button class="btn ghost small" type="submit" name="permission" value="yes">They agreed</button><?php endif; ?>
            <?php if ($perm !== 'not_yet'): ?><button class="btn ghost small" type="submit" name="permission" value="not_yet">Not yet</button><?php endif; ?>
          </form>
        <?php endif; ?>
        <?php if (!empty($placements[$a['id']])) { $p = $placements[$a['id']]; include __DIR__ . '/_placement.php'; } ?>
        <?php // Also when you take forward someone Claude called Weak (16 Nov 2026): "Suggest rule changes" learns from your reason.
        $weakFwd = \Eshcol\Ats\Services\Agreement::extrasOn(\Eshcol\Ats\App::get()) && !in_array($a['stage_kind'] ?? '', ['fail', 'hold'], true) && $f && $f['status'] === 'done' && $f['band'] === 'Weak'
            && \Eshcol\Ats\Services\Agreement::isForward(\Eshcol\Ats\App::get(), (int) $a['stage_id']);
        if (in_array($a['stage_kind'] ?? '', ['fail', 'hold'], true) || $weakFwd): $rsn = (string) ($a['stage_reason'] ?? ''); $isHold = $a['stage_kind'] === 'hold'; ?>
          <form method="post" action="/applications/<?= (int) $a['id'] ?>/reason" class="reason-box<?= $rsn === '' ? ' missing' : '' ?>"><?= csrf() ?><input type="hidden" name="back" value="<?= e(($_SERVER['REQUEST_URI'] ?? '') . '') ?>">
            <label for="rs<?= (int) $a['id'] ?>"><?= $weakFwd ? ($rsn === '' ? '<b>Claude called them Weak. Why take them forward?</b> Add a remark: Claude learns from it ("Suggest rule changes")' : '<b>Why taken forward</b>')
              : ($rsn === '' ? ($isHold ? '<b>Kept for a future requirement.</b> Add a note for later: why they are worth keeping' : '<b>Why ' . e(mb_strtolower($a['stage'])) . '?</b> Add a remark for future reference') : ($isHold ? '<b>Note for later</b>' : '<b>Reason</b>')) ?></label>
            <div class="row" style="align-items:center;gap:8px;margin:4px 0 0">
              <input type="text" id="rs<?= (int) $a['id'] ?>" name="reason" value="<?= e($rsn) ?>" maxlength="255" list="stage-reasons" placeholder="<?= $weakFwd ? 'e.g. Strong GST work in practice; the CV undersells it' : 'e.g. Qualification below the requirement: BBM and MBA; minimum is B.Com' ?>" style="flex:1;min-width:0">
              <button class="btn small" type="submit">Save</button>
            </div>
            <?php if ($rsn !== '' && !empty($a['stage_reason_at'])): ?><p class="small muted" style="margin:4px 0 0">Saved <?= e(nice_date($a['stage_reason_at'], true)) ?></p><?php endif; ?>
          </form>
        <?php endif; ?>
        <?php $cv = $clientViews[(int) $a['id']] ?? null; if ($cv): ?>
          <p class="small" style="margin:0 0 10px"><a href="/shortlists/<?= (int) $cv['shortlist_id'] ?>">Sent to the client</a>: <?= $cv['decision'] ? '<span class="chip ' . ['shortlist' => 'yes', 'hold' => 'sand', 'reject' => 'flag'][$cv['decision']] . '">' . e(\Eshcol\Ats\Services\Shortlists::DECISIONS[$cv['decision']]) . '</span>' . ($cv['comment'] ? ' &ldquo;' . e($cv['comment']) . '&rdquo;' : '') : '<span class="muted">no decision yet</span>' ?></p>
        <?php endif; ?>
        <div class="screening" id="<?= $sq && $sq['status'] === 'answered' ? 'screening' : 'screening-' . (int) $a['id'] ?>" style="margin:0 0 14px">
          <?php if ($toReview): ?>
            <p class="small muted" style="margin:0">Their answers are in the review box above.</p>
          <?php elseif ($sq && $sq['status'] === 'answered'): ?>
            <p class="small" style="margin:0 0 6px"><b>Details from the candidate</b> · <?= e(nice_date($sq['answered_at'], true)) ?><?= !empty($sq['reviewed_at']) ? ' · <span class="chip yes" style="text-decoration:none">reviewed ' . e(nice_date($sq['reviewed_at'])) . '</span>' : '' ?></p>
            <?php foreach ($sqFlags as $fl): ?><span class="chip flag">⚑ <?= e($fl) ?></span><?php endforeach; ?>
            <table class="qa"><?php foreach ($sq['answers'] as $ans): ?><tr<?= $ans['flagged'] ? ' class="flagged"' : '' ?>><td><?= e($ans['prompt']) ?></td><td><?= e((string) $ans['answer']) ?></td></tr><?php endforeach; ?></table>
          <?php else: ?>
            <form method="post" action="/applications/<?= (int) $a['id'] ?>/questionnaire" class="actions" style="align-items:center"><?= csrf() ?>
              <span class="small muted"><?= !$sq ? 'Not asked for their details yet.' : (in_array($sq['status'], ['sent', 'opened'], true) ? 'Asked for their details ' . e(nice_date($sq['created_at'])) . ($sq['status'] === 'opened' ? ' (opened)' : '') . ($sq['reminded_at'] ? ', reminded ' . e(nice_date($sq['reminded_at'])) : '') . ($sq['final_at'] ? ', final email ' . e(nice_date($sq['final_at'])) : '') . '.' : ($sq['status'] === 'declined' ? '<b>Said they are no longer interested</b> (' . e(nice_date($sq['declined_at'])) . ').' : 'The link expired unanswered.')) ?></span>
              <?php if (!$c['do_not_contact'] && !$c['removal_requested_at'] && empty($c['unreliable_at']) && $c['email']): ?><button class="btn ghost small" type="submit"><?= $sq ? 'Send a new link' : 'Ask for their details' ?></button><?php endif; ?>
            </form>
          <?php endif; ?>
        </div>
        <?php if ($waNumber && !$c['do_not_contact'] && !$c['removal_requested_at']): ?>
          <details class="wa"><summary class="small">💬 WhatsApp about this role</summary>
            <ul class="wa-list"><?php foreach ($wa->messages($c, $a, (string) $user['name']) as $k => $m): if ($k === 'hello') { continue; } ?>
              <li><a href="/candidates/<?= (int) $c['id'] ?>/whatsapp?app=<?= (int) $a['id'] ?>&amp;t=<?= e($k) ?>" target="_blank" rel="noopener"><?= e($m['label']) ?></a><br><span class="small muted"><?= e(mb_strimwidth($m['text'], 0, 140, '…')) ?></span></li>
            <?php endforeach; ?></ul>
            <p class="hint" style="margin:0">WhatsApp opens with the message typed; check it and press send. It's noted on this candidate.</p>
          </details>
        <?php endif; ?>
        <div id="interviews"><?php include __DIR__ . '/_interviews.php'; ?></div>
        <?php if (empty($a['is_pool'])) { include __DIR__ . '/_decide.php'; } ?>
        <?php include __DIR__ . '/../partials/scorecard.php'; ?>
        <details style="margin-top:12px"><summary class="small">Set your own score</summary>
          <form method="post" action="/applications/<?= $a['id'] ?>/override" class="row" style="margin-top:10px;align-items:flex-end"><?= csrf() ?>
            <div class="field" style="flex:0 1 110px;margin:0"><label for="ov<?= $a['id'] ?>">Score</label><input type="number" min="0" max="100" id="ov<?= $a['id'] ?>" name="override_score" value="<?= e($a['override_score']) ?>"></div>
            <div class="field" style="margin:0"><label for="or<?= $a['id'] ?>">Why</label><input type="text" id="or<?= $a['id'] ?>" name="override_reason" value="<?= e($a['override_reason']) ?>" placeholder="e.g. Spoke to her: has payroll experience not on the CV"></div>
            <div><button class="btn small" type="submit">Save</button></div>
          </form>
          <p class="hint">Leave the score empty and save to go back to the calculated score.</p>
        </details>
      </div>
    <?php endforeach; ?>

    <?php if ($openRoles): ?>
      <form class="card" method="post" action="/candidates/<?= $c['id'] ?>/roles">
        <?= csrf() ?>
        <div class="row" style="align-items:flex-end">
          <div class="field" style="margin:0"><label for="role">Add to another open role</label>
            <select id="role" name="role_id" required><option value="">Choose a role…</option>
              <?php foreach ($openRoles as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['title']) ?> · <?= e($r['client']) ?></option><?php endforeach; ?>
            </select></div>
          <div><button class="btn ghost" type="submit">Add and score</button></div>
        </div>
      </form>
    <?php endif; ?>

    </div>
    <div class="tab-panel" data-panel="cv" id="tab-cv">
    <h2><?= icon('file') ?> CV</h2>
    <?php foreach ($files as $i => $f): $canFrame = in_array($f['ext'], ['pdf', 'jpg', 'jpeg', 'png'], true); ?>
      <div class="card"<?= $i === 0 ? ' id="cv"' : '' ?>>
        <div class="card-head">
          <span style="min-width:0"><span class="doc-ico" style="width:30px;height:30px;vertical-align:middle;margin-right:8px"><?= e(strtoupper($f['ext'])) ?></span><b><?= e($f['original_name']) ?></b> <span class="small muted"><?= e(number_format($f['size_bytes'] / 1024)) ?> KB · <?= e(nice_date($f['created_at'])) ?><?= $i === 0 && count($files) > 1 ? ' · latest' : '' ?></span></span>
          <span class="actions"><?php // Which CV goes to clients when there are several (B13, 15 Nov 2026): the chosen one, else the newest.
            $toClients = (int) ($c['preferred_cv_id'] ?? 0) ?: (int) ($files[0]['id'] ?? 0);
            if (count($files) > 1): if ((int) $f['id'] === $toClients): ?><span class="chip yes" style="text-decoration:none" title="Shortlists and the client portal use this CV">Goes to clients</span><?php if (!empty($c['preferred_cv_id']) && !\Eshcol\Ats\Access::restricted($user ?? null)): ?><form method="post" action="/candidates/<?= (int) $c['id'] ?>/preferred-cv" style="display:inline"><?= csrf() ?><input type="hidden" name="cv_id" value="0"><button class="btn ghost small" type="submit">Use the newest instead</button></form><?php endif; ?>
            <?php elseif (!\Eshcol\Ats\Access::restricted($user ?? null)): ?><form method="post" action="/candidates/<?= (int) $c['id'] ?>/preferred-cv" style="display:inline"><?= csrf() ?><input type="hidden" name="cv_id" value="<?= (int) $f['id'] ?>"><button class="btn ghost small" type="submit">Send this one to clients</button></form><?php endif; endif; ?>
            <?php // Shown by the app itself, with Enlarge (16 Nov 2026): PDF, Word (.docx) and photos or scans.
            $cvSrc = $f['ext'] === 'docx' ? '/cv/' . (int) $f['id'] : '/cv/' . (int) $f['id'] . '?view=1';
            if ($canFrame || $f['ext'] === 'docx'): ?><button type="button" class="btn small" data-cv-enlarge data-src="<?= e($cvSrc) ?>" data-ext="<?= e($f['ext']) ?>" data-name="<?= e($nm) ?>" data-download="/cv/<?= (int) $f['id'] ?>"><?= icon('external', 'sm') ?>Enlarge</button><?php endif; ?>
            <a class="btn ghost small" href="/cv/<?= $f['id'] ?>"><?= icon('download', 'sm') ?>Download</a></span>
        </div>
        <?php if ($i === 0 && ($canFrame || $f['ext'] === 'docx')): ?>
          <div class="cv-view" data-src="<?= e($cvSrc) ?>" data-ext="<?= e($f['ext']) ?>" data-name="<?= e($nm) ?>"><p class="small muted cv-msg">Loading the CV…</p></div>
          <?php if ($f['text']): ?><details style="margin-top:10px"><summary class="small">Text the app read<?= $f['text_source'] === 'browser_ocr' ? ' (from the scan)' : '' ?></summary><pre class="cvtext" style="margin-top:8px"><?= e($f['text']) ?></pre></details><?php endif; ?>
        <?php elseif ($f['text']): ?>
          <?php if ($i === 0): ?><pre class="cvtext" style="max-height:760px"><?= e($f['text']) ?></pre>
          <?php else: ?><details><summary class="small">Text the app read</summary><pre class="cvtext" style="margin-top:8px"><?= e($f['text']) ?></pre></details><?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($files): ?><script type="module" src="<?= asset('cv-viewer.js') ?>"></script><?php endif; ?>
    </div>
  </div>

  <aside>
    <?php if ($catMode !== 'off' || $category) { include __DIR__ . '/_category.php'; } ?>
    <div class="card accent">
      <h3>Details</h3>
      <?php foreach ($facts as $label => [$val, $key]): ?>
        <p style="margin:0 0 10px"><span class="small muted"><?= e($label) ?></span><br><?= $val ?><?= $key !== 'channel' && ($c[$key] ?? null) !== null && $c[$key] !== '' ? $srcLabel($key) : '' ?></p>
      <?php endforeach; ?>
      <?php if (!\Eshcol\Ats\Access::restricted($user ?? null)): ?>      <p class="small" style="margin:6px 0 0"><a href="/candidates/<?= $c['id'] ?>/edit">Edit and confirm</a></p><?php endif; ?>
    </div>
    <?php $chipLink = !\Eshcol\Ats\Access::restricted(\Eshcol\Ats\Auth::user()); ?>
    <?php if ($titles): ?>
      <div class="card"><h3>Positions in the CV</h3><?php foreach ($titles as $t): ?><?= $chipLink ? '<a class="chip" href="/candidates?position=' . urlencode($t) . '">' . e($t) . '</a>' : '<span class="chip">' . e($t) . '</span>' ?><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!\Eshcol\Ats\Access::restricted($user ?? null)): ?>
    <form method="post" action="/candidates/<?= $c['id'] ?>/route" class="card sand" style="padding:14px 18px"><?= csrf() ?>
      <p class="small" style="margin:0 0 8px">Check every open role for one this candidate's experience and skills fit.</p>
      <button class="btn ghost small" type="submit">Check other open roles</button>
    </form>
    <?php endif; ?>
    <?php if ($skills): ?>
      <div class="card"><h3>Skills found</h3><?php foreach ($skills as $s): ?><?= $chipLink ? '<a class="chip" href="/candidates?skill=' . urlencode($s) . '">' . e($s) . '</a>' : '<span class="chip">' . e($s) . '</span>' ?><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (array_key_exists('email_status', $c)): $es = (string) $c['email_status']; $cn = $consents[0] ?? null; ?>
      <div class="card" id="contact-consent">
        <h3>Email and consent</h3>
        <p class="small" style="margin:0 0 6px"><?php if ($es === 'ok'): ?><span class="chip yes">Email working</span>
          <?php else: ?><span class="chip <?= in_array($es, \Eshcol\Ats\Services\EmailHealth::STOP, true) ? 'no' : 'flag' ?>"><?= e(\Eshcol\Ats\Services\EmailHealth::LABELS[$es] ?? $es) ?></span>
            <span class="muted"><?= e(nice_date($c['email_status_at'], true)) ?><?= $c['email_status_note'] ? ': ' . e($c['email_status_note']) : '' ?></span>
            <?php if (in_array($es, \Eshcol\Ats\Services\EmailHealth::STOP, true)): ?><br>No email goes to this address.<?php endif; ?><?php endif; ?></p>
        <?php if ($es !== 'ok'): ?><form method="post" action="/candidates/<?= (int) $c['id'] ?>/email-ok" style="margin:0 0 8px"><?= csrf() ?><button class="btn ghost small" type="submit" data-confirm-click="Email this address again? Only do this if they gave you a working address or asked to hear from you.">The address works again</button></form><?php endif; ?>
        <p class="small" style="margin:0"><b>Keeping their details:</b> <?= $cn ? e(\Eshcol\Ats\Services\Consents::LABELS[$cn['status']]) . ' <span class="muted">(' . e(\Eshcol\Ats\Services\Consents::VIA[$cn['via']] ?? $cn['via']) . ', ' . e(nice_date($cn['created_at'])) . ')</span>' : '<span class="muted">nothing recorded (added before 14 Nov 2026, or from your own files)</span>' ?></p>
        <?php if (!empty($cvRequest)): $cr = $cvRequest; ?>
          <p class="small" style="margin:6px 0 0"><b>"Share your updated CV":</b> <?= e(match (true) {
            $cr['answer'] === 'share' => 'shared' . ($cr['cv_at'] ? ' an updated CV' : '') . ' on ' . nice_date($cr['answered_at']),
            $cr['answer'] === 'keep' => 'asked to be kept for the future on ' . nice_date($cr['answered_at']) . ($cr['cv_at'] ? ', with an updated CV' : ''),
            $cr['answer'] === 'remove' => 'asked to be removed on ' . nice_date($cr['answered_at']),
            $cr['status'] === 'no_answer' => 'no answer: don\'t contact them until they apply again',
            $cr['status'] === 'sent' => 'sent ' . nice_date($cr['sent_at']) . ($cr['reminders'] ? ', ' . $cr['reminders'] . ' reminder(s)' : '') . ($cr['opened_at'] ? ', page opened' : ''),
            $cr['status'] === 'queued' => 'waiting to be sent (group ' . $cr['grp'] . ')',
            $cr['status'] === 'skipped' => 'not sent: ' . $cr['skip_reason'],
            default => 'cancelled' . ($cr['skip_reason'] ? ': ' . $cr['skip_reason'] : ''),
          }) ?></p>
        <?php endif; ?>
        <?php if (count($consents) > 1): ?><details class="small"><summary>History (<?= count($consents) ?>)</summary><ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($consents as $h): ?><li><?= e(nice_date($h['created_at'])) ?>: <?= e(\Eshcol\Ats\Services\Consents::LABELS[$h['status']]) ?> (<?= e(\Eshcol\Ats\Services\Consents::VIA[$h['via']] ?? $h['via']) ?>)</li><?php endforeach; ?></ul></details><?php endif; ?>
        <?php if (($user['role'] ?? '') === 'owner'): ?><p class="small" style="margin:8px 0 0"><a href="/candidates/<?= (int) $c['id'] ?>/data">Download their data</a> <span class="muted">for anyone who asks what you hold</span></p><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($c['keep_note']): ?><div class="card sand"><h3>Keep in mind</h3><p style="margin:0"><?= e($c['keep_note']) ?></p></div><?php endif; ?>
    <div class="card" id="notes">
      <h3><?= icon('chat') ?> Notes &amp; activity</h3>
      <form method="post" action="/candidates/<?= $c['id'] ?>/note"><?= csrf() ?>
        <textarea name="body" rows="3" placeholder="Call notes, client feedback, anything worth remembering" aria-label="New note"></textarea>
        <p style="margin:8px 0 16px"><button class="btn small" type="submit"><?= icon('plus', 'sm') ?>Add note</button></p>
      </form>
      <?php if (!$timeline): ?><p class="small muted" style="margin:0">Nothing yet.</p><?php endif; ?>
      <ul class="timeline">
        <?php foreach ($timeline as $ev): ?>
          <li><span class="tdot"><?= icon($ev['icon']) ?></span>
            <?= !empty($ev['url']) ? '<a href="' . e($ev['url']) . '">' . e($ev['text']) . '</a>' : '<b style="font-weight:600">' . e($ev['text']) . '</b>' ?>
            <?php if ($ev['sub'] !== ''): ?><br><span class="small muted"><?= e($ev['sub']) ?></span><?php endif; ?>
            <?php if (!empty($ev['body'])): ?><div class="tl-body"><?= e($ev['body']) ?></div><?php endif; ?>
            <br><span class="when"><?= e(nice_date($ev['at'], true)) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php if (($user['role'] ?? '') === 'owner'): ?>
      <details class="card" id="delete">
        <summary><b>Delete this candidate</b></summary>
        <form method="post" action="/candidates/<?= $c['id'] ?>/delete" style="margin-top:12px"><?= csrf() ?>
          <p class="small">Removes them, every CV file and their off-site backup copies, for good. This cannot be undone.</p>
          <div class="field"><label for="del-reason">Why</label>
            <select id="del-reason" name="reason">
              <option value="requested">They asked to be removed</option>
              <option value="manual">Other reason</option>
            </select>
            <p class="hint">"They asked" keeps a one-way code of their email and phone (not the details themselves), so if they apply again you'll be told.</p></div>
          <div class="field"><label for="del-confirm">Type DELETE to confirm</label><input type="text" id="del-confirm" name="confirm" autocomplete="off"></div>
          <button class="btn" type="submit" style="background:var(--red);border-color:var(--red)">Delete permanently</button>
        </form>
      </details>
    <?php endif; ?>
  </aside>
</div>
