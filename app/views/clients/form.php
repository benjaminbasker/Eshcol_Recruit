<?php
$c = $client;
$err = fn ($k) => isset($errors[$k]) ? '<p class="hint" style="color:var(--red)">' . e($errors[$k]) . '</p>' : '';
$editing = !empty($c['id']);
?>
<div class="page-head">
  <div><p class="eyebrow">Clients</p><h1><?= $editing ? 'Edit client' : 'New client' ?></h1></div>
</div>
<?php if ($errors): ?><div class="flash error">Please fix the highlighted fields.</div><?php endif; ?>
<form method="post" action="<?= $editing ? '/clients/' . (int) $c['id'] : '/clients' ?>" class="card" style="max-width:860px" enctype="multipart/form-data">
  <?= csrf() ?>
  <fieldset>
    <legend>Company</legend>
    <div class="row">
      <div class="field"><label for="name">Client name</label><input type="text" id="name" name="name" value="<?= e($c['name'] ?? '') ?>" required><?= $err('name') ?></div>
      <div class="field"><label for="legal_name">Registered name <span class="hint">for invoices</span></label><input type="text" id="legal_name" name="legal_name" value="<?= e($c['legal_name'] ?? '') ?>"></div>
    </div>
    <div class="row" style="align-items:center">
      <div style="flex:0 0 auto"><?= client_logo($c + ['name' => $c['name'] ?? 'New client'], 'lg') ?></div>
      <div class="field" style="flex:1 1 260px"><label for="logo">Logo <span class="hint">PNG, JPG or WebP, up to 1 MB. Shown in the app and on the client's shortlist page.</span></label>
        <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
        <?php if (!empty($c['logo_path'])): ?><label style="font-weight:normal;margin-top:6px"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label><?php endif; ?></div>
    </div>
    <div class="row">
      <div class="field"><label for="gstin">GSTIN</label><input type="text" id="gstin" name="gstin" value="<?= e($c['gstin'] ?? '') ?>" maxlength="15"><?= $err('gstin') ?></div>
      <div class="field" style="flex-basis:100%"><label for="billing_address">Billing address</label><textarea id="billing_address" name="billing_address" rows="3"><?= e($c['billing_address'] ?? '') ?></textarea></div>
    </div>
  </fieldset>
  <fieldset>
    <legend>For interview letters</legend>
    <div class="row">
      <div class="field"><label for="website">Website</label><input type="url" id="website" name="website" value="<?= e($c['website'] ?? '') ?>" placeholder="https://www.example.com"><?= $err('website') ?></div>
      <div class="field"><label for="meeting_link">Default online meeting link <span class="hint">Google Meet or Teams</span></label><input type="text" id="meeting_link" name="meeting_link" value="<?= e($c['meeting_link'] ?? '') ?>" placeholder="meet.google.com/abc-defg-hij"><?= $err('meeting_link') ?></div>
    </div>
    <div class="row">
      <div class="field" style="flex-basis:100%"><label for="interview_address">Address for in-person interviews</label><textarea id="interview_address" name="interview_address" rows="2" placeholder="Company name, office, floor, building, street, area"><?= e($c['interview_address'] ?? '') ?></textarea></div>
      <div class="field" style="flex-basis:100%"><label for="maps_url">Google Maps link</label><input type="text" id="maps_url" name="maps_url" value="<?= e($c['maps_url'] ?? '') ?>" placeholder="https://maps.app.goo.gl/..."><?= $err('maps_url') ?></div>
    </div>
    <p class="hint" style="margin:0">Used to fill in your call letters automatically. You can change them for any single interview.</p>
  </fieldset>
  <fieldset>
    <legend>Main contact</legend>
    <div class="row">
      <div class="field"><label for="contact_name">Name</label><input type="text" id="contact_name" name="contact_name" value="<?= e($contact['name'] ?? '') ?>"></div>
      <div class="field"><label for="contact_designation">Designation</label><input type="text" id="contact_designation" name="contact_designation" value="<?= e($contact['designation'] ?? '') ?>"></div>
    </div>
    <div class="row">
      <div class="field"><label for="contact_email">Email</label><input type="email" id="contact_email" name="contact_email" value="<?= e($contact['email'] ?? '') ?>"><?= $err('contact_email') ?></div>
      <div class="field"><label for="contact_phone">Phone</label><input type="text" id="contact_phone" name="contact_phone" value="<?= e($contact['phone'] ?? '') ?>"></div>
    </div>
  </fieldset>
  <fieldset>
    <legend>Commercials</legend>
    <div class="row">
      <div class="field"><label for="fee_type">Fee basis</label>
        <select id="fee_type" name="fee_type">
          <option value="">Not set</option>
          <option value="percent"<?= selected($c['fee_type'] ?? '', 'percent') ?>>Percentage of annual CTC</option>
          <option value="fixed"<?= selected($c['fee_type'] ?? '', 'fixed') ?>>Fixed fee per placement</option>
        </select></div>
      <div class="field"><label for="fee_value">Fee <span class="hint">% or ₹</span></label><input type="number" step="0.01" min="0" id="fee_value" name="fee_value" value="<?= e($c['fee_value'] ?? '') ?>"></div>
      <div class="field"><label for="payment_terms_days">Payment terms <span class="hint">days</span></label><input type="number" min="0" id="payment_terms_days" name="payment_terms_days" value="<?= e($c['payment_terms_days'] ?? '') ?>"></div>
      <div class="field"><label for="replacement_days">Replacement guarantee <span class="hint">days</span></label><input type="number" min="0" id="replacement_days" name="replacement_days" value="<?= e($c['replacement_days'] ?? '') ?>"></div>
    </div>
    <p class="hint" style="margin:0">Only you and your recruiters ever see this. It is never shown to clients or candidates.</p>
  </fieldset>
  <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= e($c['notes'] ?? '') ?></textarea></div>
  <?php if ($editing): ?>
    <div class="field"><label><input type="checkbox" name="is_active" value="1"<?= ($c['is_active'] ?? 1) ? ' checked' : '' ?>> Active client</label></div>
  <?php endif; ?>
  <div class="actions"><button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add client' ?></button><a href="<?= $editing ? '/clients/' . (int) $c['id'] : '/clients' ?>">Cancel</a></div>
</form>
