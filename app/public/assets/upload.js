/* Bulk CV upload: sends files one at a time so any number fit within server limits. */
(function () {
  'use strict';
  var me = document.currentScript;
  var maxBytes = (parseInt(me.getAttribute('data-max-mb'), 10) || 10) * 1048576;
  var drop = document.getElementById('drop');
  var input = document.getElementById('files');
  var role = document.getElementById('role');
  var bar = document.getElementById('bar');
  var ptext = document.getElementById('progress-text');
  var rows = document.getElementById('rows');
  var labels = { new: 'New candidate', updated: 'Added to existing', duplicate_file: 'Already on file', older_copy: 'On file with a newer CV', needs_ocr: 'Scan: needs reading', failed: 'Problem', skipped: 'Skipped' };
  var tally = {};
  var busy = false;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function addRow(r) {
    tally[r.outcome] = (tally[r.outcome] || 0) + 1;
    var who = r.candidate_id ? '<a href="/candidates/' + encodeURIComponent(r.candidate_id) + '">' + esc(r.name || 'Open') + '</a>' : '';
    var tr = document.createElement('tr');
    tr.innerHTML = '<td>' + esc(r.file) + '</td><td class="out-' + esc(r.outcome) + '"><b>' + esc(labels[r.outcome] || r.outcome) + '</b><br><span class="small muted">' + esc(r.message) + '</span></td><td>' + who + '</td>';
    rows.appendChild(tr);
  }

  function post(url, data) {
    data.append('_csrf', window.EshcolCsrf());
    return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (res) {
      return res.json().catch(function () { return { ok: false, message: 'Server error (' + res.status + ').' }; });
    });
  }

  async function run(files) {
    if (busy || !files.length) { return; }
    busy = true;
    tally = {};
    rows.innerHTML = '';
    document.getElementById('results').hidden = false;
    document.getElementById('progress-wrap').hidden = false;
    document.getElementById('after').innerHTML = '';
    var start = new FormData();
    start.append('role_id', role.value);
    var channel = document.getElementById('channel');
    if (channel && channel.value) { start.append('channel', channel.value); }
    var ack = document.getElementById('send_ack');
    if (ack && ack.checked) { start.append('send_ack', '1'); }
    var label = document.getElementById('batch_label');
    if (label && label.value.trim()) { start.append('label', label.value.trim()); }
    var older = document.getElementById('older');
    if (older && older.checked) { start.append('older', '1'); }
    var b = await post('/upload/batch', start);
    if (!b.ok) { ptext.textContent = b.message || 'Could not start the upload.'; busy = false; return; }

    for (var i = 0; i < files.length; i++) {
      var f = files[i];
      ptext.textContent = 'Reading ' + (i + 1) + ' of ' + files.length + ': ' + f.name;
      bar.style.width = Math.round(i / files.length * 100) + '%';
      if (f.size > maxBytes && !/\.zip$/i.test(f.name)) {
        addRow({ file: f.name, outcome: 'failed', message: 'Larger than ' + (maxBytes / 1048576) + ' MB.' });
        continue;
      }
      var fd = new FormData();
      fd.append('batch', b.batch);
      fd.append('file', f, f.name);
      try {
        var r = await post('/upload/file', fd);
        if (r.ok) { r.results.forEach(addRow); } else { addRow({ file: f.name, outcome: 'failed', message: r.message }); }
      } catch (e) {
        addRow({ file: f.name, outcome: 'failed', message: 'Connection problem. Try this file again.' });
      }
    }
    bar.style.width = '100%';
    ptext.textContent = 'Finished ' + files.length + ' file' + (files.length > 1 ? 's' : '') + '.';
    document.getElementById('summary').innerHTML = Object.keys(tally).map(function (k) {
      return '<span class="chip">' + esc(labels[k] || k) + ': ' + tally[k] + '</span>';
    }).join('');
    if (role.value) {
      document.getElementById('after').innerHTML = '<a class="btn" href="/roles/' + encodeURIComponent(role.value) + '">See the ranked list for this role</a>';
    } else {
      document.getElementById('after').innerHTML = '<a class="btn" href="/candidates">See all candidates</a>';
    }
    input.value = '';
    busy = false;
  }

  input.addEventListener('change', function () { run(Array.prototype.slice.call(input.files)); });
  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) { run(Array.prototype.slice.call(e.dataTransfer.files)); });
})();
