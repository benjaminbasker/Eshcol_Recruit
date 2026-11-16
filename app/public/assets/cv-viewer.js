// The CV, shown on the candidate page (16 Nov 2026): drawn by the app itself, so it shows whatever the browser's
// "download PDFs" setting is. PDF: PDF.js (self-hosted). Word (.docx): mammoth (self-hosted), cleaned. Photo or
// scan: the image. "Enlarge" opens it full-screen with zoom; Esc closes.
const PDFJS = '/assets/vendor/pdfjs-4.10.38.min.js';
const WORKER = '/assets/vendor/pdfjs-4.10.38.worker.min.js';
const MAMMOTH = '/assets/vendor/mammoth-1.8.0.browser.min.js';

let pdfjsLib = null;
const pdfjs = () => (pdfjsLib ??= import(PDFJS).then((m) => { m.GlobalWorkerOptions.workerSrc = WORKER; return m; }));
let mammothLib = null;
const mammoth = () => (mammothLib ??= new Promise((ok, fail) => {
  if (window.mammoth) { ok(window.mammoth); return; }
  const s = document.createElement('script');
  s.src = MAMMOTH;
  s.onload = () => ok(window.mammoth);
  s.onerror = fail;
  document.head.appendChild(s);
}));
const docs = new Map(); // src -> loaded PDF, so Enlarge doesn't fetch it again

const msg = (box, text) => { box.innerHTML = ''; const p = document.createElement('p'); p.className = 'small muted cv-msg'; p.textContent = text; box.appendChild(p); };

// Word output: only plain formatting survives; links must be web or mail links.
function clean(html) {
  const t = document.createElement('template');
  t.innerHTML = html;
  t.content.querySelectorAll('script,style,iframe,object,embed,link,meta,form,input,button').forEach((e) => e.remove());
  t.content.querySelectorAll('*').forEach((el) => {
    for (const a of [...el.attributes]) {
      const n = a.name.toLowerCase();
      const v = a.value.trim().toLowerCase();
      const okUrl = v.startsWith('http:') || v.startsWith('https:') || v.startsWith('mailto:') || (n === 'src' && v.startsWith('data:image/'));
      if (!['href', 'src', 'alt', 'colspan', 'rowspan'].includes(n) || ((n === 'href' || n === 'src') && !okUrl)) el.removeAttribute(a.name);
    }
    if (el.tagName === 'A') { el.target = '_blank'; el.rel = 'noopener'; }
  });
  return t.content;
}

async function renderPdf(box, src, zoom) {
  const lib = await pdfjs();
  if (!docs.has(src)) docs.set(src, lib.getDocument({ url: src, isEvalSupported: false, withCredentials: true }).promise);
  const doc = await docs.get(src);
  const cs = getComputedStyle(box);
  const width = Math.max(260, box.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - 2);
  const dpr = window.devicePixelRatio || 1;
  const pages = document.createDocumentFragment();
  const jobs = [];
  for (let n = 1; n <= doc.numPages; n++) {
    const page = await doc.getPage(n);
    const scale = (width / page.getViewport({ scale: 1 }).width) * zoom;
    const vp = page.getViewport({ scale: scale * dpr });
    const canvas = document.createElement('canvas');
    canvas.className = 'cv-page';
    canvas.width = Math.floor(vp.width);
    canvas.height = Math.floor(vp.height);
    canvas.style.width = Math.floor(vp.width / dpr) + 'px';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', 'CV page ' + n + ' of ' + doc.numPages);
    pages.appendChild(canvas);
    jobs.push(page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise);
  }
  box.innerHTML = '';
  box.appendChild(pages);
  await Promise.all(jobs);
}

async function renderDocx(box, src) {
  const [m, buf] = await Promise.all([mammoth(), fetch(src, { credentials: 'same-origin' }).then((r) => { if (!r.ok) throw new Error(r.status); return r.arrayBuffer(); })]);
  const out = await m.convertToHtml({ arrayBuffer: buf });
  const page = document.createElement('div');
  page.className = 'cv-docx';
  page.appendChild(clean(out.value));
  box.innerHTML = '';
  box.appendChild(page);
}

function renderImage(box, src, name) {
  const img = document.createElement('img');
  img.src = src;
  img.alt = 'CV of ' + name;
  img.className = 'cv-img';
  box.innerHTML = '';
  box.appendChild(img);
}

async function render(box, zoom = 1) {
  const { src, ext, name } = box.dataset;
  msg(box, 'Loading the CV…');
  try {
    if (ext === 'pdf') await renderPdf(box, src, zoom);
    else if (ext === 'docx') { await renderDocx(box, src); box.style.setProperty('--z', zoom); }
    else { renderImage(box, src, name || ''); box.style.setProperty('--z', zoom); }
  } catch (e) {
    msg(box, 'The CV could not be shown here. Use Download to open it.');
  }
}

// On the page: drawn when the CV tab first comes into view.
const seen = new IntersectionObserver((entries) => {
  for (const en of entries) {
    if (en.isIntersecting && !en.target.dataset.done) { en.target.dataset.done = '1'; render(en.target); seen.unobserve(en.target); }
  }
});
document.querySelectorAll('.cv-view').forEach((box) => seen.observe(box));
// Also when the CV tab is opened, or the page opens on it (a background tab gets no scroll signals).
const kick = () => document.querySelectorAll('.cv-view:not([data-done])').forEach((box) => {
  if (box.offsetParent !== null && box.clientWidth > 0) { box.dataset.done = '1'; seen.unobserve(box); render(box); }
});
document.querySelectorAll('[data-tab="cv"], a[href="#tab-cv"]').forEach((a) => a.addEventListener('click', () => setTimeout(kick, 60)));
setTimeout(kick, 300);

// Enlarge: full screen, with zoom.
let modal = null;
let back = null;
function close() {
  if (!modal) return;
  modal.remove();
  modal = null;
  document.body.style.overflow = '';
  document.removeEventListener('keydown', onKey);
  if (back) back.focus();
}
function onKey(e) {
  if (e.key === 'Escape') close();
  if (e.key === 'Tab' && modal) { // keep the keyboard inside the dialog
    const f = [...modal.querySelectorAll('button, a[href]')];
    if (!f.length) return;
    if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
    else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
  }
}
function open(btn) {
  back = btn;
  const { src, ext, name, download } = btn.dataset;
  let zoom = 1;
  modal = document.createElement('div');
  modal.className = 'cv-modal';
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-modal', 'true');
  modal.setAttribute('aria-label', 'CV of ' + name);
  modal.innerHTML = '<div class="cv-modal-bar"><b class="cv-modal-name"></b><span class="cv-modal-tools">'
    + '<button type="button" class="btn ghost small" data-z="-1" aria-label="Zoom out">−</button>'
    + '<span class="cv-zoom" aria-live="polite">100%</span>'
    + '<button type="button" class="btn ghost small" data-z="1" aria-label="Zoom in">+</button>'
    + '<button type="button" class="btn ghost small" data-z="0">Fit width</button>'
    + '<a class="btn ghost small" data-dl>Download</a>'
    + '<button type="button" class="btn small" data-close>Close</button></span></div>'
    + '<div class="cv-modal-body"><div class="cv-view cv-big"></div></div>';
  modal.querySelector('.cv-modal-name').textContent = name;
  modal.querySelector('[data-dl]').href = download;
  const box = modal.querySelector('.cv-big');
  Object.assign(box.dataset, { src, ext, name });
  document.body.appendChild(modal);
  document.body.style.overflow = 'hidden';
  document.addEventListener('keydown', onKey);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  modal.querySelector('[data-close]').addEventListener('click', close);
  const label = modal.querySelector('.cv-zoom');
  modal.querySelectorAll('[data-z]').forEach((b) => b.addEventListener('click', () => {
    const d = Number(b.dataset.z);
    zoom = d === 0 ? 1 : Math.min(3, Math.max(0.5, Math.round((zoom + d * 0.25) * 100) / 100));
    label.textContent = Math.round(zoom * 100) + '%';
    render(box, zoom);
  }));
  modal.querySelector('[data-close]').focus();
  render(box, zoom);
}
// Any CV button on the page, including ones in parts loaded later (the Claude tab).
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-cv-enlarge]');
  if (b) { e.preventDefault(); open(b); }
});
