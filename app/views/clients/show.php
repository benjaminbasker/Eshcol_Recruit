<div class="page-head">
  <div style="display:flex;gap:16px;align-items:center"><?= client_logo($client, 'lg') ?><div><p class="eyebrow"><a href="/clients">Clients</a></p><h1><?= e($client['name']) ?></h1>
    <?php if ($client['legal_name']): ?><p class="muted" style="margin:0"><?= e($client['legal_name']) ?></p><?php endif; ?></div></div>
  <div class="actions"><a class="btn ghost" href="/clients/<?= $client['id'] ?>/edit">Edit</a><a class="btn" href="/roles/new?client=<?= $client['id'] ?>">New role for this client</a></div>
</div>
<div class="split">
  <section>
    <h2>Roles</h2>
    <?php if (!$roles): ?>
      <div class="card empty">No roles yet for this client.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="list">
        <thead><tr><th>Role</th><th>Status</th><th>CVs</th><th>Opened</th></tr></thead>
        <tbody><?php foreach ($roles as $r): ?>
          <tr><td><a href="/roles/<?= $r['id'] ?>"><b><?= e($r['title']) ?></b></a><br><span class="small muted"><?= e($r['code']) ?></span></td>
            <td><span class="badge <?= e($r['status']) ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
            <td><?= (int) $r['cvs'] ?></td><td class="nowrap"><?= e(nice_date($r['opened_at'] ?? $r['created_at'])) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>
  <aside>
    <div class="card">
      <h3>For interview letters</h3>
      <p class="small" style="margin:0"><b>Website:</b> <?= $client['website'] ? '<a href="' . e($client['website']) . '" target="_blank" rel="noopener">' . e(preg_replace('#^https?://(www\.)?#', '', rtrim($client['website'], '/'))) . '</a>' : '<span class="muted">not set</span>' ?><br>
        <b>Online meetings:</b> <?= $client['meeting_link'] ? e(preg_replace('#^https?://#', '', $client['meeting_link'])) : '<span class="muted">not set</span>' ?><br>
        <b>Interview address:</b> <?= $client['interview_address'] ? nl2br(e($client['interview_address'])) : '<span class="muted">not set</span>' ?><?= $client['maps_url'] ? ' · <a href="' . e($client['maps_url']) . '" target="_blank" rel="noopener">map</a>' : '' ?></p>
      <p class="small" style="margin:8px 0 0"><a href="/clients/<?= $client['id'] ?>/edit">Edit these</a></p>
    </div>
    <?php $ownerType = 'client'; $ownerId = (int) $client['id']; $kinds = ['company_profile', 'other']; include __DIR__ . '/../partials/documents.php'; ?>
    <div class="card accent">
      <h3>Contacts</h3>
      <?php if (!$contacts): ?><p class="muted">None added.</p><?php endif; ?>
      <?php $isOwner = ($user['role'] ?? '') === 'owner'; $portalUrl = rtrim((string) (\Eshcol\Ats\App::get()->config['app']['url'] ?? ''), '/') . '/portal'; ?>
      <?php foreach ($contacts as $p): $has = !empty($p['portal_access']); ?>
        <div style="margin:0 0 12px" id="contact-<?= (int) $p['id'] ?>"><b><?= e($p['name']) ?></b><?= $p['designation'] ? ', ' . e($p['designation']) : '' ?><br>
        <span class="small"><?= $p['email'] ? '<a href="mailto:' . e($p['email']) . '">' . e($p['email']) . '</a>' : '' ?> <?= e($p['phone']) ?></span>
        <?php if (array_key_exists('portal_access', $p)): ?>
          <br><span class="small"><?php if ($has): ?><span class="chip yes">Client portal access</span>
            <span class="muted"><?= $p['portal_last_login_at'] ? 'last signed in ' . e(nice_date($p['portal_last_login_at'], true)) : 'invited ' . e(nice_date($p['portal_invited_at'])) . ', not signed in yet' ?></span><?php endif; ?></span>
          <?php if ($isOwner): ?>
            <form method="post" action="/clients/<?= (int) $client['id'] ?>/contacts/<?= (int) $p['id'] ?>/portal" class="actions" style="margin:4px 0 0"><?= csrf() ?>
              <?php if (!$has): ?><button class="btn ghost small" type="submit" name="action" value="invite"<?= $p['email'] ? '' : ' disabled title="Add their email address first"' ?>>Give portal access</button>
              <?php else: ?><button class="btn ghost small" type="submit" name="action" value="invite">Send the invitation again</button>
                <button class="btn link small" type="submit" name="action" value="revoke" data-confirm-click="Take away <?= e($p['name']) ?>'s access to the client portal?">Remove access</button><?php endif; ?>
              <button class="btn ghost small" type="submit" name="action" value="preview" formtarget="_blank" title="Opens the client portal in a new tab, as <?= e($p['name']) ?> sees it. Read-only: nothing you press there is saved."><?= icon('external', 'sm') ?>See what they see</button>
            </form>
          <?php endif; ?>
        <?php endif; ?></div>
      <?php endforeach; ?>
      <details id="portal"<?= $contacts ? '' : ' open' ?>><summary class="small">Add a contact</summary>
        <form method="post" action="/clients/<?= (int) $client['id'] ?>/contacts" style="margin-top:8px"><?= csrf() ?>
          <div class="field"><label for="nc-name">Name</label><input type="text" id="nc-name" name="name" required maxlength="120"></div>
          <div class="field"><label for="nc-email">Email <span class="hint">needed for the portal</span></label><input type="email" id="nc-email" name="email" maxlength="190"></div>
          <div class="field"><label for="nc-des">Designation</label><input type="text" id="nc-des" name="designation" maxlength="120"></div>
          <div class="field"><label for="nc-phone">Phone</label><input type="text" id="nc-phone" name="phone" maxlength="30"></div>
          <button class="btn ghost small" type="submit">Add contact</button>
        </form>
      </details>
      <p class="small muted" style="margin:10px 0 0"><b>Client portal:</b> a contact with access signs in at <a href="<?= e($portalUrl) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', $portalUrl)) ?></a> with their email address and a 6-digit code we email them (no password), and stay signed in for 30 days on their own device. "See what they see" opens it as that person, read-only. They see this client's positions and the candidates you have sent in shortlists, give decisions, offer interview times and ask for new positions. Nothing else.</p>
    </div>
    <div class="card sand">
      <h3>Commercials</h3>
      <p class="small" style="margin:0">Fee: <?= $client['fee_type'] === 'percent' ? e(rtrim(rtrim((string) $client['fee_value'], '0'), '.')) . '% of annual CTC' : ($client['fee_type'] === 'fixed' ? '₹' . e(number_format((float) $client['fee_value'])) . ' per placement' : 'not set') ?><br>
      Payment terms: <?= $client['payment_terms_days'] !== null ? (int) $client['payment_terms_days'] . ' days' : 'not set' ?><br>
      Replacement guarantee: <?= $client['replacement_days'] !== null ? (int) $client['replacement_days'] . ' days' : 'not set' ?><br>
      GSTIN: <?= e($client['gstin'] ?: 'not set') ?></p>
    </div>
    <?php if ($client['notes']): ?><div class="card"><h3>Notes</h3><p class="small" style="white-space:pre-wrap;margin:0"><?= e($client['notes']) ?></p></div><?php endif; ?>
    <?php if (empty($client['is_self'])) { include __DIR__ . '/_staffing_terms.php'; } ?>
  </aside>
</div>
