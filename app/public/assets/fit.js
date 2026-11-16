// Role › Claude tab: assesses the queued candidates one at a time, with progress.
(function () {
  var all = document.getElementById('fit-all-unused');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.fit-take-unused').forEach(function (c) { c.checked = all.checked; });
    });
  }
  var btn = document.getElementById('fit-run');
  if (!btn) return;
  var stopBtn = document.getElementById('fit-stop');
  var out = document.getElementById('fit-progress');
  var url = btn.getAttribute('data-url');
  var total = parseInt(btn.getAttribute('data-left'), 10) || 0;
  var stopped = false, done = 0;

  function say(t) { out.textContent = t; }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function step(attempt) {
    var data = new FormData();
    data.append('_csrf', window.EshcolCsrf());
    return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (res) { return res.json().catch(function () { return { ok: false, busy: res.status >= 500, error: 'The server did not answer (' + res.status + ').' }; }); })
      .catch(function () { return { ok: false, busy: true, error: 'No connection.' }; })
      .then(function (j) {
        if (j.busy && attempt < 4) {
          say('Claude is busy; waiting 30 seconds, then trying again…');
          return wait(30000).then(function () { return step(attempt + 1); });
        }
        return j;
      });
  }

  function next() {
    if (stopped) { say('Stopped after ' + done + '. Reload the page to see the changes so far, or carry on later.'); return; }
    step(0).then(function (j) {
      if (!j.ok) { say('Stopped: ' + j.error); return; }
      if (j.done) { say('All assessed. Loading the ranking…'); setTimeout(function () { location.reload(); }, 800); return; }
      done++;
      say('Assessed ' + done + ' of about ' + total + ' (' + j.left + ' left, ₹' + Math.round((j.cost || 0) * 88) + ' this month)…');
      next();
    });
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    stopBtn.hidden = false;
    say('Starting…');
    next();
  });
  stopBtn.addEventListener('click', function () { stopped = true; stopBtn.disabled = true; });
})();
