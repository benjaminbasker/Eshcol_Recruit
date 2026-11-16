<?php /** @var array $iv @var array $a @var array $slots @var string $token @var ?string $error @var ?string $when */
$kinds = \Eshcol\Ats\Services\Interviews::KINDS;
$first = trim((string) ($a['first_name'] ?: strtok((string) $a['full_name'], ' ')));
?>
<section class="pub-card">
  <?php $cl = client_logo($a + ['name' => $a['client_display']], 'pub', '/i/' . $token . '/logo'); if ($cl): ?><div class="pub-for"><?= $cl ?></div><?php endif; ?>
  <p class="eyebrow">Your interview · <?= e($kinds[$iv['kind']]) ?> · round <?= (int) $iv['round'] ?></p>
  <h1><?= e($a['title']) ?></h1>
  <p class="muted" style="margin-top:-4px">with <?= e($a['client_display']) ?></p>
  <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <?php if ($iv['status'] === 'booked' || $iv['status'] === 'done'): ?>
    <div class="flash ok" role="status">Your interview is confirmed. The full details are in the email we have sent you.</div>
    <table class="qa">
      <tr><td>Date &amp; time</td><td><b><?= e($when) ?></b></td></tr>
      <?php if ($iv['kind'] === 'virtual'): ?><tr><td>Join online</td><td><a href="<?= e($iv['meeting_link']) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', (string) $iv['meeting_link'])) ?></a></td></tr><?php endif; ?>
      <?php if ($iv['kind'] === 'in_person'): ?><tr><td>Address</td><td><?= nl2br(e((string) $iv['address'])) ?><?= $iv['maps_url'] ? '<br><a href="' . e($iv['maps_url']) . '" target="_blank" rel="noopener">Open in Google Maps</a>' : '' ?></td></tr><?php endif; ?>
    </table>
    <p style="margin-top:14px"><a class="btn" href="/i/<?= e($token) ?>/ics">Add to my calendar</a></p>
  <?php elseif (!$slots): ?>
    <p>All the offered times have been taken. Please reply to our email, or tell us below when you are free, and we will arrange another time.</p>
  <?php else: ?>
    <p><?= $first !== '' ? 'Hello ' . e(mb_convert_case(mb_strtolower($first), MB_CASE_TITLE)) . ', please' : 'Please' ?> choose the time that suits you best. Times are booked first come, first served.</p>
    <form method="post" action="/i/<?= e($token) ?>"><?= csrf() ?>
      <div class="pub-opts slot-list">
        <?php foreach ($slots as $s): $t = strtotime($s['starts_at']); ?>
          <label class="pub-opt wide"><input type="radio" name="slot" value="<?= (int) $s['id'] ?>" required>
            <span><b><?= date('l j F', $t) ?></b> at <b><?= date('g:i A', $t) ?></b> <span class="muted small">· <?= (int) $s['duration_min'] ?> min · <?= e($kinds[$s['kind']]) ?></span></span></label>
        <?php endforeach; ?>
      </div>
      <button class="btn pub-submit" type="submit">Confirm this time</button>
    </form>
  <?php endif; ?>
</section>
<?php if ($iv['status'] === 'choosing'): ?>
<section class="pub-card" style="margin-top:16px">
  <h2 style="font-size:18px">None of these times work?</h2>
  <form method="post" action="/i/<?= e($token) ?>"><?= csrf() ?><input type="hidden" name="none" value="1">
    <textarea name="message" rows="2" maxlength="1000" placeholder="e.g. I can do any weekday after 4 pm" aria-label="When are you free?" style="width:100%"></textarea>
    <button class="btn ghost" type="submit" style="margin-top:8px">Tell the recruiter</button>
  </form>
</section>
<?php endif; ?>
