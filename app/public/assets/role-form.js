/* Role form: suggest requirements from the pasted JD (dictionary only, no AI). */
(function () {
  'use strict';
  var btn = document.getElementById('suggest-btn');
  if (!btn) { return; }
  var msg = document.getElementById('suggest-msg');

  function merge(id, items) {
    var box = document.getElementById(id);
    var have = box.value.split(/\n/).map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    var add = items.filter(function (i) { return have.indexOf(i.toLowerCase()) === -1; });
    if (add.length) { box.value = (box.value.trim() ? box.value.trim() + '\n' : '') + add.join('\n'); }
    return add.length;
  }

  btn.addEventListener('click', function () {
    var jd = document.getElementById('jd_text').value.trim();
    if (!jd) { msg.textContent = 'Paste the JD first.'; return; }
    btn.disabled = true;
    msg.textContent = 'Reading the JD…';
    var body = new FormData();
    body.append('jd', jd);
    body.append('_csrf', window.EshcolCsrf());
    fetch('/roles/suggest', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (d) {
        var n = merge('must_haves', d.skills) + merge('qualifications', d.qualifications);
        var loc = document.getElementById('location');
        if (!loc.value && d.locations.length) { loc.value = d.locations[0]; n++; }
        if (d.locations.length > 1) { n += merge('extra_locations', d.locations.slice(1)); }
        if (d.experience) {
          var mn = document.getElementById('exp_min'), mx = document.getElementById('exp_max');
          if (!mn.value && d.experience[0] !== null) { mn.value = d.experience[0]; n++; }
          if (!mx.value && d.experience[1] !== null) { mx.value = d.experience[1]; n++; }
        }
        msg.textContent = n ? 'Added ' + n + ' suggestion' + (n > 1 ? 's' : '') + '. Move any that are only nice-to-have, and add what the dictionary missed.' : 'Nothing new found. Add requirements by hand.';
      })
      .catch(function () { msg.textContent = 'Could not read the JD just now.'; })
      .then(function () { btn.disabled = false; });
  });
})();

/* Role form: read a JD file, fill the role from it, and outline in red what is still needed. */
(function () {
  'use strict';
  var form = document.getElementById('role-form');
  if (!form) { return; }
  var drop = document.getElementById('jd-drop');
  var input = document.getElementById('jd_file');
  var jdMsg = document.getElementById('jd-msg');
  var formMsg = document.getElementById('form-msg');
  var status = document.getElementById('status');
  var $ = function (id) { return document.getElementById(id); };

  function isDraft() { return status && status.value === 'draft'; }
  function empty(el) { return !String(el.value || '').trim(); }

  // Outline required fields that are empty. A draft only needs the title and client.
  function mark() {
    var missing = [];
    form.querySelectorAll('[data-required]').forEach(function (el) {
      var hidden = el.closest('[data-eng-hide]') && el.closest('[data-eng-hide]').hidden; // e.g. the client, for our own team
      var need = !hidden && (!isDraft() || el.hasAttribute('data-always')) && empty(el);
      el.closest('.field').classList.toggle('missing', need);
      if (need) { missing.push(el); }
    });
    return missing;
  }

  function setFilled(el) {
    if (!el) { return; }
    var f = el.closest('.field');
    if (f) { f.classList.add('filled'); f.classList.remove('missing'); }
  }

  function fillIfEmpty(id, value) {
    var el = $(id);
    if (!el || value === null || value === undefined || value === '' || !empty(el)) { return 0; }
    el.value = value;
    setFilled(el);
    return 1;
  }

  function mergeLines(id, items) {
    var box = $(id);
    if (!box || !items || !items.length) { return 0; }
    var have = box.value.split(/\n/).map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    var add = items.filter(function (i) { return have.indexOf(i.toLowerCase()) === -1; });
    if (add.length) { box.value = (box.value.trim() ? box.value.trim() + '\n' : '') + add.join('\n'); setFilled(box); }
    return add.length;
  }

  function clientCity() {
    var c = $('client_id');
    var opt = c && c.options[c.selectedIndex];
    return opt ? (opt.getAttribute('data-city') || '') : '';
  }

  function read(file) {
    if (!file) { return; }
    if (!/\.(pdf|docx?)$/i.test(file.name)) { jdMsg.textContent = 'Please choose a PDF or Word file.'; return; }
    drop.classList.add('busy');
    jdMsg.textContent = 'Reading ' + file.name + '…';
    var body = new FormData();
    body.append('jd_file', file);
    body.append('_csrf', window.EshcolCsrf());
    fetch('/roles/read-jd', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.message || 'error'); } return d; }); })
      .then(function (d) {
        $('jd_upload').value = d.token;
        input.value = '';
        var n = 0, f = d.fields;
        if (d.text && empty($('jd_text'))) { $('jd_text').value = d.text; }
        if (f) {
          n += fillIfEmpty('title', f.title);
          n += fillIfEmpty('exp_min', f.exp_min);
          n += fillIfEmpty('exp_max', f.exp_max);
          n += fillIfEmpty('ctc_min_lpa', f.ctc_min_lpa);
          n += fillIfEmpty('ctc_max_lpa', f.ctc_max_lpa);
          n += fillIfEmpty('notice_max_days', f.notice_max_days);
          if (f.positions && $('positions').value === '1') { $('positions').value = f.positions; setFilled($('positions')); n++; }
          n += fillIfEmpty('location', (f.locations && f.locations[0]) || clientCity());
          if (f.locations && f.locations.length > 1) { n += mergeLines('extra_locations', f.locations.slice(1)) ? 1 : 0; }
          n += mergeLines('must_haves', f.skills) ? 1 : 0;
          n += mergeLines('qualifications', f.qualifications) ? 1 : 0;
        }
        var left = mark().length;
        jdMsg.innerHTML = '';
        var b = document.createElement('b');
        b.textContent = '✓ ' + d.name;
        jdMsg.appendChild(b);
        jdMsg.appendChild(document.createTextNode(d.message ? ' · ' + d.message :
          ' · filled ' + n + ' field' + (n === 1 ? '' : 's') + ' (shaded green: please check them). ' +
          (left ? left + ' still needed, outlined in red.' : 'Everything needed is filled.') + ' The file is saved with the role.'));
        drop.classList.add('done');
      })
      .catch(function (e) { jdMsg.textContent = (e && e.message && e.message !== 'error') ? e.message : 'Could not read that file. Try again, or paste the JD text below.'; })
      .then(function () { drop.classList.remove('busy'); });
  }

  if (drop && input) {
    input.addEventListener('change', function () { read(input.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) { read(e.dataTransfer.files[0]); } });
  }

  ['input', 'change'].forEach(function (ev) {
    form.addEventListener(ev, function (e) {
      var f = e.target.closest && e.target.closest('.field');
      if (f && e.target.id !== 'jd_file' && !empty(e.target)) { f.classList.remove('missing'); if (ev === 'input') { f.classList.remove('filled'); } }
    });
  });
  $('client_id') && $('client_id').addEventListener('change', function () { fillIfEmpty('location', clientCity()); });
  status && status.addEventListener('change', function () { if (form.querySelector('.field.missing')) { mark(); } });

  // "Who can we consider?" follows the experience band until you choose (junior: local only; 8+ years: other cities; remote: anywhere).
  var scopes = form.querySelectorAll('input[name="location_scope"]');
  var touched = Array.prototype.some.call(scopes, function (r) { return r.getAttribute('data-touched') === '1'; });
  scopes.forEach(function (r) { r.addEventListener('change', function () { touched = true; }); });
  function suggestScope() {
    if (touched || !scopes.length) { return; }
    var min = parseFloat(($('exp_min') || {}).value), mode = ($('work_mode') || {}).value;
    var want = mode === 'remote' ? 'remote' : (!isNaN(min) && min >= 8 ? 'open' : 'local');
    scopes.forEach(function (r) { r.checked = r.value === want; });
  }
  ['exp_min', 'exp_max', 'work_mode'].forEach(function (id) { $(id) && $(id).addEventListener('change', suggestScope); });

  form.addEventListener('submit', function (e) {
    var missing = mark();
    if (!missing.length) { return; }
    e.preventDefault();
    formMsg.hidden = false;
    formMsg.textContent = missing.length + ' field' + (missing.length === 1 ? ' is' : 's are') + ' still needed (outlined in red).' + (isDraft() ? '' : ' Or set the status to Draft to save it for later.');
    missing[0].focus();
    missing[0].scrollIntoView({ block: 'center' });
  });
})();

// Who is this for? (15 Nov 2026): shows the hiring manager for our own team and the contract length for staffing,
// and hides the client for our own team (the app files it under Eshcol Global). Without JavaScript all fields show.
(function () {
  var radios = document.querySelectorAll('input[name="engagement"]');
  if (!radios.length) { return; }
  function apply() {
    var on = (document.querySelector('input[name="engagement"]:checked') || {}).value || 'placement';
    document.querySelectorAll('[data-eng-show]').forEach(function (el) { el.hidden = el.getAttribute('data-eng-show') !== on; });
    document.querySelectorAll('[data-eng-hide]').forEach(function (el) { el.hidden = el.getAttribute('data-eng-hide') === on; });
  }
  radios.forEach(function (r) { r.addEventListener('change', apply); });
  apply();
})();

// Engineering branches (21 Nov 2026): "Tick the technician branches" ticks Mechanical, EEE, Electrical, Mechatronics and the rest.
(function () {
  var b = document.getElementById('br-tech');
  if (!b) { return; }
  b.addEventListener('click', function () {
    var on = (b.getAttribute('data-codes') || '').split(',');
    document.querySelectorAll('input[name="eng_branches[]"]').forEach(function (c) { c.checked = on.indexOf(c.value) >= 0; });
  });
})();
