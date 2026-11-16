<?php
/**
 * The free check's keywords for this role (its must-haves), each with every wording the dictionary
 * knows, and Claude's suggested extra wordings waiting to be accepted (10 Nov 2026). Needs $rid, $fit.
 */
$wdb = \Eshcol\Ats\App::get()->db;
$wMusts = array_column($wdb->all("SELECT value FROM role_criteria WHERE role_id = ? AND kind = 'must' ORDER BY sort, id", [$rid]), 'value');
$wSkills = new \Eshcol\Ats\Services\Skills($wdb);
$wPending = (new \Eshcol\Ats\Services\NewTerms(\Eshcol\Ats\App::get()))->pending('role', $rid);
$wBy = [];
foreach ($wPending as $p) {
    $wBy[(string) $p['canonical']][] = $p;
}
?>
<?php if ($wMusts): ?>
<div class="card" id="wordings">
  <h2 style="margin-top:0">Must-have keywords and their wordings</h2>
  <p class="small muted" style="margin:-6px 0 10px">The free check looks for these in each CV, in any of these wordings, before a CV goes to Claude. A CV that writes a skill differently (e.g. "EDMS" for document control) is only found when that wording is here.</p>
  <ul class="small" style="margin:0 0 10px;padding-left:18px">
    <?php foreach ($wMusts as $m): $terms = array_values(array_filter($wSkills->termsFor($m), fn ($t) => $t !== mb_strtolower($m))); ?>
      <li><b><?= e($m) ?></b><?= $terms ? ': <span class="muted">' . e(implode(', ', array_slice($terms, 0, 14))) . (count($terms) > 14 ? ' … +' . (count($terms) - 14) : '') . '</span>' : ' <span class="muted">(only as written)</span>' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($wPending): ?>
    <form method="post" action="/roles/<?= $rid ?>/wordings/accept" style="border-top:1px solid var(--line);padding-top:10px"><?= csrf() ?>
      <p class="small" style="margin:0 0 6px"><b>Claude suggests these other wordings.</b> Tick the ones that mean the same; the rest are left out.</p>
      <?php foreach ($wBy as $must => $list): ?>
        <div class="small" style="margin:0 0 6px"><b><?= e($must) ?>:</b>
          <?php foreach ($list as $p): ?><label style="font-weight:normal;display:inline-flex;align-items:center;gap:4px;margin:2px 10px 2px 0"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" checked> <?= e($p['label']) ?></label><?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <button class="btn small" type="submit">Add the ticked wordings</button>
    </form>
  <?php else: ?>
    <form method="post" action="/roles/<?= $rid ?>/wordings"><?= csrf() ?>
      <button class="btn ghost small" type="submit"<?= $fit['on'] ? '' : ' disabled' ?> data-confirm-click="Ask Claude for other ways CVs write these keywords? About ₹1. Nothing is added until you tick and accept.">Suggest other wordings with Claude</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
