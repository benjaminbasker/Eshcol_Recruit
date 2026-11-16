<?php
/** The client asks for a new position. @var array $me @var array $old @var ?string $error */
$v = fn (string $k) => e((string) ($old[$k] ?? ''));
?>
<section class="pub-card">
  <p class="eyebrow"><a href="/portal">Your positions</a></p>
  <h1>Ask for a new position</h1>
  <p>Tell us what you need. Only the job title is required: give what you have, and we will come back to you for the rest before we start the search.</p>
  <?php if ($error): ?><div class="flash error" role="alert"><div><?= e($error) ?></div></div><?php endif; ?>
  <form method="post" action="/portal/new"><?= csrf() ?>
    <div class="field"><label for="np-title">Job title</label><input type="text" id="np-title" name="title" required maxlength="160" value="<?= $v('title') ?>" placeholder="e.g. Accounts Officer" style="width:100%"></div>
    <div class="row">
      <div class="field" style="flex:2 1 180px"><label for="np-loc">Location</label><input type="text" id="np-loc" name="location" maxlength="160" value="<?= $v('location') ?>" placeholder="e.g. Pune"></div>
      <div class="field" style="flex:1 1 150px"><label for="np-mode">Work mode</label><select id="np-mode" name="work_mode"><option value="">Not decided</option>
        <?php foreach (WORK_MODES as $k => $l): ?><option value="<?= $k ?>"<?= ($old['work_mode'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="flex:0 1 110px"><label for="np-pos">Positions</label><input type="number" id="np-pos" name="positions" min="1" max="99" value="<?= $v('positions') ?: 1 ?>"></div>
    </div>
    <div class="row">
      <div class="field" style="flex:1 1 120px"><label for="np-min">Experience from <span class="hint">years</span></label><input type="number" id="np-min" name="exp_min" min="0" max="50" step="0.5" value="<?= $v('exp_min') ?>"></div>
      <div class="field" style="flex:1 1 120px"><label for="np-max">to <span class="hint">years</span></label><input type="number" id="np-max" name="exp_max" min="0" max="50" step="0.5" value="<?= $v('exp_max') ?>"></div>
      <div class="field" style="flex:1 1 150px"><label for="np-ctc">Budget up to <span class="hint">lakhs per annum</span></label><input type="number" id="np-ctc" name="ctc_max_lpa" min="0" max="500" step="0.1" value="<?= $v('ctc_max_lpa') ?>" placeholder="e.g. 6"></div>
      <div class="field" style="flex:1 1 150px"><label for="np-notice">Longest notice you can wait <span class="hint">days</span></label><input type="number" id="np-notice" name="notice_max_days" min="0" max="365" value="<?= $v('notice_max_days') ?>" placeholder="e.g. 30"></div>
    </div>
    <div class="field"><label for="np-jd">Job description <span class="hint">paste it here, or describe the work and what the person must know</span></label>
      <textarea id="np-jd" name="jd_text" rows="9" maxlength="60000" style="width:100%"><?= $v('jd_text') ?></textarea></div>
    <div class="field"><label for="np-note">Anything else we should know <span class="hint">optional</span></label>
      <textarea id="np-note" name="note" rows="3" maxlength="2000" style="width:100%" placeholder="e.g. Replacement for someone leaving on 30 November; reports to the Finance Manager"><?= $v('note') ?></textarea></div>
    <button class="btn pub-submit" type="submit">Send this request</button>
  </form>
</section>
