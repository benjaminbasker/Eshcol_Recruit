<?php
/** Analyser 6 (15 Nov 2026): "Your examples for this position". Needs $rid and $fit. */
use Eshcol\Ats\Services\Examples;

$exSvc = new Examples(\Eshcol\Ats\App::get());
$examples = $exSvc->forRole($rid);
$exYes = $exSvc->clientYes($rid);
$exBefore = $examples ? count($exSvc->before($rid)) : 0;
?>
<div class="card" id="examples">
  <h2>Your examples for this position (<?= count($examples) ?> of <?= Examples::MAX ?>)</h2>
  <p class="small muted" style="margin:0 0 10px">Mark up to <?= Examples::MAX ?> people as the kind of person you want (<b>☆ Example</b> in Claude's ranking). Claude then reads a short profile of them with every assessment on this position, and says how close each candidate is to them.
    It's shown next to the score, not added to it. No names, contact details, age, gender, college or employer names go to Claude; the employer type does.</p>
  <?php if ($examples): ?>
    <?php foreach ($examples as $e): ?>
      <div style="border-top:1px solid var(--line);padding:10px 0">
        <div class="row" style="align-items:center;justify-content:space-between">
          <div><b>★ <a href="/candidates/<?= $e['candidate_id'] ?>?role=<?= $rid ?>"><?= e($e['name'] ?: 'Name not found') ?></a></b> <span class="ref"><?= ref_candidate($e['candidate_id']) ?></span></div>
          <form method="post" action="/roles/<?= $rid ?>/examples/<?= $e['candidate_id'] ?>"><?= csrf() ?><input type="hidden" name="remove" value="1"><button class="btn ghost small" type="submit">Remove</button></form>
        </div>
        <p class="small" style="margin:6px 0"><span class="muted">What Claude sees:</span> <?= e($e['profile']) ?></p>
        <form method="post" action="/roles/<?= $rid ?>/examples/<?= $e['candidate_id'] ?>/note" class="row" style="align-items:flex-end"><?= csrf() ?>
          <div class="field" style="flex:1;min-width:0;margin:0"><label for="ex-note-<?= $e['id'] ?>">Why they are right, in your words (optional)</label>
            <input type="text" id="ex-note-<?= $e['id'] ?>" name="note" value="<?= e($e['note']) ?>" maxlength="250" placeholder="e.g. CA firm background, handles clients end to end" style="width:100%"></div>
          <button class="btn ghost small" type="submit">Save</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="small" style="margin:0 0 6px">No examples yet. Assessments on this position work exactly as before until you mark one.</p>
  <?php endif; ?>
  <div class="actions" style="margin-top:10px">
    <?php if ($exYes && count($examples) < Examples::MAX): ?>
      <form method="post" action="/roles/<?= $rid ?>/examples/client"><?= csrf() ?><button class="btn ghost small" type="submit">Use the client's yes answers (<?= $exYes ?>)</button></form>
    <?php endif; ?>
    <?php if ($exBefore): ?>
      <form method="post" action="/roles/<?= $rid ?>/examples/reassess"><?= csrf() ?><button class="btn ghost small" type="submit"<?= $fit['on'] ? '' : ' disabled' ?>
        data-confirm-click="Re-assess the <?= $exBefore ?> candidate<?= $exBefore > 1 ? 's' : '' ?> assessed before these examples? They go to Claude in the half-price batch, about ₹<?= number_format($exBefore * 0.93) ?> in all (about ₹0.93 each).">Re-assess them against your examples</button></form>
    <?php endif; ?>
  </div>
  <?php if ($exBefore): ?><p class="small muted" style="margin:6px 0 0"><?= $exBefore ?> candidate<?= $exBefore > 1 ? 's were' : ' was' ?> assessed before these examples. Nobody is re-assessed unless you press the button; new assessments use the examples.</p><?php endif; ?>
</div>
