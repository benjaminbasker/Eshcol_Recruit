<?php
$numFilter = is_numeric($f['exp_min']) || is_numeric($f['exp_max']) || $f['level'] !== '' || is_numeric($f['notice_max']) || is_numeric($f['ctc_max']);
$moreOpen = array_filter(array_intersect_key($f, array_flip(['location', 'notice_max', 'ctc_max', 'source', 'added', 'skill']))) !== [];
$query = fn (array $extra = []) => http_build_query(array_filter(array_merge($f, $extra), fn ($v) => $v !== '' && $v !== null));
$bands = level_bands();
?>
<div class="page-head">
  <div><p class="eyebrow">Your database</p><h1>Candidates</h1>
    <p class="muted"><?= $total ?> <?= $filtered ? 'match' . ($total === 1 ? '' : 'es') : 'on file' ?><?= $q !== '' ? ' for "' . e($q) . '"' : '' ?></p></div>
  <a class="btn" href="/upload">Upload CVs</a>
</div>

<?php // Saved views (B15): one click to open a set of filters again; "Save this view" when filters are on.
$cur = $query(); ?>
<?php if (!empty($views) || ($filtered && $cur !== '')): ?>
  <div class="saved-views">
    <?php if (!empty($views)): ?><span class="small muted">Your views:</span>
      <?php foreach ($views as $v): ?>
        <span class="sv-chip<?= $v['query'] === $cur ? ' on' : '' ?>"><a href="/candidates?<?= e($v['query']) ?>"><?= e($v['name']) ?></a>
          <form method="post" action="/candidates/views/<?= (int) $v['id'] ?>/delete"><?= csrf() ?><button type="submit" aria-label="Remove the view <?= e($v['name']) ?>" title="Remove this view" data-confirm-click="Remove the saved view &quot;<?= e($v['name']) ?>&quot;?">×</button></form></span>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($filtered && $cur !== '' && !in_array($cur, array_column($views ?? [], 'query'), true)): ?>
      <form method="post" action="/candidates/views" class="sv-save"><?= csrf() ?><input type="hidden" name="query" value="<?= e($cur) ?>">
        <input type="text" name="name" maxlength="80" placeholder="e.g. Accounts, Pune, 2-5 yrs" aria-label="Name for this view" required>
        <button class="btn ghost small" type="submit">Save this view</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="get" action="/candidates" class="card search-card">
  <div class="row" style="align-items:flex-end">
    <div class="field" style="flex:3 1 260px"><label for="q">Keywords</label><input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, email, phone, or any word in the CV (e.g. AutoCAD isometrics)"></div>
    <div class="field" style="flex:2 1 200px"><label for="position">Position <span class="hint">held, now or before</span></label>
      <input type="text" id="position" name="position" value="<?= e($f['position']) ?>" list="positions" placeholder="e.g. Accounts">
      <datalist id="positions"><?php foreach ($opts['positions'] as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></div>
  </div>
  <?php if (array_sum(array_column($opts['functions'], 'n')) > 0): ?>
  <div class="row" style="align-items:flex-end">
    <div class="field" style="flex:2 1 220px"><label for="function">Field of work <span class="hint">their own, whatever they applied for</span></label>
      <select id="function" name="function"><option value="">Any</option>
        <?php foreach ($opts['functions'] as $fo): if (!$fo['n']) { continue; } ?><option value="<?= e($fo['code']) ?>"<?= selected($f['function'], $fo['code']) ?>><?= e($fo['name']) ?> (<?= (int) $fo['n'] ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="field" style="flex:1 1 150px"><label for="seniority">Seniority</label>
      <select id="seniority" name="seniority"><option value="">Any</option>
        <?php foreach (\Eshcol\Ats\Services\Categoriser::SENIORITY as $sk => $sl): ?><option value="<?= $sk ?>"<?= selected($f['seniority'], $sk) ?>><?= e($sl) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="flex:1 1 170px"><label for="open_to">Would work in <span class="hint">lives there or said so</span></label>
      <input type="text" id="open_to" name="open_to" value="<?= e($f['open_to']) ?>" list="cities" placeholder="e.g. Pune">
      <datalist id="cities"><?php foreach ($opts['locations'] as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></div>
  </div>
  <?php endif; ?>
  <div class="row" style="align-items:flex-end">
    <div class="field" style="flex:0 1 110px"><label for="exp_min">Experience from</label><input type="number" id="exp_min" name="exp_min" min="0" max="50" step="0.5" value="<?= e($f['exp_min']) ?>" placeholder="yrs"></div>
    <div class="field" style="flex:0 1 110px"><label for="exp_max">to</label><input type="number" id="exp_max" name="exp_max" min="0" max="50" step="0.5" value="<?= e($f['exp_max']) ?>" placeholder="yrs"></div>
    <div class="field" style="flex:1 1 170px"><label for="level">or Level</label>
      <select id="level" name="level"><option value="">Any</option>
        <?php foreach ($bands as [$n, $from, $to]): ?><option value="<?= e($n) ?>"<?= selected($f['level'], $n) ?>><?= e($n) ?> (<?= round($from / 12, 1) + 0 ?><?= $to >= 9999 ? '+' : '–' . (round($to / 12, 1) + 0) ?> yrs)</option><?php endforeach; ?>
      </select></div>
    <div class="field" style="flex:1 1 170px"><label for="qualification">Qualification</label>
      <input type="text" id="qualification" name="qualification" value="<?= e($f['qualification']) ?>" list="quals" placeholder="e.g. CA, B.Com, MBA">
      <datalist id="quals"><?php foreach ($opts['qualifications'] as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></div>
    <div class="field" style="flex:1 1 170px"><label for="responded">Details request</label>
      <select id="responded" name="responded">
        <?php foreach (['' => 'Any', 'yes' => 'Responded', 'waiting' => 'Asked, no answer yet', 'never' => 'Never asked'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($f['responded'], $k) ?>><?= $v ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="flex:0 1 150px"><label for="sort">Sort by</label>
      <select id="sort" name="sort">
        <?php foreach (['' => 'Newest first', 'exp_desc' => 'Most experience', 'exp_asc' => 'Least experience', 'name' => 'Name'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($f['sort'], $k) ?>><?= $v ?></option><?php endforeach; ?>
      </select></div>
  </div>
  <details<?= $moreOpen ? ' open' : '' ?>>
    <summary class="small">More filters: skill, location, notice, salary, source, date added</summary>
    <div class="row" style="align-items:flex-end;margin-top:12px">
      <div class="field" style="flex:1 1 170px"><label for="skill">Skill</label>
        <select id="skill" name="skill"><option value="">Any</option>
          <?php foreach ($opts['skills'] as $s): ?><option value="<?= e($s['skill']) ?>"<?= selected($skill, $s['skill']) ?>><?= e($s['skill']) ?> (<?= (int) $s['n'] ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="field" style="flex:1 1 150px"><label for="location">Location</label>
        <select id="location" name="location"><option value="">Any</option>
          <?php foreach ($opts['locations'] as $p): ?><option value="<?= e($p) ?>"<?= selected($f['location'], $p) ?>><?= e($p) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" style="flex:0 1 130px"><label for="notice_max">Notice up to</label><input type="number" id="notice_max" name="notice_max" min="0" max="365" value="<?= e($f['notice_max']) ?>" placeholder="days"></div>
      <div class="field" style="flex:0 1 140px"><label for="ctc_max">Would accept up to</label><input type="number" id="ctc_max" name="ctc_max" min="0" step="0.5" value="<?= e($f['ctc_max']) ?>" placeholder="LPA"></div>
      <div class="field" style="flex:0 1 170px"><label for="source">Came from</label>
        <select id="source" name="source"><option value="">Any</option>
          <?php foreach ($opts['sources'] as $p): ?><option value="<?= e($p) ?>"<?= selected($f['source'], $p) ?>><?= e(channel_name($p)) ?></option><?php endforeach; ?>
          <option value="none"<?= selected($f['source'], 'none') ?>>Not recorded</option>
        </select></div>
      <div class="field" style="flex:0 1 150px"><label for="added">Added</label>
        <select id="added" name="added"><option value="">Any time</option>
          <?php foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 3 months', '365' => 'Last year'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($f['added'], (string) $k) ?>><?= $v ?></option><?php endforeach; ?>
        </select></div>
    </div>
  </details>
  <div class="actions" style="margin-top:12px">
    <button class="btn" type="submit">Search</button>
    <?php if ($filtered): ?><a class="btn ghost" href="/candidates">Clear all</a><?php endif; ?>
    <a class="btn ghost" href="/candidates?future=1"<?= $f['future'] === '1' ? ' aria-current="page"' : '' ?>><span class="chip keep" style="margin:0">Kept for a future requirement</span></a>
  </div>
  <?php if ($f['future'] === '1'): ?><p class="hint" style="margin:10px 0 0">Showing people you kept for a future requirement on any role. Open one to read your note on why they are worth keeping.</p><input type="hidden" name="future" value="1"><?php endif; ?>
  <?php if ($f['unreliable'] === '1'): ?><p class="hint" style="margin:10px 0 0">Showing people marked unreliable: they did not turn up, stopped responding, or backed out. They are left out of searches, shortlists, screening and interview invitations.</p><input type="hidden" name="unreliable" value="1"><?php endif; ?>
  <?php if ($f['removal'] === '1'): ?><p class="hint" style="margin:10px 0 0">Showing people who asked us not to keep their details. Open each one and delete them (choosing "they asked to be removed") once they are no longer needed for a role in progress.</p><input type="hidden" name="removal" value="1"><?php endif; ?>
  <?php if ($numFilter): ?><p class="hint" style="margin:10px 0 0">Experience is counted to today: if a CV said 6 years two years ago, it counts as 8. Candidates whose experience, notice or salary isn't known are left out of those filters; fill them in on the candidate page to include them.</p><?php endif; ?>
</form>

<?php if (!$rows): ?>
  <div class="card empty"><?= $filtered ? 'Nobody matches that yet. Try widening the experience range or clearing a filter.' : 'No candidates yet. <a href="/upload">Upload some CVs</a> to get started.' ?></div>
<?php else: ?>
  <form method="post" action="/candidates/bulk-add"><?= csrf() ?>
    <input type="hidden" name="back" value="/candidates?<?= e($query(['page' => $page > 1 ? $page : ''])) ?>">
    <div class="table-wrap"><table class="list">
      <?php $kfAll = (new \Eshcol\Ats\Services\KeyFacts(\Eshcol\Ats\App::get()))->forCandidates(array_column($rows, 'id')); ?>
      <thead><tr><th style="width:34px"><input type="checkbox" data-all aria-label="Select all on this page"></th><th>Candidate</th><th>Positions <span class="hint">and stage</span></th><th>Current position</th><th>Experience today</th><th>Level</th><th>Location</th><th>Qualification</th><th>Notice</th><th>Salary <span class="hint">now → expects</span></th><th>Details</th><th>CV</th><th>Added</th></tr></thead>
      <tbody><?php foreach ($rows as $c): $sk = $c['skills'] ? explode('|', $c['skills']) : []; $m = exp_now($c); $kf = $kfAll[(int) $c['id']] ?? []; ?>
        <tr>
          <td><?php if (!$c['do_not_contact'] && empty($c['unreliable_at'])): ?><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="Select <?= e($c['full_name'] ?: 'candidate') ?>"><?php endif; ?></td>
          <td><a href="/candidates/<?= $c['id'] ?>"><b><?= e($c['full_name'] ?: 'Name not found') ?></b></a> <span class="ref small"><?= ref_candidate($c['id']) ?></span>
            <?php if ($c['do_not_contact']): ?> <span class="badge cancelled">do not contact</span><?php endif; ?>
            <?php if (!empty($c['unreliable_at'])): ?> <span class="badge cancelled" title="<?= e(\Eshcol\Ats\Services\Reliability::describe($c)) ?>">unreliable</span><?php endif; ?>
            <?php if ($sk): ?><br><?php foreach (array_slice($sk, 0, 3) as $s): ?><span class="chip"><?= e($s) ?></span><?php endforeach; ?><?= count($sk) > 3 ? '<span class="small muted">+' . (count($sk) - 3) . '</span>' : '' ?><?php endif; ?>
            <?php if ($c['keep_note']): ?><br><span class="chip sand"><?= e($c['keep_note']) ?></span><?php endif; ?></td>
          <td><?php foreach (($kf['positions'] ?? []) as $po): ?><a href="/candidates/<?= (int) $c['id'] ?>?role=<?= (int) $po['role_id'] ?>" style="text-decoration:none"><?= \Eshcol\Ats\Services\KeyFacts::stageChip((string) $po['stage'], (string) $po['stage_kind'], (string) $po['title']) ?></a><?php endforeach; ?><?= empty($kf['positions']) ? '<span class="muted small">none open</span>' : '' ?></td>
          <td><?= e($c['current_title'] ?: '–') ?><?= $c['current_company'] ? '<br><span class="small muted">' . e($c['current_company']) . '</span>' : '' ?></td>
          <td class="nowrap"><?= e(experience($m)) ?><?= exp_is_estimate($c) ? ' <span class="small muted" title="Grown from the figure in the CV to today">est.</span>' : '' ?></td>
          <td class="nowrap"><?= $m === null ? '–' : e(level_name($m)) ?></td>
          <td><?= e(\Eshcol\Ats\Services\Commute::place($c['locality'] ?? null, $c['location']) ?: '–') ?></td>
          <td class="small"><?= e($c['qualifications'] ?: '–') ?></td>
          <td class="nowrap"><?= $c['notice_days'] === null ? '–' : (int) $c['notice_days'] . ' d' ?></td>
          <td class="nowrap"><?= $c['ctc_current_lpa'] === null ? '?' : e(lpa($c['ctc_current_lpa'])) ?> → <?= $c['ctc_expected_lpa'] === null ? '?' : e(lpa($c['ctc_expected_lpa'])) ?><?= $c['ctc_min_lpa'] !== null ? '<br><span class="small muted">min ' . e(lpa($c['ctc_min_lpa'])) . '</span>' : '' ?></td>
          <td class="nowrap"><?= !empty($kf['responded']) ? '<span class="kf k-resp-' . e($kf['responded']) . '">' . e(\Eshcol\Ats\Services\KeyFacts::RESPONDED[$kf['responded']]) . ($kf['responded'] === 'answered' && $kf['responded_at'] ? ' ' . e(date('j M', strtotime((string) $kf['responded_at']))) : '') . '</span>' : '<span class="muted small">not asked</span>' ?>
            <?php if ($kf && ($ct = \Eshcol\Ats\Services\KeyFacts::contact($kf))): ?><br><span class="kf k-msg-<?= e($ct[0]) ?>"><?= e($ct[1]) ?></span><?php endif; ?></td>
          <td><?= $kf ? \Eshcol\Ats\Services\KeyFacts::cvButton($kf) : '' ?></td>
          <td class="nowrap small"><?= e(nice_date($c['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if ($opts['roles']): ?>
      <div class="card bulk-bar">
        <div class="row" style="align-items:flex-end">
          <div class="field" style="flex:2 1 260px;margin:0"><label for="bulk-role">Add the ticked candidates to</label>
            <select id="bulk-role" name="role_id" required><option value="">Choose a role…</option>
              <?php foreach ($opts['roles'] as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['title']) ?> · <?= $r['is_pool'] ? 'talent pool' : e($r['client']) ?></option><?php endforeach; ?>
            </select></div>
          <div><button class="btn" type="submit">Add and score</button></div>
        </div>
        <p class="hint" style="margin:8px 0 0">They are scored against that role straight away. Nobody is emailed.</p>
      </div>
    <?php endif; ?>
  </form>
  <script type="module" src="<?= asset('cv-viewer.js') ?>"></script>
  <?php if ($total > $per): $pages = (int) ceil($total / $per); ?>
    <p class="actions" style="margin-top:14px">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a class="btn small <?= $p === $page ? '' : 'ghost' ?>" href="/candidates?<?= e($query(['page' => $p])) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </p>
  <?php endif; ?>
<?php endif; ?>
