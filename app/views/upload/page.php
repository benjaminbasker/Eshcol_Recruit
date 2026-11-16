<div class="page-head">
  <div><p class="eyebrow">Add candidates</p><h1>Upload CVs</h1>
    <p class="muted">PDF, Word, text or photos of CVs, up to <?= (int) $maxMb ?> MB each, or a zip of many. Each one is read, checked against people already on file, and scored if you choose a role.</p></div>
</div>
<div class="split">
  <div>
    <div class="card">
      <div class="field">
        <?php $contractor = \Eshcol\Ats\Access::restricted(\Eshcol\Ats\Auth::user()); ?>
        <label for="role">Add them to <?= $contractor ? 'your position' : 'a role <span class="hint">optional</span>' ?></label>
        <select id="role"<?= $contractor ? ' required' : '' ?>>
          <?php if (!$contractor): ?><option value="">No role, just add to the database</option><?php endif; ?>
          <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"<?= selected($roleId, $r['id']) ?>><?= e($r['title']) ?> · <?= e($r['client']) ?> (<?= e($r['code']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="channel">Where are these CVs from? <span class="hint">helps you see which job board brings the hires</span></label>
        <select id="channel">
          <option value="">Not sure (Naukri downloads are recognised by their file name)</option>
          <?php foreach (CHANNELS as $k => $v): if ($k === 'email' || $k === 'database') { continue; } ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (!$contractor): ?>
      <div class="field">
        <label for="batch_label">Label <span class="hint">optional, e.g. "Indeed old CVs": helps you find this upload later</span></label>
        <input type="text" id="batch_label" maxlength="120">
      </div>
      <div class="field"><label style="font-weight:normal"><input type="checkbox" id="older"> <b>These are older CVs</b></label>
        <p class="hint">For CVs from months or years ago. If the person is already on file, the CV we have stays their latest (unless this one is clearly dated later), and the old one only fills in details we're missing. Duplicates are never added twice either way.</p></div>
      <?php endif; ?>
      <?php if ($ackOn): ?>
      <div class="field"><label style="font-weight:normal"><input type="checkbox" id="send_ack" checked> <b>Send each applicant a thank-you email</b></label>
        <p class="hint">Once per role, about 10 minutes after upload, with the role's screening questions if that is switched on in Settings. Uploads with no role never send one. Untick for old CVs added to a role, where a thank-you weeks later would look odd. Doubtful addresses wait <?= \Eshcol\Ats\Access::restricted(\Eshcol\Ats\Auth::user()) ? 'for the Eshcol team to check' : 'for you under <a href="/emails">Emails</a>' ?>.</p></div>
      <?php endif; ?>
      <label class="drop" id="drop" for="files">
        <b>Drop CVs here</b>
        <span class="muted">or click to choose files. You can select many at once.</span>
        <input type="file" id="files" multiple accept=".pdf,.doc,.docx,.txt,.rtf,.jpg,.jpeg,.png,.zip" hidden>
      </label>
      <div id="progress-wrap" hidden>
        <div class="progress"><i id="bar"></i></div>
        <p class="small muted" id="progress-text"></p>
      </div>
    </div>
    <div id="results" hidden>
      <h2>Results</h2>
      <p class="actions small" id="summary"></p>
      <div class="table-wrap"><table class="list"><thead><tr><th>File</th><th>Result</th><th>Candidate</th></tr></thead><tbody id="rows"></tbody></table></div>
      <p class="actions" style="margin-top:16px" id="after"></p>
    </div>

    <?php if ($history):
      $label = ['new' => ['New candidate', 'yes'], 'updated' => ['Added to someone on file', ''], 'duplicate_file' => ['Already on file', ''], 'older_copy' => ['On file with a newer CV', ''],
          'needs_ocr' => ['Scan: read it on their page', 'sand'], 'failed' => ['Could not be read', 'bad'], 'skipped' => ['Skipped', 'bad']]; ?>
      <h2 style="margin-top:26px"><?= icon('clock') ?> Recent uploads</h2>
      <?php foreach ($history as $i => $b): ?>
        <details class="card" id="batch-<?= (int) $b['id'] ?>" style="padding:12px 16px"<?= $i === 0 || (int) ($_GET['all'] ?? 0) === (int) $b['id'] ? ' open' : '' ?>>
          <summary style="cursor:pointer"><b><?= e(nice_date($b['created_at'], true)) ?></b><?= !empty($b['label']) ? ' <b>· ' . e($b['label']) . '</b>' : '' ?>
            <span class="small muted"><?= !empty($b['older']) ? '· older CVs ' : '' ?>· <?= (int) $b['files'] ?> file<?= (int) $b['files'] === 1 ? '' : 's' ?><?= $b['role'] ? ' · ' . e($b['role']) : ' · no role' ?><?= $b['who'] ? ' · ' . e($b['who']) : '' ?></span>
            <span class="small" style="display:block;margin-top:4px">
              <?php foreach ($label as $k => [$text, $tone]): if (!empty($b['counts'][$k])): ?><span class="chip <?= $tone ?>"><?= (int) $b['counts'][$k] ?> <?= e(mb_strtolower($text)) ?></span> <?php endif; endforeach; ?>
            </span>
          </summary>
          <div class="table-wrap" style="margin-top:10px"><table class="list small">
            <thead><tr><th>File</th><th>Result</th><th>Candidate</th></tr></thead>
            <tbody>
            <?php foreach ($b['items'] as $it): [$text, $tone] = $label[$it['outcome']] ?? [$it['outcome'], '']; ?>
              <tr>
                <td><?= e($it['filename']) ?></td>
                <td><span class="chip <?= $tone ?>"><?= e($text) ?></span><?php if ($it['message']): ?><br><span class="muted"><?= e($it['message']) ?></span><?php endif; ?></td>
                <td><?= $it['candidate_id'] ? '<a href="/candidates/' . (int) $it['candidate_id'] . '">' . e($it['full_name'] ?: 'Name not found') . '</a> <span class="ref">' . e(ref_candidate($it['candidate_id'])) . '</span>' : '–' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php if (!empty($b['more'])): ?><p class="small" style="margin:8px 0 0"><a href="/upload?all=<?= (int) $b['id'] ?>#batch-<?= (int) $b['id'] ?>">Show all <?= (int) $b['files'] ?> files</a> <span class="muted">(<?= (int) $b['more'] ?> more)</span></p><?php endif; ?>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <aside>
    <div class="card sand">
      <h3>What happens to each CV</h3>
      <ol class="small" style="padding-left:18px;margin:0">
        <li>Stored privately. CVs are never reachable from the internet.</li>
        <li>The text is read on your server, free. Scans are flagged to read in your browser.</li>
        <li>Name, email, phone, experience, CTC, notice, location, qualifications and skills are picked out. Anything estimated is marked for you to confirm.</li>
        <li>If the email or phone matches someone already on file, the CV is added to them rather than creating a duplicate.</li>
        <li>With a role chosen, each candidate is scored against its requirements straight away.</li>
      </ol>
    </div>
  </aside>
</div>
<script src="<?= asset('upload.js') ?>" data-max-mb="<?= (int) $maxMb ?>"></script>
