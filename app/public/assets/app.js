/* Eshcol Recruit - small progressive enhancements. Pages work without it. */
(function () {
  'use strict';
  window.EshcolCsrf = function () {
    var m = document.querySelector('meta[name="csrf"]');
    return m ? m.content : '';
  };

  // Confirm before destructive or bulk actions: <form data-confirm="...">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });

  // Confirm on a specific submit button: <button data-confirm-click="...">
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm-click]');
    if (b && !window.confirm(b.getAttribute('data-confirm-click'))) { e.preventDefault(); }
    var all = e.target.closest('input[data-all]');
    if (all) {
      all.closest('form').querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = all.checked; });
    }
  });

  // Auto-submit selects marked data-autosubmit (e.g. stage changes)
  document.addEventListener('change', function (e) {
    if (e.target.matches('select[data-autosubmit]')) {
      // Moving to a closing stage (Not suitable, Withdrew): ask why, for future reference.
      var opt = e.target.options[e.target.selectedIndex];
      if (opt && opt.getAttribute('data-closing') === '1') {
        var note = window.prompt(stagePrompt(opt.textContent.trim(), opt.getAttribute('data-hold') === '1')) || '';
        var h = e.target.form.querySelector('input[name="note"]') || e.target.form.appendChild(Object.assign(document.createElement('input'), { type: 'hidden', name: 'note' }));
        h.value = note.slice(0, 255);
      }
      e.target.form.submit();
    }
  });
})();

// After saving a section (redirect to /page#section), show the confirmation inside that
// section, where the eye is, rather than at the top of the page out of sight.
// "Saved" confirmations fade after a few seconds; errors and notes stay.
(function () {
  var id = decodeURIComponent(location.hash.slice(1));
  var target = id && document.getElementById(id);
  var flashes = document.querySelectorAll('main .flash, .flash');
  if (target && flashes.length) {
    var anchor = target.querySelector('h2, h3');
    Array.prototype.forEach.call(flashes, function (f) {
      // Standing notes (the bell ones, e.g. "To clarify with the client") stay where they are: only the
      // message about what you just did moves next to the section (16 Nov 2026).
      if (f.classList.contains('bell')) { return; }
      f.classList.add('in-section');
      if (anchor) { anchor.insertAdjacentElement('afterend', f); } else { target.insertBefore(f, target.firstChild); }
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('.flash.ok'), function (f) {
    if (f.textContent.length > 60) { return; } // longer messages (like a test-email result) stay until you leave
    setTimeout(function () {
      f.classList.add('fade');
      setTimeout(function () { f.remove(); }, 700);
    }, 6000);
  });
})();

// Copy buttons: <button data-copy="id-of-textarea">
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]');
  if (!b) { return; }
  var el = document.getElementById(b.getAttribute('data-copy'));
  if (!el) { return; }
  var done = function () { var t = b.textContent; b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = t; }, 1600); };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(el.value).then(done, function () { el.select(); document.execCommand('copy'); done(); });
  } else { el.select(); document.execCommand('copy'); done(); }
});

// Mobile sidebar, menus that close on outside click, and Escape.
(function () {
  var shell = document.getElementById('shell');
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-nav-open]') && shell) { shell.classList.add('nav-open'); }
    if (e.target.closest('[data-nav-close]') && shell) { shell.classList.remove('nav-open'); }
    document.querySelectorAll('details.menu[open]').forEach(function (d) {
      if (!d.contains(e.target)) { d.removeAttribute('open'); }
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (shell) { shell.classList.remove('nav-open'); }
      document.querySelectorAll('details.menu[open]').forEach(function (d) { d.removeAttribute('open'); });
    }
  });
})();

// Ctrl+K (or /) opens a palette that jumps to any candidate, role, client or reference.
(function () {
  var box = document.getElementById('cmdk');
  if (!box) { return; }
  var input = document.getElementById('cmdk-q');
  var list = document.getElementById('cmdk-results');
  var items = [], active = 0, timer = null, seq = 0;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function render(results) {
    items = results || []; active = 0;
    var html = '', group = null;
    items.forEach(function (r, i) {
      if (r.group !== group) { group = r.group; html += '<div class="cmdk-group">' + esc(group) + '</div>'; }
      html += '<a class="cmdk-item" role="option" href="' + esc(r.url) + '" data-i="' + i + '">' +
        '<svg class="i" aria-hidden="true"><use href="#i-' + esc(r.icon || 'arrow-right') + '"/></svg>' +
        '<span class="grow">' + esc(r.title) + (r.sub ? ' <span class="sub">· ' + esc(r.sub) + '</span>' : '') + '</span>' +
        (r.ref ? '<span class="ref">' + esc(r.ref) + '</span>' : '') + '</a>';
    });
    list.innerHTML = html || '<div class="empty small">Nothing found.</div>';
    mark();
  }
  function mark() {
    list.querySelectorAll('.cmdk-item').forEach(function (a) {
      var on = +a.getAttribute('data-i') === active;
      a.classList.toggle('active', on);
      a.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) { a.scrollIntoView({ block: 'nearest' }); }
    });
  }
  function load() {
    var n = ++seq;
    fetch('/search?q=' + encodeURIComponent(input.value.trim()), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { results: [] }; })
      .then(function (d) { if (n === seq) { render(d.results); } })
      .catch(function () {});
  }
  function open() { box.classList.add('open'); input.value = ''; load(); setTimeout(function () { input.focus(); }, 10); }
  function close() { box.classList.remove('open'); }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-cmdk-open]')) { e.preventDefault(); open(); }
    else if (e.target === box) { close(); }
  });
  document.addEventListener('keydown', function (e) {
    var typing = /INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '') || (document.activeElement || {}).isContentEditable;
    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); box.classList.contains('open') ? close() : open(); return; }
    if (e.key === '/' && !typing && !box.classList.contains('open')) { e.preventDefault(); open(); return; }
    if (!box.classList.contains('open')) { return; }
    if (e.key === 'Escape') { close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); mark(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); mark(); }
    else if (e.key === 'Enter' && items[active]) { e.preventDefault(); location.href = items[active].url; }
  });
  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 140); });
})();

// Pipeline board: drag a card to another column to move the candidate to that stage.
(function () {
  var board = document.querySelector('.board[data-stage-board]');
  if (!board) { return; }
  var dragging = null;
  board.addEventListener('dragstart', function (e) {
    var card = e.target.closest('.kcard');
    if (!card) { return; }
    dragging = card; card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.getAttribute('data-app'));
  });
  board.addEventListener('dragend', function () {
    if (dragging) { dragging.classList.remove('dragging'); }
    dragging = null;
    board.querySelectorAll('.col.over').forEach(function (c) { c.classList.remove('over'); });
  });
  board.addEventListener('dragover', function (e) {
    var col = e.target.closest('.col');
    if (!col || !dragging) { return; }
    e.preventDefault();
    board.querySelectorAll('.col.over').forEach(function (c) { if (c !== col) { c.classList.remove('over'); } });
    col.classList.add('over');
  });
  board.addEventListener('drop', function (e) {
    var col = e.target.closest('.col');
    if (!col || !dragging) { return; }
    e.preventDefault();
    col.classList.remove('over');
    var card = dragging, from = card.closest('.col');
    if (from === col) { return; }
    var body = col.querySelector('.col-body');
    var empty = body.querySelector('.empty-col');
    if (empty) { empty.remove(); }
    body.insertBefore(card, body.firstChild);
    count(from); count(col);
    // A closing stage (Not suitable, Withdrew): ask why, for future reference. Cancel keeps no reason.
    var note = '';
    if (col.classList.contains('closed')) {
      note = window.prompt(stagePrompt(col.getAttribute('data-name'), col.classList.contains('hold'))) || '';
    }
    var fd = new FormData();
    fd.append('_csrf', window.EshcolCsrf());
    fd.append('stage_id', col.getAttribute('data-stage'));
    fd.append('note', note.slice(0, 255));
    fd.append('ajax', '1');
    fetch('/applications/' + card.getAttribute('data-app') + '/stage', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || j.ok === false) { throw new Error(j.error || ''); } return j; }); })
      .then(function () {
        toast('Moved to ' + col.getAttribute('data-name') + (note ? ', with your reason' : ''));
        if (col.classList.contains('closed')) {
          var tags = card.querySelector('.tags.reason-tags') || (function () { var d = document.createElement('div'); d.className = 'tags reason-tags'; card.appendChild(d); return d; })();
          tags.innerHTML = '';
          var hold = col.classList.contains('hold');
          var s = document.createElement('span'); s.className = 'chip ' + (hold ? 'keep' : 'reason'); s.textContent = note ? note.slice(0, 60) : (hold ? 'kept for later' : 'no reason given'); tags.appendChild(s);
        }
      })
      .catch(function (e) {
        from.querySelector('.col-body').appendChild(card); count(from); count(col);
        toast(e && e.message ? e.message : 'Could not move that card. Please reload and try again.', true);
      });
  });
  function count(col) {
    var n = col.querySelectorAll('.kcard').length;
    var el = col.querySelector('.col-head .n');
    if (el) { el.textContent = n; }
  }
})();

// A small confirmation at the bottom of the screen.
function toast(msg, bad) {
  var t = document.createElement('div');
  t.className = 'toast' + (bad ? ' bad' : '');
  t.setAttribute('role', 'status');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function () { t.classList.add('show'); }, 10);
  setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2600);
}

// Tabs: <nav data-tabs> with <a data-tab="x"> and <section data-panel="x">. Without JavaScript every
// panel shows. A link to #something inside a panel (after a save) opens that panel. Several tab bars can share
// a page (e.g. the candidate page's sections, and the CV report's Summary / AI analysis inside one of them):
// each bar controls only the panels named in its own links. data-default names the panel shown first.
(function () {
  var navs = Array.prototype.slice.call(document.querySelectorAll('[data-tabs]'));
  if (!navs.length) { return; }
  var groups = navs.map(function (nav) {
    var names = Array.prototype.map.call(nav.querySelectorAll('[data-tab]'), function (a) { return a.getAttribute('data-tab'); });
    var panels = Array.prototype.filter.call(document.querySelectorAll('[data-panel]'), function (p) { return names.indexOf(p.getAttribute('data-panel')) !== -1; });
    return { nav: nav, names: names, panels: panels };
  }).filter(function (g) { return g.panels.length; });
  if (!groups.length) { return; }
  document.body.classList.add('js-tabs');
  // A panel with data-lazy="url" is fetched the first time it is shown (the position page's Claude tab,
  // 14 Nov 2026). Its scripts are re-created so they run, and a #link into it is followed once it is in.
  function load(p) {
    var url = p.getAttribute('data-lazy');
    if (!url || p.getAttribute('data-loaded')) { return; }
    p.setAttribute('data-loaded', '1');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
      .then(function (html) {
        p.innerHTML = html;
        p.querySelectorAll('script').forEach(function (old) {
          var s = document.createElement('script');
          if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
          old.parentNode.replaceChild(s, old);
        });
        var id = decodeURIComponent(location.hash.slice(1));
        var el = id && id !== p.id && document.getElementById(id);
        if (el && p.contains(el)) { el.scrollIntoView({ block: 'start' }); }
      })
      .catch(function () {
        p.removeAttribute('data-loaded');
        p.innerHTML = '<div class="card"><p class="muted" style="margin:0">Could not load this just now. <a href="' + url + '">Open it on its own</a>, or reload the page.</p></div>';
      });
  }
  function show(g, name, push) {
    g.panels.forEach(function (p) { var on = p.getAttribute('data-panel') === name; p.classList.toggle('on', on); if (on) { load(p); } });
    g.nav.querySelectorAll('[data-tab]').forEach(function (a) {
      var on = a.getAttribute('data-tab') === name;
      a.classList.toggle('on', on);
      a.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    if (push) { history.replaceState(null, '', '#tab-' + name); }
  }
  function fromHash() {
    var id = decodeURIComponent(location.hash.slice(1));
    var el = id && document.getElementById(id);
    groups.forEach(function (g) {
      // Open the panel of this group that holds the target (directly, or the outer panel around it).
      var hit = null;
      if (el) {
        g.panels.forEach(function (p) { if (p === el || p.contains(el)) { hit = p; } });
      }
      var current = g.panels.filter(function (p) { return p.classList.contains('on'); })[0];
      show(g, hit ? hit.getAttribute('data-panel') : (current ? current.getAttribute('data-panel') : (g.nav.getAttribute('data-default') || g.panels[0].getAttribute('data-panel'))), false);
    });
    if (el && !el.matches('[data-panel]')) { setTimeout(function () { el.scrollIntoView({ block: 'start' }); }, 0); }
  }
  groups.forEach(function (g) {
    g.nav.addEventListener('click', function (e) {
      var a = e.target.closest('[data-tab]');
      if (!a) { return; }
      e.preventDefault();
      show(g, a.getAttribute('data-tab'), true);
    });
  });
  window.addEventListener('hashchange', fromHash);
  fromHash();
})();

// The question asked when a candidate leaves the pipeline: why not suitable, or why keep them for later.
function stagePrompt(stage, hold) {
  return hold
    ? 'Keeping them for a future requirement. A short note for later (optional): why they are worth keeping.\nFor example: Good accountant, but expects 6 LPA against a 4.2 budget'
    : 'Why ' + stage.toLowerCase() + '? A short remark for future reference (optional).\nFor example: Qualification below the requirement: BBM and MBA; minimum is B.Com';
}

// "Tick all" boxes: <input type="checkbox" data-check-all="fix[]"> ticks or unticks every box of that name (14 Nov 2026).
document.addEventListener('change', function (e) {
  var name = e.target && e.target.getAttribute && e.target.getAttribute('data-check-all');
  if (!name) { return; }
  document.querySelectorAll('input[type="checkbox"][name="' + name.replace(/"/g, '') + '"]').forEach(function (c) { c.checked = e.target.checked; });
});

// "Why this score" is fetched the first time it is opened (B12, 14 Nov 2026), so a position page with hundreds
// of candidates doesn't carry hundreds of collapsed explanations. The answer is the app's own HTML.
document.addEventListener('toggle', function (e) {
  var d = e.target;
  if (!d || d.tagName !== 'DETAILS' || !d.open) { return; }
  var box = d.querySelector('[data-why]');
  if (!box || box.getAttribute('data-loaded')) { return; }
  box.setAttribute('data-loaded', '1');
  fetch(box.getAttribute('data-why'), { credentials: 'same-origin' })
    .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
    .then(function (html) { box.innerHTML = html; })
    .catch(function () { box.removeAttribute('data-loaded'); box.innerHTML = '<span class="small muted">Could not load this just now. Close and open it again.</span>'; });
}, true);

// My day: tabs over "Waiting on you" (All / Client decisions / Interviews / ...), 14 Nov 2026.
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-todo-tab]');
  if (!b) { return; }
  var name = b.getAttribute('data-todo-tab');
  b.parentNode.querySelectorAll('[data-todo-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
  document.querySelectorAll('[data-todo-group]').forEach(function (g) { g.hidden = name !== 'all' && g.getAttribute('data-todo-group') !== name; });
});
