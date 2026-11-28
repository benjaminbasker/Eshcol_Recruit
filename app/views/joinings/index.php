<?php
/** Joinings (phase 3, 15 Nov 2026). @var array $rows @var string $show @var array $counts */
use Eshcol\Ats\Services\Joinings;
use Eshcol\Ats\Services\Staffing;
?>
<div class="page-head">
  <div><p class="eyebrow">Owner only</p><h1>Joinings</h1></div>
  <?php if (($counts['ready'] ?? 0) + ($counts['downloaded'] ?? 0)): ?><a class="btn" href="/joinings/download.csv"><?= icon('download') ?>Download for the HRMS (spreadsheet)</a><?php endif; ?>
</div>
<p class="small muted" style="margin:-8px 0 12px">People who joined Eshcol's payroll, for our own team or on staffing. Each record holds what the HRMS needs on their first day, so nothing is typed twice. Until the HRMS is ready, download them here; it will import the same records later. No Aadhaar, PAN or bank numbers are kept here, only that the documents were received.</p>
<nav class="tabs" aria-label="Which joinings">
  <a href="/joinings" class="<?= $show === 'open' ? 'on' : '' ?>">To set up <span class="n"><?= (int) (($counts['ready'] ?? 0) + ($counts['downloaded'] ?? 0)) ?></span></a>
  <a href="/joinings?show=all" class="<?= $show === 'all' ? 'on' : '' ?>">All <span class="n"><?= (int) array_sum($counts) ?></span></a>
  <a href="/joinings?show=withdrawn" class="<?= $show === 'withdrawn' ? 'on' : '' ?>">Withdrawn <span class="n"><?= (int) ($counts['withdrawn'] ?? 0) ?></span></a>
</nav>
<?php if (!$rows): ?>
  <div class="card empty"><?= $show === 'open' ? 'Nobody waiting. A record appears here when someone on an own-team or staffing position is moved to "Joined".' : 'None.' ?></div>
<?php else: ?>
  <div class="table-wrap"><table class="list">
    <thead><tr><th>Person</th><th>Job</th><th>Joining</th><th>Pay</th><th>Documents</th><th>Status</th><th></th></tr></thead>
    <tbody><?php prime_stages(array_column($rows, 'candidate_id')); foreach ($rows as $r): $p = $r['p']; $d = $p['deployment'] ?? null; ?>
      <tr id="j-<?= (int) $r['id'] ?>">
        <td><?php if ($r['candidate_id']): ?><a href="/candidates/<?= (int) $r['candidate_id'] ?>"><b><?= e($r['full_name']) ?></b></a> <?= stage_tags($r['candidate_id']) ?><?php else: ?><b><?= e($r['full_name']) ?></b><?php endif; ?>
          <br><span class="small muted"><?= e($p['source_ref']['candidate_ref'] ?? '') ?></span></td>
        <td><?= e($p['job']['designation'] ?? '') ?><br><span class="small muted"><?= $r['engagement'] === 'staffing' ? 'Staffing at ' . e($d['client_name'] ?? '') . ($d['client_site'] ?? '' ? ', ' . e(one_line((string) $d['client_site'])) : '') : 'Our own team' ?></span></td>
        <td class="nowrap"><?= e(nice_date($r['joining_date'])) ?><?= !empty($p['job']['contract_end']) ? '<br><span class="small muted">to ' . e(nice_date($p['job']['contract_end'])) . '</span>' : '' ?></td>
        <td class="small"><?= isset($p['pay']['gross_month']) && $p['pay']['gross_month'] !== null ? '₹' . e(number_format((float) $p['pay']['gross_month'])) . ' a month' : e(lpa($p['pay']['annual_ctc_lpa'] ?? null)) ?>
          <?= $d && isset($d['margin_pct']) ? '<br><span class="muted">margin ' . e((string) $d['margin_pct']) . ' %</span>' : '' ?></td>
        <td class="small"><?= (int) $r['doc_n'] ?> of <?= count(Staffing::JOINING) ?></td>
        <td><span class="chip <?= ['ready' => 'flag', 'downloaded' => 'sand', 'imported' => 'yes', 'withdrawn' => ''][$r['status']] ?>" style="text-decoration:none"><?= e(Joinings::STATUS[$r['status']]) ?></span>
          <?= $r['status'] === 'imported' && !empty($r['hrms_employee_no']) ? '<br><span class="small muted">' . e($r['hrms_employee_no']) . ($r['imported_at'] ? ', ' . e(nice_date($r['imported_at'])) : '') . '</span>'
            : ($r['downloaded_at'] ? '<br><span class="small muted">' . e(nice_date($r['downloaded_at'])) . '</span>' : '') ?></td>
        <td class="actions small">
          <a class="btn ghost small" href="/joinings/<?= (int) $r['id'] ?>/json">Full record</a>
          <?php if (in_array($r['status'], ['ready', 'downloaded'], true)): ?>
            <form method="post" action="/joinings/<?= (int) $r['id'] ?>/refresh" style="display:inline"><?= csrf() ?><button class="btn ghost small" type="submit" title="Take in documents or feedback added since">Refresh</button></form>
            <form method="post" action="/joinings/<?= (int) $r['id'] ?>/status" style="display:inline"><?= csrf() ?><input type="hidden" name="status" value="imported"><button class="btn small" type="submit">Set up in the HRMS</button></form>
          <?php elseif ($r['status'] === 'imported'): ?>
            <form method="post" action="/joinings/<?= (int) $r['id'] ?>/status" style="display:inline"><?= csrf() ?><input type="hidden" name="status" value="ready"><button class="btn ghost small" type="submit">Undo</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if (($pages ?? 1) > 1): $base = '/joinings?' . ($show !== 'open' ? 'show=' . $show . '&' : '') . 'page='; ?>
    <p class="actions small" style="margin-top:12px">
      <?php if ($page > 1): ?><a class="btn ghost small" href="<?= e($base . ($page - 1)) ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn ghost small" href="<?= e($base . ($page + 1)) ?>">Next</a><?php endif; ?>
    </p>
  <?php endif; ?>
<?php endif; ?>
<section class="card" id="hrms-link" style="margin-top:18px">
  <h2>Link to the HRMS</h2>
  <p class="small">The HRMS fetches these records itself, every hour or when HR presses Fetch from Recruit, and tells Recruit when it has imported one: the record then shows "In the HRMS" with the employee number. Both apps hold the same link key: make it in the HRMS (Joinings from Recruit, Link to Recruit) and paste it here. Status: <b><?= !empty($hrmsLinked) ? 'linked' : 'not linked' ?></b>.</p>
  <form method="post" action="/joinings/hrms-link" class="actions"><?= csrf() ?>
    <input type="password" name="key" autocomplete="off" placeholder="Paste the link key from the HRMS" aria-label="Link key from the HRMS" style="max-width:420px">
    <button class="btn" type="submit">Save</button>
  </form>
  <?php if (!empty($hrmsLinked)): ?><form method="post" action="/joinings/hrms-link" style="margin-top:8px"><?= csrf() ?><input type="hidden" name="remove" value="1"><button class="btn ghost small" type="submit">Switch the link off</button></form><?php endif; ?>
</section>
<section class="card" id="leavers" style="margin-top:18px">
  <h2>Leaving or left (from the HRMS)</h2>
  <p class="small">People Recruit placed who have resigned, finished their contract or left, as the HRMS records them. Raise a replacement with the client if one is needed, then mark it dealt with. <?= !empty($feedsAt) ? 'Last fetched: ' . e($feedsAt) . '.' : 'Not fetched yet.' ?></p>
  <?php if (empty($leavers)): ?><p class="small muted">Nobody leaving.</p><?php else: ?>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th>Person</th><th>Client</th><th>Last day</th><th>Why</th><th>Rehire</th><th></th></tr></thead>
    <tbody><?php foreach ($leavers as $l): ?>
      <tr><td><?= $l['candidate_id'] ? '<a href="/candidates/' . (int) $l['candidate_id'] . '">' . e($l['full_name'] ?? ref_candidate((int) $l['candidate_id'])) . '</a>' : e($l['emp_no']) ?><br><span class="small muted"><?= e((string) $l['designation']) ?>, <?= e($l['emp_no']) ?></span></td>
        <td class="small"><?= e((string) ($l['client'] ?? '')) ?></td><td><?= e(nice_date($l['last_day'])) ?><?= $l['has_left'] ? '' : ' <span class="chip flag" style="text-decoration:none">On notice</span>' ?></td>
        <td class="small"><?= e((string) $l['reason']) ?></td><td class="small"><?= $l['rehire_ok'] === null ? '' : ((int) $l['rehire_ok'] ? 'Yes' : 'No') ?></td>
        <td><?php if ($l['handled_at']): ?><span class="small muted">Dealt with<?= $l['handled_note'] ? ': ' . e($l['handled_note']) : '' ?></span>
          <?php else: ?><form method="post" action="/joinings/leavers/<?= (int) $l['id'] ?>/handled" class="actions"><?= csrf() ?><input type="text" name="note" maxlength="255" placeholder="e.g. Replacement raised on 270" aria-label="Note" style="max-width:200px"><button class="btn ghost small" type="submit">Dealt with</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php if (!empty($hrmsLinked)): ?><form method="post" action="/joinings/hrms-fetch" style="margin-top:8px"><?= csrf() ?><button class="btn ghost small" type="submit">Fetch from the HRMS now</button></form><?php endif; ?>
</section>
