<?php
/**
 * "Found in your database" on a position (13 Nov 2026): everyone on file whose field, experience and
 * location fit, who isn't on the position yet. Nothing happens until you add them.
 * @var array $found ['fits' => rows, 'elsewhere' => rows] @var array $role @var string $catMode
 */
$app = \Eshcol\Ats\App::get();
$fnName = fn (?string $c) => \Eshcol\Ats\Services\Categoriser::functionName($app, $c);
$sen = \Eshcol\Ats\Services\Categoriser::SENIORITY;
$person = function (array $p) use ($fnName, $sen): string {
    $bits = array_filter([experience(exp_now($p)), $p['location'] ?: 'city not known', $p['seniority'] ? ($sen[$p['seniority']] ?? '') : '',
        $p['specialisation'] ?: $fnName($p['function_code']), $p['ctc_min_lpa'] !== null ? 'would accept ' . lpa($p['ctc_min_lpa']) : ($p['ctc_expected_lpa'] !== null ? 'expects ' . lpa($p['ctc_expected_lpa']) : '')]);
    return e(implode(' · ', $bits));
};
$fits = $found['fits'];
$else = $found['elsewhere'];
?>
<details class="card accent" id="found"<?= $fits ? ' open' : '' ?> style="margin-bottom:16px">
  <summary><b><?= icon('search', 'sm') ?> Found in your database: <?= count($fits) ?></b>
    <span class="small muted">in this position's field, experience range and location<?= $else ? ', plus ' . count($else) . ' living elsewhere' : '' ?>. Free; nobody is added or emailed until you choose.<?= $catMode === 'trial' ? ' (Trial: from the new categories.)' : '' ?></span></summary>
  <?php if ($fits): ?>
    <form method="post" action="/roles/<?= (int) $role['id'] ?>/found" id="found-add"><?= csrf() ?></form>
    <div class="table-wrap" style="margin-top:12px"><table class="list compact">
      <thead><tr><th><span class="visually-hidden">Tick</span></th><th>Candidate</th><th>Why they fit</th><th><span class="visually-hidden">Not this one</span></th></tr></thead>
      <tbody><?php foreach ($fits as $p): ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" form="found-add" aria-label="Tick <?= e($p['full_name'] ?: 'candidate') ?>"></td>
          <td><a href="/candidates/<?= (int) $p['id'] ?>"><b><?= e($p['full_name'] ?: 'Name not found') ?></b></a> <span class="small muted"><?= e(ref_candidate((int) $p['id'])) ?></span><br><span class="small"><?= $person($p) ?></span></td>
          <td class="small"><?= $p['in_band'] ? 'In the experience band' : 'Within the allowance' ?><?= $p['why'] !== '' ? ' · ' . e($p['why']) : '' ?><?= $p['cat_source'] === 'recruiter' ? ' · category checked by you' : ($p['confidence'] === 'low' ? ' · category unsure' : '') ?></td>
          <td><form method="post" action="/roles/<?= (int) $role['id'] ?>/found" style="margin:0"><?= csrf() ?><input type="hidden" name="action" value="dismiss"><input type="hidden" name="candidate_id" value="<?= (int) $p['id'] ?>"><button class="btn ghost small" type="submit">Not this one</button></form></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <div class="actions" style="margin-top:12px">
      <button class="btn small" type="submit" form="found-add" name="action" value="add">Add the ticked ones</button>
      <button class="btn ghost small" type="submit" form="found-add" name="action" value="add_all">Add all <?= count($fits) ?></button>
      <span class="small muted">Added people are scored, and Claude assesses the relevant ones in the next half-price batch (about ₹0.9 each).</span>
    </div>
  <?php endif; ?>
  <?php if ($else): ?>
    <details style="margin-top:14px"><summary class="small"><b>Living elsewhere (<?= count($else) ?>)</b>: right field and experience, but this position takes local candidates only</summary>
      <ul class="small" style="margin:8px 0 0;padding-left:18px">
        <?php foreach ($else as $p): ?><li><a href="/candidates/<?= (int) $p['id'] ?>"><?= e($p['full_name'] ?: 'Name not found') ?></a>: <?= $person($p) ?></li><?php endforeach; ?>
      </ul>
      <p class="small muted" style="margin:8px 0 0">If the client would take someone who relocates, change "Who can we consider?" on the position, or add a person from their page.</p>
    </details>
  <?php endif; ?>
</details>
