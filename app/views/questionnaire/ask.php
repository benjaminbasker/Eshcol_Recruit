<?php
/** @var array $people @var int $sentToday @var int $cap */
$ready = array_filter($people, fn ($p) => !$p['candidate']['blocked'] && !$p['asked']);
$ticked = array_filter($ready, fn ($p) => $p['relevant']);
$status = ['sent' => 'Asked, no answer yet', 'opened' => 'Asked, opened the page', 'answered' => 'Answered', 'declined' => 'No longer interested'];
?>
<?php
/** @var array $responses */
$rc = $responses['counts'];
$rr = $responses['rows'];
$answered = array_values(array_filter($rr, fn ($r) => $r['status'] === 'answered'));
$waiting = array_values(array_filter($rr, fn ($r) => in_array($r['status'], ['sent', 'opened', 'expired'], true)));
$declinedRows = array_values(array_filter($rr, fn ($r) => $r['status'] === 'declined'));
$rate = $rc['asked'] ? (int) round(100 * $rc['answered'] / $rc['asked']) : 0;
$lpa = fn ($v) => $v === null ? '–' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
// The same key details on every list (16 Nov 2026).
$askKf = (new \Eshcol\Ats\Services\KeyFacts(\Eshcol\Ats\App::get()))->forCandidates(array_column(array_merge($answered, $waiting), 'candidate_id'));
?>
<div class="page-head">
  <div><p class="eyebrow"><a href="/emails">Emails</a></p><h1>Candidate details</h1>
    <p class="muted">Who has answered the "complete your application" request, and what they told us. Requests go with every thank-you email and from the list at the bottom of this page; a gentle reminder follows after 48 hours and a final "Are you still interested?" 48 hours later.</p></div>
  <a class="btn ghost" href="/cv-requests"><?= icon('mail') ?>Share your updated CV</a>
</div>
<?php if (!empty($dash['people'])) { include __DIR__ . '/_glance.php'; } ?>
<?php if ($rc['asked']): ?>

  <h2><?= icon('check') ?> Answers in (<?= count($answered) ?>)</h2>
  <?php if (!$answered): ?>
    <div class="card empty">No answers yet.</div>
  <?php else: ?>
    <p class="small muted" style="margin:-4px 0 8px">Salaries in lakhs per annum. Within budget first; ⚑ marks something to look at.</p>
    <div class="table-wrap"><table class="list compact">
      <thead><tr><th>Candidate</th><th>Role</th><th>Current</th><th>Lowest</th><th>Hoping</th><th>Can join</th><th>Lives in</th><th>Can work there</th><th>Answered</th><th>Your review</th></tr></thead>
      <tbody><?php prime_stages(array_merge(array_column($answered, 'candidate_id'), array_column($waiting ?? [], 'candidate_id'))); foreach ($answered as $r): $over = $r['ctc_max_lpa'] !== null && $r['ctc_min_lpa'] !== null && (float) $r['ctc_min_lpa'] > (float) $r['ctc_max_lpa']; ?>
        <tr>
          <td><a href="/candidates/<?= (int) $r['candidate_id'] ?>"><b><?= e($r['full_name'] ?: 'Candidate') ?></b></a> <span class="small muted"><?= e(ref_candidate((int) $r['candidate_id'])) ?></span> <?= stage_tags($r['candidate_id']) ?>
            <?php $ak = $askKf[(int) $r['candidate_id']] ?? []; if ($ak): ?><br><?= \Eshcol\Ats\Services\KeyFacts::line($ak, ['place', 'ctc', 'notice', 'responded']) ?> <?= \Eshcol\Ats\Services\KeyFacts::cvButton($ak) ?><?php endif; ?>
            <?php foreach ($r['flags'] as $fl): ?><br><span class="chip flag">⚑ <?= e($fl) ?></span><?php endforeach; ?>
            <?php if (in_array($r['stage_kind'], ['fail', 'hold'], true)): ?><br><span class="chip <?= $r['stage_kind'] === 'hold' ? 'keep' : 'reason' ?>"><?= e($r['stage_name']) ?>: <?= e($r['stage_reason'] ?: ($r['stage_kind'] === 'hold' ? 'no note yet' : 'no reason given')) ?></span><?php endif; ?></td>
          <td class="small"><?= e($r['title']) ?><?= $r['ctc_max_lpa'] !== null ? '<br><span class="muted">budget up to ' . e($lpa($r['ctc_max_lpa'])) . '</span>' : '' ?></td>
          <td class="nowrap"><?= e($lpa($r['ctc_current_lpa'])) ?></td>
          <td class="nowrap"><b<?= $over ? ' class="text-bad"' : '' ?>><?= e($lpa($r['ctc_min_lpa'])) ?></b></td>
          <td class="nowrap"><?= e($lpa($r['ctc_expected_lpa'])) ?></td>
          <td class="small"><?= e((string) ($r['notice_label'] ?? '–')) ?></td>
          <td class="small"><?= e(trim(($r['locality'] ? $r['locality'] . ', ' : '') . ($r['location'] ?? ''), ', ') ?: '–') ?></td>
          <td class="small"><?= $r['relocate'] === 'No' ? '<span class="chip flag">No</span>' : e($r['relocate'] ?? '–') ?></td>
          <td class="small nowrap"><?= e(nice_date($r['answered_at'], true)) ?></td>
          <td class="nowrap"><?php if ($r['stage_kind'] !== 'active' || !empty($r['reviewed_at'])): ?><span class="chip yes" style="text-decoration:none">Reviewed</span><?php else: ?><a class="btn small" href="<?= e(\Eshcol\Ats\Controllers\CandidateController::reviewUrl($r)) ?>#review-<?= (int) $r['application_id'] ?>">Review</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($declinedRows): ?>
    <h2 style="margin-top:22px"><?= icon('x') ?> No longer interested (<?= count($declinedRows) ?>)</h2>
    <div class="card small"><?php foreach ($declinedRows as $r): ?><div><a href="/candidates/<?= (int) $r['candidate_id'] ?>"><?= e($r['full_name'] ?: 'Candidate') ?></a> <span class="muted"><?= e(ref_candidate((int) $r['candidate_id'])) ?> · <?= e($r['title']) ?> · <?= e(nice_date($r['declined_at'])) ?></span></div><?php endforeach; ?></div>
  <?php endif; ?>

  <details style="margin-top:22px"<?= $waiting && !$answered ? ' open' : '' ?>>
    <summary><b>No answer yet (<?= count($waiting) ?>)</b> <span class="small muted">asked, opened, reminded</span></summary>
    <div class="table-wrap" style="margin-top:8px"><table class="list compact">
      <thead><tr><th>Candidate</th><th>Role</th><th>Asked</th><th>Progress</th></tr></thead>
      <tbody><?php foreach ($waiting as $r): ?>
        <tr><td><a href="/candidates/<?= (int) $r['candidate_id'] ?>"><?= e($r['full_name'] ?: 'Candidate') ?></a> <span class="small muted"><?= e(ref_candidate((int) $r['candidate_id'])) ?></span> <?= stage_tags($r['candidate_id']) ?>
          <?php $ak = $askKf[(int) $r['candidate_id']] ?? []; if ($ak): ?><br><?= \Eshcol\Ats\Services\KeyFacts::line($ak, ['responded']) ?> <?= \Eshcol\Ats\Services\KeyFacts::cvButton($ak) ?><?php endif; ?></td>
          <td class="small"><?= e($r['title']) ?></td>
          <td class="small nowrap"><?= e(nice_date($r['created_at'])) ?></td>
          <td class="small"><?= $r['status'] === 'expired' ? 'Link expired' : ($r['final_at'] ? 'Final email sent ' . e(nice_date($r['final_at'])) : ($r['reminded_at'] ? 'Reminded ' . e(nice_date($r['reminded_at'])) : ($r['status'] === 'opened' ? '<b>Opened the page</b>' : 'Waiting'))) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </details>
<?php endif; ?>

<h2 style="margin-top:26px"><?= icon('send') ?> Ask more people</h2>
<p class="small muted" style="margin:-4px 0 10px">One email per person, covering every open role they are on. It asks for their current salary, the lowest salary they would accept and how soon they could join, and lets them check the details we read from their CV.</p>
<?php if (!$people): ?>
  <div class="card empty">Nobody is on an open client role yet.</div>
<?php else: ?>
  <div class="card sand small">Ticked: the <?= count($ticked) ?> people the free check found relevant to at least one of their roles. The email names only the roles they are relevant to.
    People already asked, marked do not contact, or with no email address can't be ticked. <?= (int) $sentToday ?> of <?= (int) $cap ?> emails sent today.</div>
  <form method="post" action="/details-requests"><?= csrf() ?>
  <div class="table-wrap"><table class="list">
    <thead><tr><th style="width:34px"><input type="checkbox" data-all aria-label="Select all"></th><th>Candidate</th><th>Roles</th><th>Status</th></tr></thead>
    <tbody><?php prime_stages(array_map(fn ($x) => $x['candidate']['id'], $people)); foreach ($people as $p): $c = $p['candidate']; $can = !$c['blocked'] && !$p['asked']; ?>
      <tr<?= $can ? '' : ' class="muted"' ?>>
        <td><?php if ($can): ?><input type="checkbox" name="cand[]" value="<?= (int) $c['id'] ?>" aria-label="Ask <?= e($c['full_name'] ?: 'this candidate') ?>"<?= $p['relevant'] ? ' checked' : '' ?>><?php endif; ?></td>
        <td><a href="/candidates/<?= (int) $c['id'] ?>"><b><?= e($c['full_name'] ?: 'Candidate') ?></b></a> <span class="small muted"><?= e(ref_candidate((int) $c['id'])) ?></span> <?= stage_tags($c['id']) ?><br><span class="small muted"><?= e($c['email'] ?: 'no email address') ?></span></td>
        <td class="small"><?php foreach ($p['apps'] as $a): ?><div><?= e(role_title_mode($a['title'], $a['work_mode'])) ?> <?= $a['relevant'] ? '<span class="chip yes">relevant</span>' : '<span class="chip">not relevant</span>' ?></div><?php endforeach; ?></td>
        <td class="small"><?= $c['blocked'] ? 'Can\'t be contacted' : e($status[(string) $p['asked']] ?? ($p['asked'] ? ucfirst($p['asked']) : 'Not asked yet')) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($ready): ?>
    <p class="actions" style="margin-top:12px"><button class="btn" type="submit" data-confirm-click="Email the ticked candidates now, asking for their details?">Send to the ticked candidates</button></p>
  <?php endif; ?>
  </form>
<?php endif; ?>
<script type="module" src="<?= asset('cv-viewer.js') ?>"></script>
