<?php
$by = [];
foreach ($criteria as $c) { $by[$c['kind']][] = $c['value']; }
$total = array_sum($counts);
$all = array_merge($apps, $below);
$view = $_GET['view'] ?? ($stageFilter ? 'list' : (count($all) > 250 ? 'list' : 'board'));
$view = in_array($view, ['board', 'list'], true) ? $view : 'board';
$isPool = (bool) $role['is_pool'];
$notice = fn ($d) => $d === null ? '?' : ((int) $d === 0 ? 'immediate' : (int) $d . 'd');
$activeIv = count(array_filter($ivs, fn ($i) => in_array($i['status'], ['choosing', 'booked'], true)));
$withClient = count($clientDecisions);
$threshold = (int) $role['score_threshold'];
// Shortlisted (Shortlisted stage or later): a green band on their row and card (16 Nov 2026).
$isShort = fn (array $a): bool => \Eshcol\Ats\Services\Shortlists::isShortlisted(\Eshcol\Ats\App::get(), (int) $a['stage_id']);
// The same key details on every list (16 Nov 2026), for everyone on the position, in one go.
$kfMap = (new \Eshcol\Ats\Services\KeyFacts(\Eshcol\Ats\App::get()))->forCandidates(array_column($all, 'candidate_id'));
$kfOf = fn (array $a): array => $kfMap[(int) $a['candidate_id']] ?? [];
// Responded / Yet to respond, ranked (17 Nov 2026): "responded" = answered the details request or wrote back to us;
// "yet to respond" = asked or written to, no answer yet. Ranked by Claude's assessment, else the keyword score.
// Closed stages (Not suitable, Withdrawn, No response, Future requirement) are left out and counted.
$fitRowsAll = $fit['rows'];
$rankScore = fn (array $a): ?int => \Eshcol\Ats\Services\KeyFacts::rankScore($a, $fit['rows']);
['responded' => $responded, 'waiting' => $waitingList, 'closed' => $closedOut] = \Eshcol\Ats\Services\KeyFacts::replyLists($all, $kfMap, $fit['rows']);
// The main tab: Responded opens first when nothing else was asked for and someone has responded.
$shortN = $isPool ? 0 : count((new \Eshcol\Ats\Services\Shortlists(\Eshcol\Ats\App::get()))->shortlistedOn((int) $role['id']));
$foundN = count($found['fits'] ?? []);
$who = in_array($_GET['who'] ?? '', ['responded', 'waiting', 'short', 'found'], true) ? $_GET['who']
    : (!$isPool && !isset($_GET['view']) && !$stageFilter && $responded ? 'responded' : '');
if ($who) { $view = 'list'; }
$qChip = function (?array $q, int $candidateId): string {
    if (!$q) { return ''; }
    $flags = json_decode((string) $q['flags'], true) ?: [];
    if ($q['status'] === 'answered') {
        return '<a href="/candidates/' . $candidateId . '#screening" class="chip ' . ($flags ? 'flag' : 'yes') . '" title="' . e(implode('; ', $flags)) . '">' . ($flags ? '⚑ ' . e(implode('; ', $flags)) : '✓ Answers in') . '</a>';
    }
    if (in_array($q['status'], ['sent', 'opened'], true)) {
        return '<span class="chip">✉ Details ' . ($q['status'] === 'opened' ? 'requested, opened' : 'requested') . '</span>';
    }
    if ($q['status'] === 'declined') {
        return '<span class="chip flag">No longer interested</span>';
    }
    return $q['status'] === 'expired' ? '<span class="chip">Details not given</span>' : '';
};
$fitChip = function (array $a) use ($fit, $sentToClient): string {
    // Already sent to this client (any position): a badge, so nobody sends the same person twice by mistake.
    $sentAt = ($sentToClient ?? [])[(int) $a['candidate_id']] ?? null;
    $sentChip = $sentAt ? '<span class="chip sand" style="text-decoration:none" title="Already sent to this client">Sent to client ' . e(date('j M', strtotime((string) $sentAt))) . '</span> ' : '';
    $f = $fit['rows'][(int) $a['id']] ?? null;
    if (!$f) { return $sentChip; }
    if ($f['status'] === 'skipped' && !empty($f['skipped_for'])) {
        // One assessment per client: assessed on the client's other position, which fits them better.
        $o = \Eshcol\Ats\App::get()->db->one("SELECT f.score, f.band, f.status, r.title FROM roles r LEFT JOIN applications a ON a.role_id = r.id AND a.candidate_id = ?
            LEFT JOIN fit_assessments f ON f.application_id = a.id WHERE r.id = ?", [$a['candidate_id'], $f['skipped_for']]);
        if ($o) {
            return $sentChip . '<span class="chip ' . ($o['status'] === 'done' ? (['Strong' => 'yes', 'Possible' => 'sand', 'Weak' => 'no'][$o['band']] ?? '') : '') . '" style="text-decoration:none" title="One assessment per client: assessed for ' . e($o['title']) . '">'
                . ($o['status'] === 'done' ? 'Claude ' . (int) $o['score'] . ' · ' . e($o['band']) . ' (for ' . e($o['title']) . ')' : 'Claude: assessing for ' . e($o['title'])) . '</span>';
        }
    }
    // Clear processing states (audit UI 7.1): queued, assessing (with when it went to Claude), failed (with why), role changed.
    if ($f['status'] !== 'done') {
        $at = $f['updated_at'] ? date('j M, g:i a', strtotime((string) $f['updated_at'])) : '';
        return $sentChip . match ($f['status']) {
            'batched' => '<span class="chip state-busy" title="Sent to Claude in a half-price batch ' . e($at) . '; results usually within the hour">Claude: assessing (sent ' . e($at) . ')</span>',
            'reading' => '<span class="chip state-busy" title="Claude is assessing now">Claude: assessing…</span>',
            'failed' => '<span class="chip no" style="text-decoration:none" title="' . e((string) ($f['error'] ?? 'Unknown reason')) . '">Claude: failed</span>',
            default => '<span class="chip" title="In the queue for the next half-price batch">Claude: queued</span>',
        };
    }
    $staleChip = !empty($fit['version']) && !empty($f['role_version']) && $f['role_version'] !== $fit['version']
        ? ' <span class="chip sand" title="The position\'s requirements changed after this assessment: press Re-assess everyone on the Claude tab">role changed</span>' : '';
    return $sentChip . $staleChip . '<span class="chip ' . (['Strong' => 'yes', 'Possible' => 'sand', 'Weak' => 'no'][$f['band']] ?? '') . '" style="text-decoration:none" title="Assessed by Claude">Claude ' . (int) $f['score'] . ' · ' . e($f['band']) . '</span>';
};
$row = function (array $a, ?int $rank = null) use ($stages, $role, $qs, $clientDecisions, $notice, $qChip, $fitChip, $isShort, $kfOf, $rankScore, $fitRowsAll): string {
    // In a ranked list the score shown is the one it is ranked by (Claude's, else the keyword score).
    $sc = $rank !== null ? $rankScore($a) : ($a['eff_score'] !== null ? (int) $a['eff_score'] : null);
    $cd = $clientDecisions[(int) $a['id']] ?? null;
    $q = $qs[(int) $a['id']] ?? null;
    $nm = $a['full_name'] ?: 'Name not found';
    ob_start(); ?>
    <tr<?= $isShort($a) ? ' class="short" title="Shortlisted"' : '' ?>>
      <td style="width:64px"><?php if ($rank !== null): ?><span class="rank-n" title="Rank by Claude's assessment">#<?= $rank ?></span><br><?php endif; ?><span class="score <?= score_class($sc, (int) $role['score_threshold']) ?>"><?= $sc ?? '–' ?></span><?php if ($rank !== null && !\Eshcol\Ats\Services\KeyFacts::byClaude($a, $fitRowsAll)): ?><span class="kw-tag" title="Claude hasn't assessed them for this position: keyword score">keyword</span><?php endif; ?>
        <?php if ($a['override_score'] !== null): ?><br><span class="small muted" title="<?= e($a['override_reason']) ?>">set by you</span><?php endif; ?></td>
      <td>
        <div style="display:flex;gap:10px;align-items:flex-start">
          <span class="avatar sm" style="--h:<?= avatar_hue($nm) ?>;margin-top:2px"><?= e(initials($nm)) ?></span>
          <div style="min-width:0">
            <a href="/candidates/<?= $a['candidate_id'] ?>?role=<?= $role['id'] ?>"><b><?= e($nm) ?></b></a> <span class="ref"><?= ref_candidate($a['candidate_id']) ?></span> <?= \Eshcol\Ats\Services\KeyFacts::stageChip((string) $a['stage'], (string) ($a['stage_kind'] ?? '')) ?>
            <?php if ($a['text_status'] === 'needs_ocr'): ?> <span class="confirm">scan</span><?php endif; ?>
            <?php if ($a['do_not_contact']): ?> <span class="badge cancelled">do not contact</span><?php endif; ?>
            <?php if (!empty($a['unreliable_at'])): ?> <span class="badge cancelled" title="<?= e(\Eshcol\Ats\Services\Reliability::describe($a)) ?>">unreliable</span><?php endif; ?>
            <div><?= $kfOf($a) ? \Eshcol\Ats\Services\KeyFacts::line($kfOf($a), ['responded']) . ' ' . \Eshcol\Ats\Services\KeyFacts::cvButton($kfOf($a)) : '' ?></div>
            <div style="margin-top:4px">
              <?= $fitChip($a) ?>
              <?php if ($a['fit_note']): ?><span class="chip <?= $a['source'] === 'routed' ? 'sand' : 'flag' ?>"><?= $a['source'] === 'routed' ? '↪ ' : '⚠ ' ?><?= e($a['fit_note']) ?></span><?php endif; ?>
              <?php if ($cd): ?><span class="chip <?= ['shortlist' => 'yes', 'hold' => 'sand', 'reject' => 'flag'][$cd] ?>">Client: <?= e(\Eshcol\Ats\Services\Shortlists::DECISIONS[$cd]) ?></span><?php endif; ?>
              <?= $qChip($q, (int) $a['candidate_id']) ?>
              <?php if (in_array($a['stage_kind'] ?? '', ['fail', 'hold'], true)): ?><span class="chip <?= $a['stage_kind'] === 'hold' ? 'keep' : 'reason' ?>" title="<?= e($a['stage']) ?>"><?= e($a['stage']) ?>: <?= e($a['stage_reason'] ?: ($a['stage_kind'] === 'hold' ? 'no note yet' : 'no reason given')) ?></span><?php endif; ?>
            </div>
            <details style="margin-top:4px"><summary class="small">Why this score</summary>
              <div style="padding-top:8px" data-why="/applications/<?= (int) $a['id'] ?>/why?kind=score"><span class="small muted">Loading…</span></div>
            </details>
          </div>
        </div>
      </td>
      <td style="width:210px">
        <form method="post" action="/applications/<?= $a['id'] ?>/stage"><?= csrf() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <select name="stage_id" data-autosubmit aria-label="Stage">
            <?php foreach ($stages as $s): ?><option value="<?= $s['id'] ?>"<?= selected($a['stage_id'], $s['id']) ?><?= in_array($s['kind'], ['fail', 'hold'], true) ? ' data-closing="1"' . ($s['kind'] === 'hold' ? ' data-hold="1"' : '') : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
          </select>
          <noscript><button class="btn small">Move</button></noscript>
        </form>
        <span class="small muted"><?= e(ucfirst($a['source'])) ?> · <?= e(nice_date($a['applied_at'])) ?></span>
      </td>
    </tr>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-head">
  <div style="display:flex;gap:16px;align-items:flex-start">
    <?= $isPool ? '' : client_logo($role, 'lg') ?>
    <div>
    <?php $contractor = \Eshcol\Ats\Access::restricted($user ?? \Eshcol\Ats\Auth::user()); ?>
    <p class="eyebrow"><a href="/roles">Roles</a> › <?= ($role['client_id'] ?? null) && !$contractor ? '<a href="/clients/' . (int) $role['client_id'] . '">' . e(\Eshcol\Ats\Services\Engagement::clientLabel(['name' => $role['client'], 'is_self' => $role['is_self'] ?? 0])) . '</a>' : e($role['client']) ?> · <?= e($role['code']) ?><?php $engT = \Eshcol\Ats\Services\Engagement::of($role); if (!$isPool && $engT !== 'placement'): ?> · <span class="chip <?= $engT === 'inhouse' ? 'yes' : 'sand' ?>" style="text-decoration:none"><?= $engT === 'inhouse' ? 'Our own team' : 'Staffing: on Eshcol\x27s payroll' . (!empty($role['contract_months']) ? ', ' . (int) $role['contract_months'] . ' months' : '') ?></span><?php endif; ?></p>
    <h1><?= e($role['title']) ?> <span class="badge <?= e($role['status']) ?>" style="vertical-align:middle"><?= e($isPool ? 'talent pool' : str_replace('_', ' ', $role['status'])) ?></span></h1>
    <div class="facts-inline" style="margin-top:6px">
      <span><?= icon('pin', 'sm') ?><?= e($role['location'] ?: 'Location not set') ?><?= isset(WORK_MODES[$role['work_mode'] ?? '']) ? ' · ' . e(WORK_MODES[$role['work_mode']]) : '' ?></span>
      <?php if (!$isPool): ?><span><?= icon('users', 'sm') ?><?= (int) $role['positions'] ?> position<?= $role['positions'] > 1 ? 's' : '' ?></span><?php endif; ?>
      <?php if ($role['exp_min'] !== null || $role['exp_max'] !== null): ?>
        <span title="Candidates inside the allowance are a stretch fit and are flagged"><?= icon('award', 'sm') ?><?= e(($role['exp_min'] ?? 0) + 0) ?>–<?= $role['exp_max'] !== null ? e($role['exp_max'] + 0) : '+' ?> yrs <span class="muted">(takes <?= e(max(0, ($role['exp_min'] ?? 0) - $role['exp_allow_below']) + 0) ?>–<?= $role['exp_max'] !== null ? e($role['exp_max'] + $role['exp_allow_above'] + 0) : '+' ?>)</span></span>
      <?php endif; ?>
      <?php if ($role['ctc_max_lpa'] !== null): ?><span>₹ up to <?= e(lpa($role['ctc_max_lpa'])) ?></span><?php endif; ?>
      <?php if ($role['notice_max_days'] !== null): ?><span><?= icon('clock', 'sm') ?>notice ≤ <?= (int) $role['notice_max_days'] ?> days</span><?php endif; ?>
    </div>
    <?php if (!$isPool && ($catMode ?? 'off') !== 'off'): ?>
    <div class="facts-inline" style="margin-top:4px">
      <span title="Fields of work this position takes"><?= icon('briefcase', 'sm') ?><?= $roleFields ? e(implode(', ', array_map(fn ($c) => \Eshcol\Ats\Services\Categoriser::functionName(\Eshcol\Ats\App::get(), $c), $roleFields))) : 'Field not set' ?><?= $roleFields && !$fieldsSet && !$contractor ? ' <span class="muted">(guessed from the title: <a href="/roles/' . (int) $role['id'] . '/edit#who-fieldset">set it</a>)</span>' : '' ?></span>
      <span><?= icon('pin', 'sm') ?><?= e(\Eshcol\Ats\Services\FunctionRouting::SCOPES[\Eshcol\Ats\Services\FunctionRouting::scopeOf($role)]) ?></span>
    </div>
    <?php endif; ?>
    </div>
  </div>
  <div class="actions">
    <?php $contractor = \Eshcol\Ats\Access::restricted($user ?? null); if (!$contractor): ?>
    <a class="btn ghost" href="/roles/<?= $role['id'] ?>/edit"><?= icon('edit') ?>Edit</a>
    <details class="menu">
      <summary class="btn ghost" aria-label="More actions"><?= icon('more') ?></summary>
      <div class="menu-list">
        <?php if (!$isPool): ?><a href="/roles/<?= $role['id'] ?>/post"><?= icon('external') ?><div>Post this job<small>LinkedIn, Naukri and WhatsApp text</small></div></a><?php endif; ?>
        <a href="/roles/<?= $role['id'] ?>/matches"><?= icon('search') ?><div>Find in your database<small>Everyone on file who fits</small></div></a>
        <?php if (!$isPool): ?><a href="/roles/<?= $role['id'] ?>/questions"><?= icon('clipboard') ?><div>Screening questions</div></a><?php endif; ?>
        <form method="post" action="/roles/<?= $role['id'] ?>/rescore"><?= csrf() ?><button type="submit"><?= icon('sparkles') ?><div>Re-score everyone<small>After you change the must-haves</small></div></button></form>
      </div>
    </details>
    <?php endif; ?>
    <?php if (!$isPool): ?>
      <?php if (\Eshcol\Ats\Services\Engagement::of($role) !== 'inhouse'): ?><a class="btn ghost" href="/roles/<?= $role['id'] ?>/shortlist/new"><?= icon('send') ?>Send shortlist</a><?php endif; ?>
      <a class="btn" href="/upload?role=<?= $role['id'] ?>"><?= icon('upload') ?>Upload CVs</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($isPool): ?>
  <div class="card sand"><b>Talent pool.</b> CVs land here by the position the candidate holds, when they applied for a role in a different field, fell outside its experience allowance, or arrived with no role and fit no open one. It isn't a client role and nobody is emailed. When a client asks for this position, create the role and use <i>Find in your database</i>.</div>
<?php endif; ?>

<?php if (!$isPool):
  // Position page, option A (14 Nov 2026): number tiles, then the best people with what decides them and one next step.
  $fitRows = $fit['rows'] ?? [];
  // Still to decide (16 Nov 2026): Not suitable, Withdrew and Future requirement are left out. Equal scores keep a
  // fixed order (name, then reference), so the list doesn't reshuffle between visits.
  $isLikely = fn ($a) => !in_array($a['stage_kind'] ?? '', ['fail', 'hold'], true) && in_array($fitRows[(int) $a['id']]['band'] ?? '', ['Strong', 'Possible'], true) && ($fitRows[(int) $a['id']]['status'] ?? '') === 'done';
  $likely = array_values(array_filter($all, $isLikely));
  usort($likely, fn ($x, $y) => [(int) $fitRows[(int) $y['id']]['score'], mb_strtolower((string) $x['full_name']), (int) $x['candidate_id']]
      <=> [(int) $fitRows[(int) $x['id']]['score'], mb_strtolower((string) $y['full_name']), (int) $y['candidate_id']]);
  $sentN = count(array_filter($all, fn ($a) => isset(($sentToClient ?? [])[(int) $a['candidate_id']])));
  $openDays = max(0, (int) round((time() - strtotime((string) ($role['opened_at'] ?: $role['created_at']))) / 86400));
  $topScore = $likely ? (int) $fitRows[(int) $likely[0]['id']]['score'] : null;
  $budget = $role['ctc_max_lpa'] !== null ? (float) $role['ctc_max_lpa'] : null; ?>
<div class="tiles">
  <div class="tile hl"><b><?= number_format($total) ?></b><span>Candidates</span></div>
  <div class="tile"><b><?= count($likely) ?></b><span>Strong or Possible</span></div>
  <?php if (\Eshcol\Ats\Services\Engagement::of($role) === 'inhouse'): $ivN = count(array_filter($all, fn ($a) => in_array(\Eshcol\Ats\Services\Stages::code(\Eshcol\Ats\App::get(), (int) ($a['stage_id'] ?? 0)), ['interview', 'offer', 'joined'], true))); ?>
  <div class="tile"><b><?= $ivN ?></b><span>At interview or later</span></div>
  <?php else: ?><div class="tile"><b><?= $sentN ?></b><span>Sent to client</span></div><?php endif; ?>
  <div class="tile"><b><?= $openDays ?> day<?= $openDays === 1 ? '' : 's' ?></b><span>Open for</span></div>
  <div class="tile"><b><?= $topScore ?? '–' ?></b><span>Top match</span></div>
</div>
<?php // Top candidates was folded into the Responded / Yet to respond tabs below (17 Nov 2026): the same ranking, one table. ?>
<?php endif; ?>

<?php if (!empty($toClarify) && in_array($role['status'], ['open', 'draft', 'on_hold'], true) && !\Eshcol\Ats\Access::restricted($user ?? null)): ?>
  <div class="flash bell" role="status"><?= icon('clipboard', 'sm') ?><div><b>To clarify with the client:</b> <?= e(implode('; ', $toClarify)) ?>. <a href="/roles/<?= (int) $role['id'] ?>/edit">Add them</a><?= count(array_filter($toClarify, fn ($t) => str_starts_with($t, 'At least three'))) ? ' (must-haves are on the Claude tab)' : '' ?>.</div></div>
<?php endif; ?>

<nav class="tabs" data-tabs aria-label="Role sections">
  <a href="#tab-pipeline" data-tab="pipeline"><?= icon('columns', 'sm') ?>Pipeline <span class="n"><?= $total ?></span></a>
  <?php if (!$isPool): ?>
    <a href="#tab-interviews" data-tab="interviews"><?= icon('calendar', 'sm') ?>Interviews<?= $activeIv ? ' <span class="n">' . $activeIv . '</span>' : '' ?></a>
    <a href="#tab-claude" data-tab="claude"><?= icon('sparkles', 'sm') ?>Claude<?= count(array_filter($fit['rows'], fn ($f) => $f['status'] === 'done')) ? ' <span class="n">' . count(array_filter($fit['rows'], fn ($f) => $f['status'] === 'done')) . '</span>' : '' ?></a>
    <a href="#tab-client" data-tab="client"><?= icon('send', 'sm') ?>Client &amp; screening<?= $shortlists ? ' <span class="n">' . count($shortlists) . '</span>' : '' ?></a>
  <?php endif; ?>
  <a href="#tab-details" data-tab="details"><?= icon('file', 'sm') ?>Job details</a>
</nav>

<section class="tab-panel" id="tab-pipeline" data-panel="pipeline">
  <?php // One candidate table with tabs (17 Nov 2026): Responded, Yet to respond, Shortlisted, Found in database, Everyone.
  $tabUrl = fn (string $w): string => '/roles/' . (int) $role['id'] . '?who=' . $w . '#tab-pipeline'; ?>
  <nav class="stage-tabs who-tabs" aria-label="Candidates">
    <a href="<?= $tabUrl('responded') ?>" class="w-resp<?= $who === 'responded' ? ' on' : '' ?><?= $responded ? '' : ' zero' ?>"<?= $who === 'responded' ? ' aria-current="page"' : '' ?>>✓ Responded, ranked <b>(<?= count($responded) ?>)</b></a>
    <a href="<?= $tabUrl('waiting') ?>" class="w-wait<?= $who === 'waiting' ? ' on' : '' ?><?= $waitingList ? '' : ' zero' ?>"<?= $who === 'waiting' ? ' aria-current="page"' : '' ?>>✉ Yet to respond, ranked <b>(<?= count($waitingList) ?>)</b></a>
    <?php if (!$isPool): ?>
      <a href="<?= $tabUrl('short') ?>" class="w-short<?= $who === 'short' ? ' on' : '' ?><?= $shortN ? '' : ' zero' ?>"<?= $who === 'short' ? ' aria-current="page"' : '' ?>>✓ Shortlisted <b>(<?= $shortN ?>)</b></a>
      <?php if (!empty($found)): ?><a href="<?= $tabUrl('found') ?>" class="w-found<?= $who === 'found' ? ' on' : '' ?><?= $foundN ? '' : ' zero' ?>"<?= $who === 'found' ? ' aria-current="page"' : '' ?>>🔍 Found in database <b>(<?= $foundN ?>)</b></a><?php endif; ?>
    <?php endif; ?>
    <a href="/roles/<?= (int) $role['id'] ?>?view=board#tab-pipeline" class="w-all<?= !$who ? ' on' : '' ?>"<?= !$who ? ' aria-current="page"' : '' ?>>Everyone, by stage <b>(<?= $total ?>)</b></a>
  </nav>
  <?php if ($who === 'short'): ?>
    <?php include __DIR__ . '/_shortlisted.php'; ?>
    <?php if (!$shortN): ?><div class="card empty">Nobody is shortlisted yet. Move someone to Shortlisted and they appear here, ready to send to the client.</div><?php endif; ?>
  <?php elseif ($who === 'found'): ?>
    <?php if (!empty($found) && ($found['fits'] || $found['elsewhere'])) { include __DIR__ . '/_found.php'; } else { echo '<div class="card empty">Nobody else on file fits this position\'s field, experience and location right now.</div>'; } ?>
  <?php elseif (!$apps && !$below && !$stageFilter): ?>
    <div class="card empty">
      <?= icon('users') ?>
      <h3>No candidates on this role yet</h3>
      <p>Upload the CVs you've received, or check who you already have on file.</p>
      <p class="actions" style="justify-content:center"><a class="btn" href="/upload?role=<?= $role['id'] ?>"><?= icon('upload') ?>Upload CVs</a><a class="btn ghost" href="/roles/<?= $role['id'] ?>/matches"><?= icon('search') ?>Find in your database</a></p>
    </div>
  <?php else: ?>
    <?php if (!$who): // Stage tabs with the numbers (16 Nov 2026): every stage, so you can see exactly where everyone is; a click lists them. ?>
    <nav class="stage-tabs" aria-label="Candidates by stage">
      <a href="/roles/<?= $role['id'] ?>?view=list#tab-pipeline" class="st-all<?= !$who && !$stageFilter && $view === 'list' ? ' on' : '' ?>"<?= !$who && !$stageFilter && $view === 'list' ? ' aria-current="page"' : '' ?>>All <b>(<?= $total ?>)</b></a>
      <?php foreach ($stages as $s): $n = (int) ($counts[$s['id']] ?? 0); $on = $stageFilter === (int) $s['id']; ?>
        <a href="/roles/<?= $role['id'] ?>?view=list&amp;stage=<?= $s['id'] ?>#tab-pipeline" class="<?= \Eshcol\Ats\Services\KeyFacts::stageTone((string) $s['name'], (string) $s['kind']) ?><?= $on ? ' on' : '' ?><?= $n ? '' : ' zero' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($s['name']) ?> <b>(<?= $n ?>)</b></a>
      <?php endforeach; ?>
    </nav>
    <div class="actions" style="justify-content:space-between;margin-bottom:12px">
      <div class="seg" role="group" aria-label="View">
        <a href="/roles/<?= $role['id'] ?>?view=board#tab-pipeline" class="<?= !$who && $view === 'board' ? 'on' : '' ?>"><?= icon('columns', 'sm') ?>Board</a>
        <a href="/roles/<?= $role['id'] ?>?view=list#tab-pipeline" class="<?= !$who && $view === 'list' ? 'on' : '' ?>"><?= icon('list', 'sm') ?>List</a>
      </div>
      <?php if ($view === 'board'): ?>
        <span class="small muted">Drag a card to move the candidate to that stage. Faded cards are below the threshold. A grey dashed ring is the keyword score only: Claude hasn't assessed them for this position.</span>
      <?php elseif ($stageFilter): ?>
        <span class="small muted">Showing only <?= e((string) ($stages[array_search($stageFilter, array_map('intval', array_column($stages, 'id')), true)]['name'] ?? '')) ?>. <a href="/roles/<?= $role['id'] ?>?view=list#tab-pipeline">Show everyone</a></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($who):
      $wlAll = $who === 'responded' ? $responded : $waitingList;
      // Their stages inside this tab (17 Nov 2026): Applied, Under review, Submitted to client… with the numbers.
      $wlCounts = array_count_values(array_map(fn ($x) => (int) $x['stage_id'], $wlAll));
      $st = (int) ($_GET['st'] ?? 0);
      $wl = $st ? array_values(array_filter($wlAll, fn ($x) => (int) $x['stage_id'] === $st)) : $wlAll; ?>
      <?php if ($wlAll): ?>
        <nav class="stage-tabs sub-tabs" aria-label="By stage">
          <a href="/roles/<?= (int) $role['id'] ?>?who=<?= e($who) ?>#tab-pipeline" class="st-all<?= !$st ? ' on' : '' ?>"<?= !$st ? ' aria-current="page"' : '' ?>>All <b>(<?= count($wlAll) ?>)</b></a>
          <?php foreach ($stages as $sx): $n = $wlCounts[(int) $sx['id']] ?? 0; if (!$n) { continue; } $on = $st === (int) $sx['id']; ?>
            <a href="/roles/<?= (int) $role['id'] ?>?who=<?= e($who) ?>&amp;st=<?= (int) $sx['id'] ?>#tab-pipeline" class="<?= \Eshcol\Ats\Services\KeyFacts::stageTone((string) $sx['name'], (string) $sx['kind']) ?><?= $on ? ' on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($sx['name']) ?> <b>(<?= $n ?>)</b></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <p class="small muted" style="margin:0 0 10px"><?= $who === 'responded'
        ? "Everyone who has answered our details request or written back."
        : "Everyone we have asked or written to who hasn't answered yet." ?>
        Best first: those Claude has assessed for this position by Claude's score, then the rest by keyword score (marked "keyword").
        <?= $closedOut[$who] ? ' Not shown: ' . $closedOut[$who] . ' marked Not suitable, Withdrawn, No response or Future requirement (see their stage tabs).' : '' ?></p>
      <?php if (!$wl): ?>
        <div class="card empty"><?= $who === 'responded' ? 'Nobody has responded yet.' : 'Nobody is waiting: everyone we asked has answered.' ?></div>
      <?php else: ?>
        <div class="table-wrap"><table class="list">
          <thead><tr><th>Rank</th><th>Candidate</th><th>Stage</th></tr></thead>
          <tbody><?php foreach ($wl as $i => $a) { echo $row($a, $i + 1); } ?></tbody>
        </table></div>
      <?php endif; ?>
    <?php elseif ($view === 'board'):
      $cols = [];
      foreach ($all as $a) { $cols[(int) $a['stage_id']][] = $a; }
      // Long columns show their first $cap cards (best first) with a link to the rest (B12, 15 Nov 2026): a busy position
      // page was over 1 MB. Counts in the headings stay the full numbers.
      $showAll = !empty($_GET['all']);
      $cap = $showAll ? PHP_INT_MAX : 40;
      $dot = ['active' => '#6B3FB4', 'success' => '#26733A', 'fail' => '#A59FB3', 'hold' => '#C98A2E']; ?>
      <div class="board" data-stage-board>
        <?php foreach ($stages as $s): $list = $cols[(int) $s['id']] ?? []; ?>
          <div class="col<?= in_array($s['kind'], ['fail', 'hold'], true) ? ' closed' : '' ?><?= $s['kind'] === 'hold' ? ' hold' : '' ?>" data-stage="<?= (int) $s['id'] ?>" data-name="<?= e($s['name']) ?>">
            <div class="col-head"><span class="dot" style="background:<?= $dot[$s['kind']] ?? '#6B3FB4' ?>"></span><?= e($s['name']) ?><span class="n"><?= count($list) ?></span></div>
            <div class="col-body">
              <?php if (!$list): ?><div class="empty-col">Drop here</div><?php endif; ?>
              <?php foreach (array_slice($list, 0, $cap) as $a):
                $nm = $a['full_name'] ?: 'Name not found';
                $cd = $clientDecisions[(int) $a['id']] ?? null;
                $isBelow = $a['stage_kind'] !== 'fail' && $a['eff_score'] !== null && (int) $a['eff_score'] < $threshold; ?>
                <a class="kcard<?= $isBelow ? ' below' : '' ?><?= $isShort($a) ? ' short' : '' ?>" draggable="true" data-app="<?= (int) $a['id'] ?>" href="/candidates/<?= $a['candidate_id'] ?>?role=<?= $role['id'] ?>">
                  <div class="top-line">
                    <span class="avatar sm" style="--h:<?= avatar_hue($nm) ?>"><?= e(initials($nm)) ?></span>
                    <span class="name"><?= e($nm) ?></span>
                    <?php $kf = $fit['rows'][(int) $a['id']] ?? null; $kc = $kf && $kf['status'] === 'done' && $kf['score'] !== null; // the assessment score leads when there is one
                      $ks = $kc ? (int) $kf['score'] : ($a['eff_score'] !== null ? (int) $a['eff_score'] : null); ?><span class="ring sm <?= $ks === null ? 'r-none' : (!$kc ? 'r-kw' : ($ks >= 75 ? 'r-hi' : ($ks >= $threshold ? 'r-mid' : 'r-lo'))) ?>" style="--p:<?= $ks ?? 0 ?>%" title="<?= $kc ? 'Claude assessment' : "Keyword score only: Claude hasn't assessed them for this position" ?>"><em><?= $ks ?? '–' ?></em></span>
                  </div>
                  <?php $kq = \Eshcol\Ats\Services\KeyFacts::topQualification($kfOf($a)['qualifications'] ?? null); ?>
                  <div class="meta"><?= e(experience(exp_now($a))) ?> · <?= e($a['location'] ?: '?') ?><?= $kq !== '' ? ' · ' . e($kq) : '' ?> · <?= $notice($a['notice_days']) ?> · <?= e(lpa($kfOf($a)['ctc_current_lpa'] ?? null)) ?> → <?= e(lpa($a['ctc_expected_lpa'])) ?></div>
                  <?php if (in_array($a['stage_kind'], ['fail', 'hold'], true)): $hold = $a['stage_kind'] === 'hold'; ?><div class="tags reason-tags"><span class="chip <?= $hold ? 'keep' : 'reason' ?>" title="<?= e($a['stage_reason'] ?: ($hold ? 'No note yet' : 'No reason given yet')) ?>"><?= e($a['stage_reason'] ? mb_strimwidth($a['stage_reason'], 0, 60, '…') : ($hold ? 'kept for later' : 'no reason given')) ?></span></div><?php endif; ?>
                  <?php if ($cd || $a['fit_note'] || $a['do_not_contact'] || !empty($a['unreliable_at']) || isset($qs[(int) $a['id']]) || isset($fit['rows'][(int) $a['id']]) || isset($sentToClient[(int) $a['candidate_id']])): ?>
                    <div class="tags">
                      <?= $fitChip($a) ?>
                      <?php if ($a['do_not_contact']): ?><span class="chip no" style="text-decoration:none">do not contact</span><?php endif; ?>
                      <?php if (!empty($a['unreliable_at'])): ?><span class="chip no" style="text-decoration:none" title="<?= e(\Eshcol\Ats\Services\Reliability::describe($a)) ?>">unreliable</span><?php endif; ?>
                      <?php if ($cd): ?><span class="chip <?= ['shortlist' => 'yes', 'hold' => 'sand', 'reject' => 'flag'][$cd] ?>">Client: <?= e(\Eshcol\Ats\Services\Shortlists::DECISIONS[$cd]) ?></span><?php endif; ?>
                      <?php if ($a['fit_note']): ?><span class="chip <?= $a['source'] === 'routed' ? 'sand' : 'flag' ?>" title="<?= e($a['fit_note']) ?>"><?= $a['source'] === 'routed' ? '↪ routed' : '⚠ stretch' ?></span><?php endif; ?>
                      <?= strip_tags($qChip($qs[(int) $a['id']] ?? null, (int) $a['candidate_id']), '<span>') ?>
                    </div>
                  <?php endif; ?>
                  <?php $lc = ($lastContact ?? [])[(int) $a['candidate_id']] ?? null; ?>
                  <div class="kfoot"><?= icon('clock', 'sm') ?><?= $lc ? 'Last contact ' . e(ago($lc)) : 'No contact yet' ?></div>
                </a>
              <?php endforeach; ?>
              <?php if (count($list) > $cap): ?><a class="kmore" href="/roles/<?= (int) $role['id'] ?>?view=board&amp;all=1#tab-pipeline">+ <?= count($list) - $cap ?> more: show everyone</a><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <noscript><p class="small muted">Dragging needs JavaScript. Use the List view to change stages.</p></noscript>
    <?php else: ?>
      <?php if ($apps): ?>
        <div class="table-wrap"><table class="list">
          <thead><tr><th>Score</th><th>Candidate</th><th>Stage</th></tr></thead>
          <?php $cap = !empty($_GET['all']) ? PHP_INT_MAX : 100; // the first 100, best first (B12, 15 Nov 2026) ?>
          <tbody><?php foreach (array_slice($apps, 0, $cap) as $a) { echo $row($a); } ?></tbody>
        </table></div>
        <?php if (count($apps) > $cap): ?><p class="small" style="margin:10px 0 0">Showing the first <?= $cap ?> of <?= count($apps) ?>. <a href="/roles/<?= (int) $role['id'] ?>?view=list&amp;all=1<?= $stageFilter ? '&amp;stage=' . (int) $stageFilter : '' ?>#tab-pipeline">Show everyone</a></p><?php endif; ?>
      <?php elseif (!$below): ?>
        <div class="card empty">Nobody at this stage.</div>
      <?php endif; ?>
      <?php if ($below): ?>
        <details class="card" style="margin-top:18px"<?= $apps ? '' : ' open' ?>>
          <summary><b>Below the threshold of <?= $threshold ?> (<?= count($below) ?>)</b> <span class="small muted">still here, nobody is rejected automatically</span></summary>
          <div class="table-wrap" style="margin-top:12px"><table class="list"><tbody><?php foreach (array_slice($below, 0, !empty($_GET['all']) ? PHP_INT_MAX : 100) as $a) { echo $row($a); } ?></tbody></table></div>
          <?php if (empty($_GET['all']) && count($below) > 100): ?><p class="small" style="margin:10px 0 0">Showing 100 of <?= count($below) ?>. <a href="/roles/<?= (int) $role['id'] ?>?view=list&amp;all=1#tab-pipeline">Show everyone</a></p><?php endif; ?>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if (!$isPool): ?>
<section class="tab-panel" id="tab-interviews" data-panel="interviews">
  <?php include __DIR__ . '/_interviews.php'; ?>
</section>

<section class="tab-panel" id="tab-claude" data-panel="claude" data-lazy="/roles/<?= (int) $role['id'] ?>?part=claude">
  <div class="card"><p class="muted" style="margin:0">Loading Claude's assessments… <noscript>This tab needs JavaScript. <a href="/roles/<?= (int) $role['id'] ?>?part=claude">Open it on its own</a>.</noscript></p></div>
</section>

<section class="tab-panel" id="tab-client" data-panel="client">
  <div class="card" id="shortlists">
    <div class="card-head"><h2>Client shortlists</h2><a class="btn small" href="/roles/<?= $role['id'] ?>/shortlist/new"><?= icon('send', 'sm') ?>Send a shortlist</a></div>
    <?php if (!$shortlists): ?>
      <p class="muted small" style="margin:0">None sent yet. Pick the best candidates, and we brand their CVs and draft the email in your usual format.</p>
    <?php else: ?>
      <?php $fb = (new \Eshcol\Ats\Services\Migrator(\Eshcol\Ats\App::get()))->pending() ? [] : (new \Eshcol\Ats\Services\SubmissionChecks(\Eshcol\Ats\App::get()))->feedback((int) $role['id']);
      if ($fb): $sum = fn ($k) => array_sum(array_map(fn ($r) => (int) $r[$k], $fb)); $waitDays = max(array_map(fn ($r) => (int) $r['waiting'] ? (int) $r['days'] : 0, $fb)); ?>
        <p class="client-strip small"><b>Client feedback:</b>
          <span class="chip yes"><?= $sum('yes') ?> shortlisted</span><span class="chip sand"><?= $sum('hold') ?> on hold</span><span class="chip flag"><?= $sum('no') ?> not suitable</span>
          <span class="chip<?= $sum('waiting') && $waitDays >= \Eshcol\Ats\Services\SubmissionChecks::CHASE_WORKING_DAYS ? ' bad' : '' ?>"><?= $sum('waiting') ?> waiting<?= $sum('waiting') ? ' (oldest ' . $waitDays . ' working day' . ($waitDays === 1 ? '' : 's') . ')' : '' ?></span></p>
      <?php endif; ?>
      <table class="list">
        <thead><tr><th>Shortlist</th><th>To</th><th>Candidates</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($shortlists as $sl):
          $st = $sl['status'] === 'draft' ? ['draft', 'Draft, not sent'] : ($sl['status'] === 'closed' ? ['cancelled', 'Closed'] : ((int) $sl['decided'] ? ['open', (int) $sl['decided'] . ' decided'] : ($sl['first_viewed_at'] ? ['filled', 'Opened'] : ['', 'Not opened yet']))); ?>
          <tr>
            <td><a href="/shortlists/<?= (int) $sl['id'] ?>"><span class="ref"><?= ref_shortlist($sl['id']) ?></span></a> <span class="small muted"><?= e(nice_date($sl['created_at'])) ?></span></td>
            <td><?= e($sl['to_name'] ?: $sl['to_email']) ?></td>
            <td><?= (int) $sl['n'] ?></td>
            <td><span class="badge <?= $st[0] ?>"><?= e($st[1]) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" id="screening">
    <div class="card-head"><h2>Candidate details request</h2><?php if (!$contractor): ?><a class="btn ghost small" href="/roles/<?= $role['id'] ?>/questions"><?= icon('edit', 'sm') ?>Edit questions</a><?php endif; ?></div>
    <p class="small" style="margin:0 0 10px">Every thank-you email asks for these. Candidates check the details we read from their CV, then give their current and lowest acceptable salary (and the one they hope for), how soon they could join, and whether they can work <?= $role['location'] ? 'in ' . e($role['location']) : 'there' ?><?= $qCustom ? ', plus ' . $qCustom . ' question' . ($qCustom > 1 ? 's' : '') . ' of your own' : '' ?>.
      <?php if (array_sum($qCounts)): ?><br><span class="muted"><?= $qCounts['answered'] ?> answered<?= $qCounts['flagged'] ? ' (' . $qCounts['flagged'] . ' with flags)' : '' ?> · <?= $qCounts['sent'] ?> waiting</span><?php endif; ?></p>
    <?php if (!$contractor): ?><p class="actions"><a class="btn small" href="/details-requests"><?= icon('check', 'sm') ?>Responses, and ask more people</a></p><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="tab-panel" id="tab-details" data-panel="details">
  <?php if (!empty($history)): ?>
    <details class="card" id="history"><summary><b>Changes to this position</b> <span class="small muted">(<?= count($history) ?>)</span></summary>
      <ul class="small" style="margin:8px 0 0;padding-left:18px">
        <?php foreach ($history as $h): ?>
          <li><b><?= e(nice_date($h['at'], true)) ?></b>, <?= e($h['who']) ?>:<ul style="padding-left:16px"><?php foreach ($h['lines'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></li>
        <?php endforeach; ?>
      </ul></details>
  <?php endif; ?>
  <?php if (!$isPool || ($by['must'] ?? [])): ?>
  <div class="card">
    <h2>What we're looking for</h2>
    <p class="small" style="margin:0 0 8px"><b>Must-haves:</b> <?= $by['must'] ?? [] ? implode('', array_map(fn ($v) => '<span class="chip">' . e($v) . '</span>', $by['must'])) : '<span class="muted">none set, so every CV scores full marks here.' . ($contractor ? '' : ' <a href="/roles/' . $role['id'] . '/edit">Add some</a>.') . '</span>' ?></p>
    <?php if ($by['nice'] ?? []): ?><p class="small" style="margin:0 0 8px"><b>Nice-to-haves:</b> <?= implode('', array_map(fn ($v) => '<span class="chip sand">' . e($v) . '</span>', $by['nice'])) ?></p><?php endif; ?>
    <?php if ($by['qualification'] ?? []): ?><p class="small" style="margin:0"><b>Qualification:</b> <?= e(implode(' or ', $by['qualification'])) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!$isPool): $ownerType = 'role'; $ownerId = (int) $role['id']; $kinds = ['jd', 'other']; include __DIR__ . '/../partials/documents.php'; endif; ?>
  <?php if ($role['jd_text']): ?>
    <div class="card"><h2>Job description</h2><pre class="cvtext"><?= e($role['jd_text']) ?></pre></div>
  <?php endif; ?>
</section>
<script type="module" src="<?= asset('cv-viewer.js') ?>"></script>
