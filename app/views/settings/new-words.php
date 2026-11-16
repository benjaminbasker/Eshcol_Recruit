<?php
/** @var array $words @var array $canonicals @var array $areas */
$kinds = \Eshcol\Ats\Services\NewTerms::KINDS;
?>
<div class="page-head"><div><p class="eyebrow"><a href="/settings#dictionary">Settings</a> › Skills dictionary</p><h1>New words from CVs</h1>
  <p class="muted">Job titles and software that Claude read in CVs but the dictionary doesn't know yet, most-mentioned first. Add the useful ones and the free check, search and talent pools recognise them from then on. Ignore the rest: they won't come back.</p></div></div>

<datalist id="nw-titles"><?php foreach ($canonicals as $c): if ($c['category'] === 'designation'): ?><option value="<?= e($c['canonical']) ?>"><?php endif; endforeach; ?></datalist>
<datalist id="nw-skills"><?php foreach ($canonicals as $c): if ($c['category'] === 'skill'): ?><option value="<?= e($c['canonical']) ?>"><?php endif; endforeach; ?></datalist>

<form method="post" action="/settings/new-words" style="margin:0 0 12px"><?= csrf() ?><input type="hidden" name="action" value="scan">
  <button class="btn ghost small" type="submit">Check all CVs Claude has read again</button> <span class="small muted">Free: it looks through Claude's readings, nothing is sent to Claude. Words already added or ignored stay as they are.</span></form>
<?php if (!$words): ?>
  <div class="card empty">Nothing waiting. New words appear here as Claude reads more CVs.</div>
<?php else: ?>
<section class="card">
  <p class="small" style="margin-top:0">To file a word under a name the dictionary already has (e.g. "Accts Executive" under "Accounts Executive"), type or pick that name before pressing Add. Left as it is, the word becomes a name of its own.</p>
  <form method="post" action="/settings/new-words" id="nw-bulk"><?= csrf() ?></form>
  <div class="table-wrap"><table class="list compact">
    <thead><tr><th><span class="visually-hidden">Tick</span></th><th>Word</th><th>Kind</th><th class="num">CVs</th><th>Add as</th></tr></thead>
    <tbody><?php foreach ($words as $w): $fid = 'nw' . (int) $w['id']; ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= (int) $w['id'] ?>" form="nw-bulk" aria-label="Tick <?= e($w['label']) ?>"></td>
        <td><b><?= e($w['label']) ?></b><?php if ($w['candidate_id']): ?><br><span class="small muted">e.g. <a href="/candidates/<?= (int) $w['candidate_id'] ?>"><?= e($w['full_name'] ?: ref_candidate((int) $w['candidate_id'])) ?></a></span><?php endif; ?></td>
        <td class="small"><?= e($kinds[$w['category']] ?? $w['category']) ?></td>
        <td class="num"><?= (int) $w['seen'] ?></td>
        <td>
          <form method="post" action="/settings/new-words" class="row" style="gap:6px;align-items:center;margin:0;flex-wrap:nowrap"><?= csrf() ?>
            <input type="hidden" name="action" value="add"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <input type="text" name="canonical" value="<?= e($w['label']) ?>" list="<?= $w['category'] === 'designation' ? 'nw-titles' : 'nw-skills' ?>" aria-label="Add <?= e($w['label']) ?> as" style="min-width:160px;flex:1">
            <select name="area" aria-label="Area for <?= e($w['label']) ?>" style="max-width:180px"><option value="">Area (optional)</option><?php foreach ($areas as $a): ?><option><?= e($a) ?></option><?php endforeach; ?></select>
            <button class="btn small" type="submit">Add</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="actions" style="margin-top:12px">
    <button class="btn ghost small" type="submit" form="nw-bulk" name="action" value="add_ticked">Add the ticked ones as they are</button>
    <button class="btn ghost small" type="submit" form="nw-bulk" name="action" value="ignore">Ignore the ticked ones</button>
  </div>
</section>
<?php endif; ?>
