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
      <tr>
        <td><?php if ($r['candidate_id']): ?><a href="/candidates/<?= (int) $r['candidate_id'] ?>"><b><?= e($r['full_name']) ?></b></a> <?= stage_tags($r['candidate_id']) ?><?php else: ?><b><?= e($r['full_name']) ?></b><?php endif; ?>
          <br><span class="small muted"><?= e($p['source_ref']['candidate_ref'] ?? '') ?></span></td>
        <td><?= e($p['job']['designation'] ?? '') ?><br><span class="small muted"><?= $r['engagement'] === 'staffing' ? 'Staffing at ' . e($d['client_name'] ?? '') . ($d['client_site'] ?? '' ? ', ' . e(one_line((string) $d['client_site'])) : '') : 'Our own team' ?></span></td>
        <td class="nowrap"><?= e(nice_date($r['joining_date'])) ?><?= !empty($p['job']['contract_end']) ? '<br><span class="small muted">to ' . e(nice_date($p['job']['contract_end'])) . '</span>' : '' ?></td>
        <td class="small"><?= isset($p['pay']['gross_month']) && $p['pay']['gross_month'] !== null ? '₹' . e(number_format((float) $p['pay']['gross_month'])) . ' a month' : e(lpa($p['pay']['annual_ctc_lpa'] ?? null)) ?>
          <?= $d && isset($d['margin_pct']) ? '<br><span class="muted">margin ' . e((string) $d['margin_pct']) . ' %</span>' : '' ?></td>
        <td class="small"><?= (int) $r['doc_n'] ?> of <?= count(Staffing::JOINING) ?></td>
        <td><span class="chip <?= ['ready' => 'flag', 'downloaded' => 'sand', 'imported' => 'yes', 'withdrawn' => ''][$r['status']] ?>" style="text-decoration:none"><?= e(Joinings::STATUS[$r['status']]) ?></span>
          <?= $r['downloaded_at'] ? '<br><span class="small muted">' . e(nice_date($r['downloaded_at'])) . '</span>' : '' ?></td>
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
