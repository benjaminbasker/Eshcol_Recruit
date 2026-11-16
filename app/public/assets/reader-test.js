// Settings › Reader test: runs Claude Haiku and Sonnet on each CV of the test, one at a time,
// showing progress. Waits and retries when Claude is busy; stops on any other problem.
(function () {
  var btn = document.getElementById('rt-run');
  if (!btn) return;
  var stopBtn = document.getElementById('rt-stop');
  var out = document.getElementById('rt-progress');
  var jobs = JSON.parse(btn.getAttribute('data-jobs') || '[]');
  var url = btn.getAttribute('data-url');
  var stopped = false;
  var done = 0, failed = 0, spent = 0;

  function say(text) { out.textContent = text; }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function runOne(job, attempt) {
    var data = new FormData();
    data.append('_csrf', window.EshcolCsrf());
    data.append('candidate_id', job.c);
    data.append('reader', job.r);
    return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (res) { return res.json().catch(function () { return { ok: false, busy: res.status >= 500, error: 'The server did not answer (' + res.status + ').' }; }); })
      .catch(function () { return { ok: false, busy: true, error: 'No connection.' }; })
      .then(function (j) {
        if (j.busy && attempt < 4) {
          say('Claude is busy; waiting 30 seconds, then trying again (' + (done + 1) + ' of ' + jobs.length + ')…');
          return wait(30000).then(function () { return runOne(job, attempt + 1); });
        }
        return j;
      });
  }

  function next(i) {
    if (stopped) { say('Stopped after ' + done + ' of ' + jobs.length + '. Reload the page to continue.'); return; }
    if (i >= jobs.length) { say('Finished: ' + done + ' runs, ' + failed + ' failed. Reloading…'); setTimeout(function () { location.reload(); }, 1200); return; }
    var job = jobs[i];
    say('Reading CV ' + (Math.floor(i / 2) + 1) + ' of ' + Math.ceil(jobs.length / 2) + ' with Claude ' + (job.r === 'haiku' ? 'Haiku' : 'Sonnet') + '… (' + done + ' of ' + jobs.length + ' done, ₹' + Math.round(spent * 88) + ' spent)');
    runOne(job, 0).then(function (j) {
      done++;
      if (j.spent !== undefined) spent = j.spent;
      if (!j.ok) failed++;
      if (j.stop) { stopped = true; say('Stopped: ' + j.error); return; }
      next(i + 1);
    });
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    stopBtn.hidden = false;
    next(0);
  });
  stopBtn.addEventListener('click', function () { stopped = true; stopBtn.disabled = true; });
})();
