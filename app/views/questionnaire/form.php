<?php
/** @var array $q @var array $roles @var array $questions @var array $errors @var array $old @var string $first @var string $token */
$parts = [
    1 => ['Check your details', 'This is what we read from your CV. Please correct anything that is wrong or out of date.'],
    2 => ['The details we need', 'These are critical for matching you with ' . \Eshcol\Ats\Services\Engagement::requirements(\Eshcol\Ats\Services\Engagement::forRoles($roles)) . '.'],
    3 => [count($roles) > 1 ? 'About the roles' : 'About the role', ''],
    4 => ['Anything else', ''],
    5 => [\Eshcol\Ats\Services\GapQuestions::HEADING, \Eshcol\Ats\Services\GapQuestions::INTRO],
];
$shown = 0;
$byPart = [];
foreach ($questions as $qq) {
    $byPart[$qq['part']][] = $qq;
}
$phrase = \Eshcol\Ats\Services\Questionnaires::rolePhrase($roles, true);
$n = 0;
?>
<section class="pub-card">
  <p class="eyebrow">Complete your application</p>
  <h1><?= $first !== '' ? 'Hello ' . e($first) . '.' : 'Hello.' ?></h1>
  <p>For <?= $phrase ?>. It takes about two minutes. Your answers go only to the Eshcol Global recruitment team.</p>
  <p class="small muted">Questions marked <span class="pub-req" aria-hidden="true">*</span><span class="visually-hidden">with a star</span> must be answered before you can send.</p>
  <?php if ($errors): ?><div class="flash error" role="alert">A few answers need another look. They're marked below.</div><?php endif; ?>
  <form method="post" action="/q/<?= e($token) ?>" novalidate><?= csrf() ?>
    <?php foreach ($byPart as $part => $qs): $lastRole = null; ?>
      <div class="pub-part">
        <h2><span class="pub-part-n"><?= ++$shown ?></span> <?= e($parts[$part][0]) ?></h2>
        <?php if ($parts[$part][1] !== ''): ?><p class="hint"><?= e($parts[$part][1]) ?></p><?php endif; ?>
        <?php foreach ($qs as $qq): $k = $qq['key']; $id = 'f_' . $k; $err = $errors[$k] ?? null; $val = (string) (array_key_exists($k, $old) ? $old[$k] : ($qq['prefill'] ?? '')); $n++; ?>
          <?php if (!empty($qq['role']) && $qq['role'] !== $lastRole): $lastRole = $qq['role']; ?><p class="pub-role">For the <?= e($qq['role']) ?> role</p><?php endif; ?>
          <fieldset class="pub-q<?= $err ? ' has-error' : '' ?>">
            <legend><?= e($qq['prompt']) ?><?= $qq['required'] ? ' <span class="pub-req" aria-hidden="true">*</span>' : '' ?></legend>
            <?php if (!empty($qq['hint'])): ?><p class="hint"><?= e($qq['hint']) ?></p><?php endif; ?>
            <?php if ($qq['kind'] === 'number'): ?>
              <div class="pub-num"><span>₹</span><input type="text" inputmode="decimal" id="<?= $id ?>" name="<?= e($k) ?>" value="<?= e($val) ?>" aria-label="<?= e($qq['prompt']) ?>" autocomplete="off"<?= $qq['required'] ? ' aria-required="true"' : '' ?>><span>lakhs per annum</span></div>
            <?php elseif ($qq['kind'] === 'years'): ?>
              <div class="pub-num"><input type="text" inputmode="decimal" id="<?= $id ?>" name="<?= e($k) ?>" value="<?= e($val) ?>" aria-label="<?= e($qq['prompt']) ?>" autocomplete="off"><span>years</span></div>
            <?php elseif (in_array($qq['kind'], ['text', 'email', 'tel', 'url'], true)): ?>
              <input type="<?= $qq['kind'] === 'text' ? 'text' : $qq['kind'] ?>" id="<?= $id ?>" name="<?= e($k) ?>" value="<?= e($val) ?>" maxlength="<?= $k === 'full_name' ? 160 : 255 ?>" aria-label="<?= e($qq['prompt']) ?>"<?= $qq['required'] ? ' aria-required="true"' : '' ?><?= ['full_name' => ' autocomplete="name"', 'email' => ' autocomplete="email"', 'phone' => ' autocomplete="tel" inputmode="tel"', 'linkedin' => ' autocomplete="url" inputmode="url"', 'location' => ' autocomplete="address-level2"'][$k] ?? '' ?><?= !empty($qq['suggest']) ? ' list="' . $id . '_list"' : '' ?>>
              <?php if (!empty($qq['suggest'])): ?><datalist id="<?= $id ?>_list"><?php foreach ($qq['suggest'] as $sg): ?><option value="<?= e($sg) ?>"><?php endforeach; ?></datalist><?php endif; ?>
            <?php elseif ($qq['kind'] === 'textarea'): ?>
              <textarea class="pub-text" id="<?= $id ?>" name="<?= e($k) ?>" rows="4" maxlength="2000" aria-label="<?= e($qq['prompt']) ?>"><?= e($val) ?></textarea>
            <?php elseif ($qq['kind'] === 'select'): ?>
              <select class="pub-select" id="<?= $id ?>" name="<?= e($k) ?>" aria-label="<?= e($qq['prompt']) ?>"<?= $qq['required'] ? ' aria-required="true"' : '' ?>>
                <option value=""<?= $val === '' ? ' selected' : '' ?>>Choose one</option>
                <?php foreach ($qq['options'] as $opt): ?><option value="<?= e($opt) ?>"<?= $val === $opt ? ' selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <div class="pub-opts">
                <?php foreach (($qq['options'] ?? ['Yes', 'No']) as $opt): ?>
                  <label class="pub-opt"><input type="radio" name="<?= e($k) ?>" value="<?= e($opt) ?>"<?= $val === $opt ? ' checked' : '' ?>> <span><?= e($opt) ?></span></label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($err): ?><p class="pub-err"><?= e($err) ?></p><?php endif; ?>
          </fieldset>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <fieldset class="pub-q pub-keep">
      <legend>Your details on file</legend>
      <label class="pub-opt wide"><input type="checkbox" name="keep" value="1"<?= ($old === [] || !empty($old['keep'])) ? ' checked' : '' ?>> <span>Keep my details on file, so Eshcol Global can tell me about other roles with its clients that may suit me over the next five years.</span></label>
      <p class="hint">Untick this if you would rather we deleted your details once this role is filled. See our <a href="https://www.eshcolglobal.com/privacy/">privacy policy</a>.</p>
    </fieldset>
    <button class="btn pub-submit" type="submit">Send my answers</button>
  </form>
</section>
