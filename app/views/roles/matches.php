<div class="page-head">
  <div><p class="eyebrow"><?= e($role['client']) ?> · <?= e($role['code']) ?></p>
    <h1>Already in your database</h1>
    <p class="muted">Candidates on file who fit <b><?= e($role['title']) ?></b>, best first. People who asked not to be contacted, or whose time on file has run out, are left out.</p></div>
  <div class="actions">
    <?php if ($results): ?>
      <form method="post" action="/roles/<?= $role['id'] ?>/add-all"><?= csrf() ?><button class="btn" type="submit" data-confirm-click="Add every relevant match (same field, experience inside the allowance) to this role? Claude then assesses them, about ₹1–2 each.">Add all relevant; Claude assesses them</button></form>
    <?php endif; ?>
    <a class="btn ghost" href="/roles/<?= $role['id'] ?>">Back to the role</a>
  </div>
</div>
<?php if (!$results): ?>
  <div class="card empty">No matches on file yet. As your database grows, this list fills up on its own.</div>
<?php else: ?>
  <div class="table-wrap"><table class="list">
    <thead><tr><th>Score</th><th>Candidate</th><th></th></tr></thead>
    <tbody>
    <?php prime_stages(array_map(fn ($x) => $x['cand']['id'], $results)); foreach ($results as $m): $c = $m['cand'];
      $stale = $c['last_confirmed_at'] ? strtotime($c['last_confirmed_at']) < strtotime("-$reconfirmMonths months") : strtotime($c['created_at']) < strtotime("-$reconfirmMonths months"); ?>
      <tr>
        <td style="width:70px"><span class="score <?= score_class($m['score'], (int) $role['score_threshold']) ?>"><?= $m['score'] ?></span></td>
        <td><a href="/candidates/<?= $c['id'] ?>"><b><?= e($c['full_name'] ?: 'Name not found') ?></b></a> <?= stage_tags($c['id']) ?>
          <?php if ($stale): ?> <span class="confirm" title="Details are more than <?= (int) $reconfirmMonths ?> months old">reconfirm</span><?php endif; ?>
          <?php if ($c['keep_note']): ?><br><span class="chip sand"><?= e($c['keep_note']) ?></span><?php endif; ?>
          <br><span class="small muted"><?= e(experience(exp_now($c))) ?><?= exp_is_estimate($c) ? ' (est.)' : '' ?> · <?= e($c['location'] ?: 'location unknown') ?> · on file since <?= e(nice_date($c['created_at'])) ?></span>
          <details style="margin-top:6px"><summary class="small">Why this score</summary><div style="padding-top:8px"><?php $bd = $m['breakdown']; include __DIR__ . '/../partials/scorecard.php'; ?></div></details>
        </td>
        <td style="width:150px">
          <form method="post" action="/roles/<?= $role['id'] ?>/add/<?= $c['id'] ?>"><?= csrf() ?><input type="hidden" name="back" value="matches">
            <button class="btn small" type="submit">Add to role</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
