// Builds the Eshcol-branded CVs for a draft shortlist, in your browser.
// PDF CVs: a cover page from the server, then every page of the candidate's own CV, shrunk a
// little to make room for a slim header (logo, reference, role) and footer (our contact line).
// Word CVs: converted to HTML here (mammoth), then laid out as a branded PDF on the server.
// Anything else (old .doc, scans, text): the server builds it from the text we read.
(function () {
  var box = document.getElementById('cv-builder');
  if (!box) { return; }
  var LIBS = {
    pdf: ['/assets/vendor/pdf-lib-1.17.1.min.js', 'sha384-weMABwrltA6jWR8DDe9Jp5blk+tZQh7ugpCsF3JwSA53WZM9/14PjS5LAJNHNjAI'],
    docx: ['/assets/vendor/mammoth-1.8.0.browser.min.js', 'sha384-/cXAMbzovUIKbBERjPmR3SnPTh8siWr5lsvFYj1Uq4XP0yaJUZJmsh0YXyGv5P0y']
  };
  var loaded = {};
  function lib(name) {
    if (loaded[name]) { return loaded[name]; }
    loaded[name] = new Promise(function (ok, fail) {
      var s = document.createElement('script');
      s.src = LIBS[name][0]; s.integrity = LIBS[name][1]; s.crossOrigin = 'anonymous';
      s.onload = ok; s.onerror = function () { fail(new Error('Could not load the ' + name + ' tool. Check your internet connection.')); };
      document.head.appendChild(s);
    });
    return loaded[name];
  }
  var csrf = document.querySelector('meta[name=csrf]').content;
  function post(url, data) {
    var fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData) && data) { Object.keys(data).forEach(function (k) { fd.append(k, data[k]); }); }
    fd.append('_csrf', csrf);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'The server did not answer (' + r.status + ').' }; });
    });
  }
  function bytes(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) { throw new Error('Could not fetch ' + url + ' (' + r.status + ')'); }
      return r.arrayBuffer();
    });
  }
  // Helvetica (built into every PDF reader) only knows Western characters.
  function plain(s) { return String(s || '').replace(/[–—]/g, '-').replace(/[^\x20-\x7E\xA0-\xFF]/g, ''); }

  async function stamp(plan) {
    await lib('pdf');
    var PDF = window.PDFLib;
    var out = await PDF.PDFDocument.create();
    var cover = await PDF.PDFDocument.load(await bytes(plan.cover_url));
    (await out.copyPages(cover, cover.getPageIndices())).forEach(function (p) { out.addPage(p); });
    var src = await PDF.PDFDocument.load(await bytes(plan.source_url), { ignoreEncryption: true });
    var font = await out.embedFont(PDF.StandardFonts.Helvetica);
    var bold = await out.embedFont(PDF.StandardFonts.HelveticaBold);
    var logo = await out.embedPng(await bytes(plan.logo_url));
    var pages = await out.embedPages(src.getPages());
    var grape = PDF.rgb(0.475, 0.275, 0.698), grey = PDF.rgb(0.43, 0.40, 0.50), sand = PDF.rgb(0.85, 0.70, 0.42);
    pages.forEach(function (ep) {
      var W = ep.width, H = ep.height, top = 30, bottom = 30;
      var scale = Math.min(1, (H - top - bottom) / H, (W - 24) / W);
      var w = W * scale, h = H * scale;
      var page = out.addPage([W, H]);
      page.drawPage(ep, { x: (W - w) / 2, y: bottom + (H - top - bottom - h) / 2, width: w, height: h });
      var lh = 15, lw = logo.width * (lh / logo.height);
      page.drawImage(logo, { x: 22, y: H - 7 - lh, width: lw, height: lh });
      var head = plain(plan.head);
      page.drawText(head, { x: W - 22 - bold.widthOfTextAtSize(head, 7.5), y: H - 17, size: 7.5, font: bold, color: grape });
      page.drawLine({ start: { x: 22, y: bottom - 6 }, end: { x: W - 22, y: bottom - 6 }, thickness: 0.6, color: sand });
      var foot = plain(plan.foot), fs = 7;
      while (font.widthOfTextAtSize(foot, fs) > W - 44 && fs > 5) { fs -= 0.5; }
      page.drawText(foot, { x: (W - font.widthOfTextAtSize(foot, fs)) / 2, y: bottom - 17, size: fs, font: font, color: grey });
    });
    out.setTitle(plain(plan.title)); out.setAuthor('Eshcol Global Services (P) Ltd'); out.setCreator('Eshcol Recruit');
    return out.save();
  }

  async function build(row) {
    var status = row.querySelector('[data-status]');
    var base = row.getAttribute('data-base');
    status.textContent = 'Preparing…'; row.className = row.className.replace(/\b(ready|failed)\b/g, '') + ' busy';
    try {
      var plan = await (await fetch(base + '/plan', { credentials: 'same-origin' })).json();
      var res;
      if (plan.type === 'pdf') {
        try {
          var pdf = await stamp(plan);
          var fd = new FormData(); fd.append('pdf', new Blob([pdf], { type: 'application/pdf' }), 'cv.pdf');
          res = await post(base + '/branded', fd);
        } catch (e) {
          // A PDF the stamper can't open (locked or damaged): build from the text instead.
          res = await post(base + '/render', { note: 'pdf-fallback' });
        }
      } else if (plan.type === 'docx') {
        await lib('docx');
        var conv = await window.mammoth.convertToHtml({ arrayBuffer: await bytes(plan.source_url) }, {
          convertImage: window.mammoth.images.imgElement(function (img) {
            return img.read('base64').then(function (b) { return { src: 'data:' + img.contentType + ';base64,' + b }; });
          })
        });
        res = await post(base + '/render', { html: conv.value });
      } else {
        res = await post(base + '/render', {});
      }
      if (!res.ok) { throw new Error(res.message || 'Could not save the CV.'); }
      status.innerHTML = 'Ready · <a href="' + base + '/cv" target="_blank">open</a> <span class="muted">(' + res.kb + ' KB' + (res.how ? ', ' + res.how : '') + ')</span>';
      row.className = row.className.replace(/\bbusy\b/g, '') + ' ready';
    } catch (e) {
      status.innerHTML = '<span class="err">' + (e.message || 'Something went wrong') + '</span> <button type="button" class="btn link small" data-retry>Try again</button>';
      row.className = row.className.replace(/\bbusy\b/g, '') + ' failed';
    }
    update();
  }
  function update() {
    var rows = box.querySelectorAll('[data-item]');
    var ready = box.querySelectorAll('[data-item].ready').length;
    var send = document.getElementById('send-btn');
    var bar = document.getElementById('cv-bar');
    if (bar) { bar.style.width = Math.round(ready / Math.max(1, rows.length) * 100) + '%'; }
    if (send) { send.disabled = ready < rows.length; }
    var note = document.getElementById('cv-count');
    if (note) { note.textContent = ready + ' of ' + rows.length + ' CVs ready'; }
  }
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-retry]');
    if (b) { build(b.closest('[data-item]')); }
  });
  (async function () {
    var rows = Array.prototype.slice.call(box.querySelectorAll('[data-item]'));
    update();
    for (var i = 0; i < rows.length; i++) {
      if (!rows[i].classList.contains('ready')) { await build(rows[i]); }
    }
  })();
})();
