import { api } from './api.js';
import { el, debounce, toast, fmtCount } from './util.js';
import { renderRow, resetThumbQueue } from './table.js';
import { renderSenses } from './senses.js';
import { VideoRecorder, fmtTime } from './recorder.js';
import { buildPhonologyForm } from './phonology.js';

const state = {
  search: '',
  thema: '',
  labels: [],
  statuses: [],
  ownerUserId: '',          // '' = Iedereen; '<userId>' = filter to that user
  context: 'signio',        // signio (extern=1) | signbank (extern IS NULL)
  sort: 'glos_az',          // glos_az | glos_za | newest | oldest
  page: 1,
  total: 0,
  pageSize: 50,
  rows: [],
  options: { themas: [], labels: [], users: [] },
  user: null,
};

const $ = sel => document.querySelector(sel);

const userNameMap = new Map();
const userName = uid => userNameMap.get(String(uid)) || `#${uid}`;

async function init() {
  state.user = await api.currentUser();
  $('#userBadge').textContent = state.user.username;

  state.options = await api.filterOptions();
  state.options.users.forEach(u => userNameMap.set(String(u.userId), u.user));

  state.ownerUserId = String(state.user.userId);
  if (state.user.defaultContext === 'signbank' || state.user.defaultContext === 'signio') {
    state.context = state.user.defaultContext;
  }

  populateThemaSelect();
  populateOwnerSelect();
  populateLabelsMulti('#labelsMulti', state.options.labels.map(l => l.label), v => { state.labels = v; refresh(); });
  populateStatusMulti();

  $('#ownerSelect').addEventListener('change', (e) => {
    state.ownerUserId = e.target.value;
    state.page = 1;
    refresh();
  });

  $('#sortSelect').value = state.sort;
  $('#sortSelect').addEventListener('change', (e) => {
    state.sort = e.target.value;
    state.page = 1;
    refresh();
  });

  $('#searchInput').addEventListener('input', debounce(e => {
    state.search = e.target.value.trim();
    state.page = 1;
    refresh();
  }, 300));
  $('#themaSelect').addEventListener('change', e => {
    state.thema = e.target.value;
    state.page = 1;
    refresh();
  });
  $('#resetFiltersBtn').addEventListener('click', () => {
    state.search = ''; $('#searchInput').value = '';
    state.thema = '';  $('#themaSelect').value = '';
    state.labels = []; resetMulti('#labelsMulti');
    state.statuses = []; resetMulti('#statusMulti');
    state.ownerUserId = String(state.user.userId);
    $('#ownerSelect').value = state.ownerUserId;
    state.sort = 'glos_az';
    $('#sortSelect').value = 'glos_az';
    state.page = 1;
    refresh();
  });

  setupAddModal();
  setupRecordModal();
  setupConfirmModal();
  setupStudioModal();
  setupPhonologyModal();
  setupSignbankModal();
  setupCompareModal();
  setupOverscrollPaging();
  setupNavDrawer();
  setupContextToggle();
  $('#addGlossBtn').addEventListener('click', () => openAddModal());

  document.addEventListener('click', closeOpenDetails);

  await refresh();
}

function closeOpenDetails(ev) {
  document.querySelectorAll('details[open]').forEach(d => {
    if (!d.contains(ev.target)) d.removeAttribute('open');
  });
}

function clearChildren(node) { while (node.firstChild) node.removeChild(node.firstChild); }

function populateOwnerSelect() {
  const sel = $('#ownerSelect');
  clearChildren(sel);
  const myId = String(state.user.userId);
  const myName = state.user.username || userNameMap.get(myId) || myId;
  sel.appendChild(el('option', { value: myId }, `${myName} (jij)`));
  sel.appendChild(el('option', { value: '' }, 'Iedereen'));
  state.options.users
    .filter(u => String(u.userId) !== myId)
    .sort((a, b) => Number(a.userId) - Number(b.userId))
    .forEach(u => sel.appendChild(el('option', { value: String(u.userId) }, u.user)));
  sel.value = state.ownerUserId;
}

function populateThemaSelect() {
  const sel = $('#themaSelect');
  clearChildren(sel);
  sel.appendChild(el('option', { value: '' }, '— alle —'));
  for (const t of state.options.themas) {
    sel.appendChild(el('option', { value: t }, t));
  }
  const list = $('#themaList');
  clearChildren(list);
  for (const t of state.options.themas) {
    list.appendChild(el('option', { value: t }));
  }
}

function populateLabelsMulti(root, items, onChange) {
  const container = document.querySelector(`${root} .multi-list`);
  clearChildren(container);
  for (const item of items) {
    const lbl = el('label', {},
      el('input', { type: 'checkbox', value: item, onchange: () => syncMulti(root, onChange) }),
      ' ', item
    );
    container.appendChild(lbl);
  }
}

function populateStatusMulti() {
  const root = '#statusMulti';
  document.querySelectorAll(`${root} .multi-list input`).forEach(inp => {
    inp.addEventListener('change', () => syncMulti(root, v => { state.statuses = v; state.page = 1; refresh(); }));
  });
}

function syncMulti(root, onChange) {
  const inputs = document.querySelectorAll(`${root} .multi-list input:checked`);
  const values = Array.from(inputs).map(i => i.value);
  const summary = document.querySelector(`${root} .multi-summary`);
  if (values.length === 0) {
    summary.textContent = '— alle —'; summary.classList.add('placeholder');
  } else if (values.length <= 2) {
    summary.textContent = values.join(', '); summary.classList.remove('placeholder');
  } else {
    summary.textContent = `${values.length} geselecteerd`; summary.classList.remove('placeholder');
  }
  onChange(values);
}

function resetMulti(root) {
  document.querySelectorAll(`${root} .multi-list input`).forEach(i => i.checked = false);
  const summary = document.querySelector(`${root} .multi-summary`);
  summary.textContent = '— alle —'; summary.classList.add('placeholder');
}

async function refresh() {
  const list = $('#glossList');
  resetThumbQueue();
  clearChildren(list);
  for (let i = 0; i < 6; i++) list.appendChild(el('div', { class: 'gloss-row skeleton', style: 'height:130px;' }));
  $('#emptyState').style.display = 'none';

  let res;
  try {
    res = await api.list({
      search: state.search,
      thema:  state.thema,
      labels: state.labels,
      statuses: state.statuses,
      ownerUserId: state.ownerUserId,
      context: state.context,
      sort: state.sort,
      page: state.page,
    });
  } catch (e) {
    toast('Lijst laden mislukt: ' + e.message, 'error');
    clearChildren(list);
    return;
  }
  state.rows = res.rows;
  state.total = res.total;
  state.page = res.page;
  state.pageSize = res.pageSize;

  clearChildren(list);
  if (!res.rows.length) {
    $('#emptyState').style.display = '';
  } else {
    const ctx = makeCtx();
    const groupByGlos = state.statuses.includes('extern_duplicate');
    if (!groupByGlos) {
      res.rows.forEach(r => list.appendChild(renderRow(r, ctx)));
    } else {
      // Bucket rows by glos so each duplicate group is its own visual unit.
      const groups = new Map();
      res.rows.forEach(r => {
        const key = r.glos || `__id_${r.id}`;
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(r);
      });
      groups.forEach((rows, key) => {
        const wrap = el('div', { class: 'dup-group' });
        if (rows.length > 1) {
          wrap.appendChild(el('div', { class: 'dup-banner' },
            el('i', { class: 'fas fa-triangle-exclamation' }),
            `Waarschuwing: duplicaat gedetecteerd voor "${rows[0].glos || '?'}". ${rows.length} rijen hieronder.`
          ));
        }
        rows.forEach(r => wrap.appendChild(renderRow(r, ctx)));
        list.appendChild(wrap);
      });
    }
  }

  const meta = $('#resultMeta');
  clearChildren(meta);
  if (res.total === 0) {
    meta.textContent = 'Geen resultaten';
  } else {
    const from = (res.page - 1) * res.pageSize + 1;
    const to   = Math.min(res.page * res.pageSize, res.total);
    meta.append(
      'Toont ',
      el('strong', {}, String(from)), '–',
      el('strong', {}, String(to)), ' van ',
      el('strong', {}, fmtCount(res.total)),
    );
  }

  renderPager(res.page, Math.ceil(res.total / res.pageSize));
}

function makeCtx() {
  return {
    userName,
    themaOptions: () => state.options.themas,
    labelOptions: () => state.options.labels,
    userOptions:  () => state.options.users.map(u => ({
      value: String(u.userId),
      label: u.user,
    })),
    refreshRow: (row) => {
      const old = document.querySelector(`.gloss-row[data-id="${row.id}"]`);
      if (!old) return;
      const fresh = renderRow(row, makeCtx());
      old.replaceWith(fresh);
    },
    openRecord:  (row)        => openRecordModal(row),
    openConfirm: (msg, onYes) => openConfirmModal(msg, onYes),
    openStudio:  (row, video) => openStudioModal(row, video),
    openPhonology: (row)      => openPhonologyModal(row),
    deleteAllZelfopname: (row) => deleteAllZelfopname(row),
    contextIsSignbank: ()     => state.context === 'signbank',
    pushToSignbank: (row)     => pushGlossToSignbank(row),
    broadcastToSignbank: (row) => broadcastGloss(row),
    disconnectSignbank: (row)  => disconnectGloss(row),
    compareWithSignbank: (row) => compareGloss(row),
  };
}

async function deleteAllZelfopname(row) {
  if (!row.zelfopname.length) return;
  const count = row.zelfopname.length;
  const msg = count === 1
    ? `Zelfopname "${row.zelfopname[0]}" verwijderen?`
    : `Alle ${count} zelfopnames van deze glos verwijderen?`;
  openConfirmModal(msg, async () => {
    const filenames = [...row.zelfopname];
    for (const fn of filenames) {
      const upd = await api.deleteVideo(row.id, fn);
      row.zelfopname = upd.zelfopname;
    }
    makeCtx().refreshRow(row);
    toast(count === 1 ? 'Zelfopname verwijderd' : `${count} zelfopnames verwijderd`, 'success');
  });
}

function renderPager(current, totalPages) {
  const pager = $('#pager');
  clearChildren(pager);
  if (totalPages <= 1) return;

  const btn = (label, page, opts = {}) => el('button', {
    onclick: () => { state.page = page; refresh(); window.scrollTo({ top: 0, behavior: 'smooth' }); },
    ...(opts.disabled ? { disabled: true } : {}),
    class: opts.active ? 'active' : '',
  }, label);

  pager.appendChild(btn('«', Math.max(1, current - 1), { disabled: current === 1 }));

  const window_ = 2;
  const pages = new Set([1, totalPages, current]);
  for (let i = current - window_; i <= current + window_; i++) {
    if (i >= 1 && i <= totalPages) pages.add(i);
  }
  const sorted = [...pages].sort((a, b) => a - b);
  let prev = 0;
  for (const p of sorted) {
    if (p - prev > 1) pager.appendChild(el('span', { class: 'ellipsis' }, '…'));
    pager.appendChild(btn(String(p), p, { active: p === current }));
    prev = p;
  }
  pager.appendChild(btn('»', Math.min(totalPages, current + 1), { disabled: current === totalPages }));
}

/* ---------- Add gloss modal ---------- */

const sensesEditors = {};

function setupAddModal() {
  populateLabelsMulti('#addLabelsMulti', state.options.labels.map(l => l.label), () => {});

  document.querySelectorAll('#addForm .senses-editor').forEach(node => {
    const name = node.dataset.name;
    let arr = [];
    const editor = renderSenses([], { onChange: v => { arr = v; }, placeholder: name === 'sensesEngels' ? 'Sense (EN)…' : 'Sense…' });
    node.appendChild(editor);
    sensesEditors[name] = () => arr;
    sensesEditors[`__reset_${name}`] = () => {
      clearChildren(node);
      arr = [];
      const editor2 = renderSenses([], { onChange: v => { arr = v; }, placeholder: name === 'sensesEngels' ? 'Sense (EN)…' : 'Sense…' });
      node.appendChild(editor2);
      sensesEditors[name] = () => arr;
    };
  });

  $('#addModal').addEventListener('click', ev => {
    if (ev.target.matches('[data-close]') || ev.target === $('#addModal')) closeModal('#addModal');
  });

  $('#addForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    const labels = Array.from(document.querySelectorAll('#addLabelsMulti .multi-list input:checked'))
      .map(i => i.value);
    const payload = {
      glos: fd.get('glos').toString().trim(),
      glos_engels: fd.get('glos_engels').toString().trim(),
      thema: fd.get('thema').toString().trim(),
      labels,
      senses: sensesEditors.senses(),
      sensesEngels: sensesEditors.sensesEngels(),
    };
    payload.context = state.context;
    try {
      await api.create(payload);
      closeModal('#addModal');
      ev.target.reset();
      document.querySelectorAll('#addLabelsMulti .multi-list input').forEach(i => i.checked = false);
      sensesEditors.__reset_senses();
      sensesEditors.__reset_sensesEngels();
      toast('Glos aangemaakt', 'success');
      state.page = 1;
      refresh();
    } catch (e) {
      toast('Aanmaken mislukt: ' + e.message, 'error');
    }
  });
}

function openAddModal() {
  $('#addModal').classList.remove('hidden');
  setTimeout(() => $('#addForm input[name="glos"]').focus(), 50);
}

function closeModal(sel) { $(sel).classList.add('hidden'); }

/* ---------- Record modal ---------- */

let recorder = null;
let recordingForRow = null;
let recordingState = 'idle';

function setupRecordModal() {
  const modal = $('#recordModal');
  modal.addEventListener('click', ev => {
    if (ev.target.matches('[data-close]') || ev.target === modal) closeRecordModal();
  });
  $('#recordToggle').addEventListener('click', toggleRecording);
}

async function openRecordModal(row) {
  recordingForRow = row;
  recordingState = 'idle';
  $('#recordModal').classList.remove('hidden');
  $('#recordStatus').textContent = 'Klaar';
  $('#recordTimer').textContent = '0:00';
  setRecordToggleLabel('start');
  $('#recordToggle').classList.remove('recording');

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    $('#recordPreview').srcObject = stream;
    $('#recordPreview').play().catch(() => {});
    recorder = new VideoRecorder($('#recordPreview'));
    recorder.stream = stream;
  } catch (e) {
    toast('Camera-toegang geweigerd: ' + e.message, 'error');
    closeRecordModal();
  }
}

function setRecordToggleLabel(mode) {
  const btn = $('#recordToggle');
  clearChildren(btn);
  if (mode === 'start') {
    btn.appendChild(el('i', { class: 'fas fa-circle' }));
    btn.append(' Opname starten');
  } else {
    btn.appendChild(el('i', { class: 'fas fa-stop' }));
    btn.append(' Stoppen & opslaan');
  }
}

async function toggleRecording() {
  if (!recorder) return;
  if (recordingState === 'idle') {
    try {
      const mimeCandidates = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
      const mime = mimeCandidates.find(t => MediaRecorder.isTypeSupported(t)) || '';
      recorder.recorder = new MediaRecorder(recorder.stream, mime ? { mimeType: mime } : undefined);
      recorder.chunks = [];
      recorder.recorder.addEventListener('dataavailable', e => {
        if (e.data && e.data.size > 0) recorder.chunks.push(e.data);
      });
      recorder.recorder.start();
      recorder.startTime = Date.now();
      recorder.timerInterval = setInterval(
        () => $('#recordTimer').textContent = fmtTime(Math.floor((Date.now() - recorder.startTime) / 1000)),
        250
      );
      recordingState = 'recording';
      $('#recordStatus').textContent = 'Opname loopt…';
      setRecordToggleLabel('stop');
      $('#recordToggle').classList.add('recording');
    } catch (e) {
      toast('Kon niet starten: ' + e.message, 'error');
    }
  } else if (recordingState === 'recording') {
    $('#recordStatus').textContent = 'Bezig met opslaan…';
    $('#recordToggle').disabled = true;
    const blob = await recorder.stop();
    try {
      const upd = await api.uploadVideo(recordingForRow.id, blob);
      recordingForRow.zelfopname = upd.zelfopname;
      makeCtx().refreshRow(recordingForRow);
      toast('Zelfopname opgeslagen', 'success');
      closeRecordModal();
    } catch (e) {
      toast('Uploaden mislukt: ' + e.message, 'error');
    } finally {
      $('#recordToggle').disabled = false;
    }
  }
}

function closeRecordModal() {
  if (recorder) recorder.cleanup();
  recorder = null;
  recordingState = 'idle';
  recordingForRow = null;
  $('#recordModal').classList.add('hidden');
}

/* ---------- Overscroll-to-paginate ---------- */

const OVERSCROLL_THRESHOLD   = 1100;  // pixels of overscroll to fully fill drop
const OVERSCROLL_HOLD_MS     = 500;   // after full: keep scrolling this long before committing
const OVERSCROLL_FADE_MS     = 1100;  // safety reset if no scroll activity at all
const OVERSCROLL_MAX_DELTA   = 50;    // per-event clamp so trackpad inertia can't insta-fill
const OVERSCROLL_GAP_MS      = 300;   // wheel-event gap that resets buildup
const OVERSCROLL_DRAIN_PER_S = 1300;  // idle drain rate (px/s) when not yet committing

let overscroll = {
  dir: 0,
  amount: 0,
  ready: false,
  fullSince: 0,
  decayId: null,
  cooldown: false,
  lastEventAt: 0,
  drainRaf: 0,
};

function modalOpen() {
  return !!document.querySelector('.modal-backdrop:not(.hidden)');
}

function totalPages() {
  return Math.max(1, Math.ceil(state.total / state.pageSize));
}

function updateOverscrollUI() {
  const top = $('#overscrollTop');
  const bot = $('#overscrollBottom');
  const pct = Math.min(1, overscroll.amount / OVERSCROLL_THRESHOLD);
  const ready = pct >= 1;
  overscroll.ready = ready;
  const setActive = (drop, label, page) => {
    drop.classList.add('active');
    drop.classList.toggle('ready', ready);
    drop.style.setProperty('--fill', pct);
    drop.querySelector('.drop-label-text').textContent = label;
    drop.querySelector('.drop-page').textContent = `pagina ${page} / ${totalPages()}`;
  };
  if (overscroll.dir === -1) {
    setActive(top, overscroll.ready ? 'Loslaten…' : 'Vorige pagina', state.page - 1);
    bot.classList.remove('active', 'ready');
    bot.style.removeProperty('--fill');
  } else if (overscroll.dir === 1) {
    setActive(bot, overscroll.ready ? 'Loslaten…' : 'Volgende pagina', state.page + 1);
    top.classList.remove('active', 'ready');
    top.style.removeProperty('--fill');
  } else {
    top.classList.remove('active', 'ready');
    bot.classList.remove('active', 'ready');
    top.style.removeProperty('--fill');
    bot.style.removeProperty('--fill');
  }
}

function resetOverscroll() {
  overscroll.dir = 0;
  overscroll.amount = 0;
  overscroll.ready = false;
  overscroll.fullSince = 0;
  clearTimeout(overscroll.decayId);
  cancelAnimationFrame(overscroll.drainRaf);
  overscroll.drainRaf = 0;
  updateOverscrollUI();
}

function scheduleFade() {
  clearTimeout(overscroll.decayId);
  overscroll.decayId = setTimeout(resetOverscroll, OVERSCROLL_FADE_MS);
}

function startDrainLoop() {
  if (overscroll.drainRaf) return;
  let last = performance.now();
  const tick = (now) => {
    const dt = now - last;
    last = now;
    // Once ready (click pending), do not drain — let user click or fade-timer reset.
    if (overscroll.amount > 0 && !overscroll.ready) {
      const idle = now - overscroll.lastEventAt;
      if (idle > 60) {
        overscroll.amount = Math.max(0, overscroll.amount - (OVERSCROLL_DRAIN_PER_S * dt) / 1000);
        updateOverscrollUI();
      }
    }
    if (overscroll.amount > 0) {
      overscroll.drainRaf = requestAnimationFrame(tick);
    } else {
      overscroll.drainRaf = 0;
      resetOverscroll();
    }
  };
  overscroll.drainRaf = requestAnimationFrame(tick);
}

async function commitOverscrollNav(dir) {
  if (overscroll.cooldown) return;
  overscroll.cooldown = true;
  const goingNext = dir === 1;
  state.page += dir;
  const doc = document.documentElement;
  await refresh();
  if (goingNext) window.scrollTo({ top: 0, behavior: 'auto' });
  else requestAnimationFrame(() => window.scrollTo({ top: doc.scrollHeight, behavior: 'auto' }));
  setTimeout(() => { overscroll.cooldown = false; }, 600);
  resetOverscroll();
}

function setupOverscrollPaging() {
  // Esc dismisses if drop is sitting in ready state.
  window.addEventListener('keydown', (e) => {
    if (!overscroll.ready || modalOpen()) return;
    if (e.key === 'Escape') resetOverscroll();
  });

  window.addEventListener('wheel', (e) => {
    if (modalOpen() || overscroll.cooldown) return;

    const doc = document.documentElement;
    const atTop    = window.scrollY <= 0;
    const atBottom = (window.scrollY + window.innerHeight) >= (doc.scrollHeight - 2);

    const canPrev = state.page > 1;
    const canNext = state.page < totalPages();

    let dir = 0;
    if (atBottom && e.deltaY > 0 && canNext) dir = 1;
    else if (atTop && e.deltaY < 0 && canPrev) dir = -1;

    if (dir === 0) {
      if (overscroll.amount > 0) resetOverscroll();
      return;
    }

    const now = performance.now();
    const gap = now - overscroll.lastEventAt;
    if (overscroll.dir !== dir || gap > OVERSCROLL_GAP_MS) {
      overscroll.amount = 0;
      overscroll.fullSince = 0;
    }
    overscroll.dir = dir;
    overscroll.lastEventAt = now;
    overscroll.amount = Math.min(
      OVERSCROLL_THRESHOLD,
      overscroll.amount + Math.min(OVERSCROLL_MAX_DELTA, Math.abs(e.deltaY))
    );
    if (overscroll.amount >= OVERSCROLL_THRESHOLD) {
      if (!overscroll.fullSince) overscroll.fullSince = now;
    } else {
      overscroll.fullSince = 0;
    }
    updateOverscrollUI();
    scheduleFade();
    startDrainLoop();

    if (overscroll.fullSince && (now - overscroll.fullSince) >= OVERSCROLL_HOLD_MS) {
      commitOverscrollNav(dir);
    }
  }, { passive: true });

  // touch fallback (pull gesture on mobile)
  let touchStartY = null;
  window.addEventListener('touchstart', (e) => {
    if (modalOpen()) return;
    touchStartY = e.touches[0].clientY;
  }, { passive: true });
  window.addEventListener('touchmove', (e) => {
    if (modalOpen() || overscroll.cooldown || touchStartY == null) return;
    const y = e.touches[0].clientY;
    const dy = touchStartY - y;
    const doc = document.documentElement;
    const atTop    = window.scrollY <= 0;
    const atBottom = (window.scrollY + window.innerHeight) >= (doc.scrollHeight - 2);
    const canPrev = state.page > 1;
    const canNext = state.page < totalPages();

    let dir = 0;
    if (atBottom && dy > 0 && canNext) dir = 1;
    else if (atTop && dy < 0 && canPrev) dir = -1;

    if (dir === 0) return;
    if (overscroll.dir !== dir) overscroll.amount = 0;
    overscroll.dir = dir;
    overscroll.lastEventAt = performance.now();
    overscroll.amount = Math.min(OVERSCROLL_THRESHOLD, Math.abs(dy));
    updateOverscrollUI();
    scheduleFade();
  }, { passive: true });
  window.addEventListener('touchend', () => {
    if (overscroll.ready) commitOverscrollNav(overscroll.dir);
    else if (overscroll.amount && !overscroll.cooldown) resetOverscroll();
    touchStartY = null;
  }, { passive: true });
}

/* ---------- Studio video modal (list of entries) ---------- */

const STUDIO_BASE = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini';
let studioCards = []; // each: { id, players[], rafId, master }
let studioCurrentRow = null;

function setupStudioModal() {
  const modal = $('#studioModal');
  modal.addEventListener('click', ev => {
    if (ev.target.matches('[data-close]') || ev.target === modal) closeStudioModal();
  });
}

function studioVideoUrl(basename, postProcessed) {
  if (!basename) return null;
  const stem = basename.replace(/\.\w+$/, '');
  const folder = postProcessed === 1 ? 'post' : 'raw';
  return `${STUDIO_BASE}/${folder}/${encodeURIComponent(stem)}.mp4`;
}

function openStudioModal(row) {
  studioCurrentRow = row;
  $('#studioTitle').textContent = `Studio video's — ${row.glos || '#' + row.id}`;
  renderStudioList();
  $('#studioModal').classList.remove('hidden');
}

function renderStudioList() {
  cleanupStudioCards();
  const list = $('#studioList');
  clearChildren(list);

  const videos = studioCurrentRow?.studio_videos || [];
  const liveCount    = videos.filter(v => !v.deleted).length;
  const deletedCount = videos.length - liveCount;
  $('#studioCount').textContent = videos.length === 0
    ? 'Geen studio-opnames'
    : `${videos.length} opname${videos.length === 1 ? '' : 's'}${deletedCount ? ` (waarvan ${deletedCount} verwijderd)` : ''}`;

  if (!videos.length) {
    list.appendChild(el('div', { class: 'empty-state' },
      el('i', { class: 'fas fa-clapperboard' }),
      el('p', {}, 'Geen studio-opnames voor deze glos.')
    ));
    return;
  }

  videos.forEach(v => list.appendChild(renderStudioCard(v)));
}

function renderStudioCard(video) {
  const isDeleted = !!video.deleted;
  const card = el('div', {
    class: 'studio-card open' + (isDeleted ? ' deleted' : ''),
    dataset: { id: video.id },
  });

  const head = el('div', { class: 'studio-card-head' });
  head.append(
    el('span', { class: 'filename' }, (video.m_file || '').replace(/\.\w+$/, '')),
    el('div', { class: 'badges' },
      el('span', { class: 'badge ' + (video.post_processed === 1 ? 'post' : 'raw') },
        el('i', { class: 'fas ' + (video.post_processed === 1 ? 'fa-check-circle' : 'fa-circle-half-stroke') }),
        video.post_processed === 1 ? 'post' : 'raw'),
      video.zOg ? el('span', { class: 'badge zog' }, video.zOg) : null,
      video.date ? el('span', { class: 'badge' }, el('i', { class: 'fas fa-calendar-day' }), video.date) : null,
      isDeleted ? el('span', { class: 'badge deleted' }, el('i', { class: 'fas fa-trash' }), 'verwijderd') : null,
    ),
  );
  card.appendChild(head);

  const body = el('div', { class: 'studio-card-body' });

  const cameras = [
    { letter: 'L', label: 'Links',  file: video.l_file },
    { letter: 'M', label: 'Midden', file: video.m_file },
    { letter: 'R', label: 'Rechts', file: video.r_file },
  ].filter(c => c.file);

  const grid = el('div', { class: 'studio-grid' });
  const players = [];
  cameras.forEach(cam => {
    const cell = el('div', { class: 'cam-cell cam-' + cam.letter });
    const tag  = el('span', { class: 'cam-tag' }, cam.letter, ' ', cam.label);
    const v    = el('video', {
      muted: true, playsinline: true, preload: 'none', loop: true,
      'data-src': studioVideoUrl(cam.file, video.post_processed),
    });
    cell.append(v, tag);
    grid.appendChild(cell);
    players.push(v);
  });

  const playBtn  = el('button', { class: 'btn-icon', title: 'Afspelen / pauzeren' }, el('i', { class: 'fas fa-play' }));
  const resetBtn = el('button', { class: 'btn-icon', title: 'Opnieuw' }, el('i', { class: 'fas fa-rotate-left' }));
  const scrub    = el('input', { type: 'range', min: '0', max: '0', step: '0.05', value: '0' });
  const time     = el('span', { class: 'time-display' }, '0:00 / 0:00');
  const controls = el('div', { class: 'studio-controls' },
    playBtn, resetBtn,
    el('div', { class: 'scrub' }, scrub),
    time
  );

  const cardState = { players, master: players[0] || null, rafId: null };
  studioCards.push(cardState);

  const updatePlay = () => {
    const i = playBtn.querySelector('i');
    i.className = 'fas ' + (cardState.master && !cardState.master.paused ? 'fa-pause' : 'fa-play');
  };
  const updateTime = () => {
    if (!cardState.master) return;
    const cur = cardState.master.currentTime || 0;
    const dur = cardState.master.duration   || 0;
    time.textContent = `${fmtTime(Math.floor(cur))} / ${fmtTime(Math.floor(dur))}`;
  };

  playBtn.addEventListener('click', () => {
    if (!cardState.master) return;
    if (cardState.master.paused) cardState.players.forEach(v => v.play().catch(() => {}));
    else                         cardState.players.forEach(v => v.pause());
    updatePlay();
  });
  resetBtn.addEventListener('click', () => {
    cardState.players.forEach(v => { v.currentTime = 0; v.play().catch(() => {}); });
    updatePlay();
  });
  scrub.addEventListener('input', ev => {
    const t = parseFloat(ev.target.value);
    cardState.players.forEach(v => { try { v.currentTime = t; } catch {} });
    updateTime();
  });

  if (cardState.master) {
    cardState.master.addEventListener('loadedmetadata', () => {
      scrub.max = String(cardState.master.duration || 0);
      updateTime();
    });
    cardState.master.addEventListener('play',  updatePlay);
    cardState.master.addEventListener('pause', updatePlay);
  }

  // Always open: attach sources and start sync loop immediately.
  cardState.players.forEach(v => {
    if (!v.src && v.dataset.src) v.src = v.dataset.src;
  });
  requestAnimationFrame(() => {
    cardState.players.forEach(v => v.play().catch(() => {}));
    startSyncLoop(cardState, scrub, updateTime);
  });

  body.appendChild(grid);
  body.appendChild(controls);

  if (!isDeleted) {
    const delBtn = el('button', {
      class: 'btn btn-danger btn-sm',
      onclick: async (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        openConfirmModal(`Studio-opname "${(video.m_file || '#' + video.id).replace(/\.\w+$/, '')}" verwijderen?`, async () => {
          try {
            await api.deleteStudioVideo(video.id);
            video.added = 'DELETE';
            video.deleted = true;
            renderStudioList();
            // also refresh the underlying gloss row
            makeCtx().refreshRow(studioCurrentRow);
            toast('Studio-opname verwijderd', 'success');
          } catch (e) {
            toast('Verwijderen mislukt: ' + e.message, 'error');
          }
        });
      }
    },
      el('i', { class: 'fas fa-trash' }), ' Verwijderen'
    );
    body.appendChild(el('div', { class: 'delete-row' }, delBtn));
  }

  card.appendChild(body);
  return card;
}

function startSyncLoop(cardState, scrub, updateTime) {
  cancelAnimationFrame(cardState.rafId);
  const tick = () => {
    if (!cardState.master) return;
    const t = cardState.master.currentTime || 0;
    cardState.players.forEach(v => {
      if (v === cardState.master) return;
      if (Math.abs((v.currentTime || 0) - t) > 0.2) {
        try { v.currentTime = t; } catch {}
      }
    });
    if (!cardState.master.paused && !cardState.master.ended) {
      scrub.value = String(t);
      updateTime();
    }
    cardState.rafId = requestAnimationFrame(tick);
  };
  cardState.rafId = requestAnimationFrame(tick);
}

function cleanupStudioCards() {
  studioCards.forEach(c => {
    cancelAnimationFrame(c.rafId);
    c.players.forEach(v => { try { v.pause(); v.removeAttribute('src'); v.load(); } catch {} });
  });
  studioCards = [];
}

function closeStudioModal() {
  cleanupStudioCards();
  studioCurrentRow = null;
  $('#studioModal').classList.add('hidden');
}

/* ---------- Signbank push ---------- */

function setupSignbankModal() {
  const modal = $('#signbankModal');
  modal.addEventListener('click', ev => {
    if (ev.target.matches('[data-close]') || ev.target === modal) closeSignbankModal();
  });
}

let signbankCurrentRow = null;

function pushGlossToSignbank(row) {
  signbankCurrentRow = row;
  $('#signbankTitle').textContent = `Push naar Signbank — ${row.glos || '#' + row.id}`;
  $('#signbankRetryBtn').onclick = () => firePush(row);
  $('#signbankModal').classList.remove('hidden');
  firePush(row);
}

function closeSignbankModal() {
  signbankCurrentRow = null;
  $('#signbankModal').classList.add('hidden');
}

async function firePush(row) {
  const body = $('#signbankBody');
  const status = $('#signbankFooterStatus');
  status.className = 'signbank-status busy';
  status.textContent = 'Bezig met versturen…';
  clearChildren(body);
  body.appendChild(renderSourceSection(row));
  const banner = el('div', { class: 'sb-banner info' },
    el('i', { class: 'fas fa-paper-plane' }),
    el('span', {}, 'Verzoek wordt verstuurd naar Signbank…'),
  );
  body.appendChild(banner);

  let res;
  try {
    res = await api.pushToSignbank(row.id);
  } catch (e) {
    body.removeChild(banner);
    body.appendChild(renderBanner('error', 'Verzoek mislukt', e.message));
    status.className = 'signbank-status';
    status.textContent = '';
    return;
  }

  body.removeChild(banner);
  body.appendChild(renderResultBanner(res));
  body.appendChild(renderLogSection(res.log || []));
  body.appendChild(renderRequestSection(res.request));
  body.appendChild(renderResponseSection(res));

  status.className = 'signbank-status';
  status.textContent = res.ok
    ? `Klaar — HTTP ${res.status} in ${res.duration_ms} ms`
    : `Mislukt — HTTP ${res.status} in ${res.duration_ms} ms`;
}

function renderSourceSection(row) {
  const wrap = el('section', { class: 'sb-section' });
  wrap.appendChild(el('header', {}, 'Bron — form_data rij'));
  const div = el('div', { class: 'sb-section-body' });

  const grid = el('div', { class: 'sb-source-grid' });
  grid.appendChild(field('Glos NL', row.glos));
  grid.appendChild(field('Glos EN', row.glos_engels));
  grid.appendChild(field('Senses NL', (row.senses || []).join(' · ') || null));
  grid.appendChild(field('Senses EN', (row.sensesEngels || []).join(' · ') || null));
  grid.appendChild(field('Thema', row.thema));
  grid.appendChild(field('Labels', (row.labels || []).join(', ') || null));
  div.appendChild(grid);

  // Media: thumbnail/video preview if available.
  const thumbVideo = row.thumbnail_video?.m_file;
  const zelf = (row.zelfopname || [])[0];
  if (thumbVideo || zelf) {
    const media = el('div', { class: 'sb-source-media', style: 'margin-top: 12px;' });
    const v = el('video', { muted: true, loop: true, autoplay: true, playsinline: true });
    if (thumbVideo) {
      const folder = row.thumbnail_video.post_processed === 1 ? 'post' : 'raw';
      v.src = `https://signcollect.nl/gebarenoverleg_media/studioFilesMini/${folder}/${encodeURIComponent(thumbVideo.replace(/\.\w+$/, ''))}.mp4`;
    } else {
      v.src = `/uploads/${encodeURIComponent(zelf)}`;
    }
    media.appendChild(v);
    const meta = el('div', { class: 'meta' });
    meta.append(
      el('div', {}, el('strong', {}, 'Thumbnail/Video bron: '), thumbVideo ? `studio M-frame (${thumbVideo})` : `zelfopname (${zelf})`),
      el('div', { style: 'margin-top: 6px;' }, '(Wordt nog niet automatisch meegestuurd — Signbank API ondersteunt video alleen via een aparte ',
        el('code', {}, '/api_update_gloss/{glossid}/video'),
        ' call ná aanmaken.)')
    );
    media.appendChild(meta);
    div.appendChild(media);
  }

  // Phonology summary (text fields only, dropdown values are numeric ids without context lookup here).
  const phonoText = ['virtualObjectt', 'phonologyOther', 'mouthGesture', 'mouthing', 'phoneticVariation']
    .map(k => row[k]).filter(Boolean);
  const fasePieces = [];
  if (row.fonologie_fase1 === '1' || row.fonologie_fase1 === 1) fasePieces.push('Fase 1 ✓');
  if (row.fonologie_fase2 === '1' || row.fonologie_fase2 === 1) fasePieces.push('Fase 2 ✓');
  if (phonoText.length || fasePieces.length) {
    div.appendChild(el('div', { style: 'margin-top: 12px; font-size: 12.5px; color: var(--text-muted);' },
      el('strong', {}, 'Fonologie: '),
      [phonoText.join(' · '), fasePieces.join(' · ')].filter(Boolean).join(' · '),
      ' (niet aanwezig in Signbank create-payload spec)'
    ));
  }

  wrap.appendChild(div);
  return wrap;
}

function field(label, value) {
  const wrap = el('div', {});
  wrap.appendChild(el('div', { class: 'label' }, label));
  if (value === null || value === undefined || value === '') {
    wrap.appendChild(el('div', { class: 'value empty' }, '— leeg —'));
  } else {
    wrap.appendChild(el('div', { class: 'value' }, String(value)));
  }
  return wrap;
}

function renderBanner(kind, title, detail) {
  const icons = { success: 'fa-circle-check', error: 'fa-triangle-exclamation', info: 'fa-circle-info' };
  return el('div', { class: `sb-banner ${kind}` },
    el('i', { class: 'fas ' + (icons[kind] || icons.info) }),
    el('span', {},
      el('span', { class: 'banner-title' }, title),
      detail ? el('span', { class: 'banner-detail' }, detail) : null,
    ),
  );
}

function renderResultBanner(res) {
  if (res.ok) {
    return renderBanner('success', `Signbank antwoord: HTTP ${res.status}`, `Verzoek voltooid in ${res.duration_ms} ms.`);
  }
  let detail = `HTTP ${res.status} in ${res.duration_ms} ms`;
  if (res.http_error) detail += ' · curl: ' + res.http_error;
  if (res.response && typeof res.response === 'object' && res.response._html_error) {
    const err = res.response;
    detail = `${err.h1 || err.title || 'Server error'} — ${err.detail || ''}`.trim();
  }
  return renderBanner('error', 'Signbank gaf een fout', detail);
}

function renderLogSection(logEntries) {
  const wrap = el('section', { class: 'sb-section' });
  wrap.appendChild(el('header', {}, 'Tijdlijn'));
  const div = el('div', { class: 'sb-section-body sb-log' });
  if (!logEntries.length) {
    div.appendChild(el('div', {}, '(geen gebeurtenissen)'));
  } else {
    logEntries.forEach(e => {
      const line = el('div', { class: 'sb-log-line' });
      line.appendChild(el('span', { class: 't' }, e.t));
      line.appendChild(el('span', { class: 'level ' + (e.level || 'info') }, (e.level || 'info').toUpperCase()));
      const msgWrap = el('span', {});
      msgWrap.appendChild(el('div', {}, e.msg));
      if (e.data !== null && e.data !== undefined) {
        const det = el('details', {});
        det.appendChild(el('summary', {}, 'details'));
        det.appendChild(el('pre', {}, JSON.stringify(e.data, null, 2)));
        msgWrap.appendChild(det);
      }
      line.appendChild(msgWrap);
      div.appendChild(line);
    });
  }
  wrap.appendChild(div);
  return wrap;
}

function renderRequestSection(req) {
  const wrap = el('section', { class: 'sb-section' });
  const headerRow = el('header', {});
  headerRow.appendChild(el('span', {}, 'Verzonden naar Signbank'));
  if (req && req.method && req.url) {
    headerRow.appendChild(el('span', { style: 'text-transform: none; letter-spacing: 0; color: var(--text);' },
      el('code', {}, `${req.method} ${req.url}`)));
  }
  wrap.appendChild(headerRow);
  const div = el('div', { class: 'sb-section-body' });
  if (req && req.headers) {
    div.appendChild(el('div', { style: 'font-size: 12px; color: var(--text-muted); margin-bottom: 8px;' },
      el('strong', {}, 'Headers: '),
      req.headers.join(' · ')
    ));
  }
  if (req && req.payload) {
    div.appendChild(el('pre', { class: 'sb-payload-pre' }, JSON.stringify(req.payload, null, 2)));
  } else {
    div.appendChild(el('div', {}, '(geen body)'));
  }
  wrap.appendChild(div);
  return wrap;
}

function renderResponseSection(res) {
  const wrap = el('section', { class: 'sb-section' });
  wrap.appendChild(el('header', {},
    el('span', {}, 'Antwoord van Signbank'),
    el('span', { style: 'text-transform: none; letter-spacing: 0; color: var(--text-muted);' },
      `${res.content_type || ''}`)
  ));
  const div = el('div', { class: 'sb-section-body' });
  if (res.response && typeof res.response === 'object' && res.response._html_error) {
    div.appendChild(el('div', {},
      el('strong', {}, (res.response.h1 || res.response.title) + ' '),
      el('span', { style: 'color: var(--text-muted);' }, res.response.detail || '')
    ));
    if (res.raw_excerpt) {
      const det = el('details', { style: 'margin-top: 8px;' });
      det.appendChild(el('summary', {}, 'Ruwe HTML (eerste 800 tekens)'));
      det.appendChild(el('pre', { class: 'sb-payload-pre' }, res.raw_excerpt));
      div.appendChild(det);
    }
  } else if (res.response !== null && res.response !== undefined) {
    div.appendChild(el('pre', { class: 'sb-payload-pre' }, JSON.stringify(res.response, null, 2)));
  } else {
    div.appendChild(el('div', {}, '(leeg)'));
  }
  wrap.appendChild(div);
  return wrap;
}

/* ---------- Signbank broadcast & disconnect ---------- */

function broadcastGloss(row) {
  openConfirmModal(
    `Glos "${row.glos || '#' + row.id}" naar Signbank pushen? Dit maakt een nieuwe Signbank-entry aan en slaat de glossid lokaal op.`,
    async () => runSignbankOperation({
      title:   `Broadcast naar Signbank — ${row.glos || '#' + row.id}`,
      busyMsg: 'Bezig met broadcasten naar Signbank…',
      row,
      call:    () => api.broadcastToSignbank(row.id),
      onOk:    (res) => {
        row.signbank = res.glossid;
        makeCtx().refreshRow(row);
        toast(`Verbonden met Signbank — glossid #${res.glossid}`, 'success');
      },
    })
  );
}

function disconnectGloss(row) {
  openConfirmModal(
    `Loskoppelen van Signbank — glos "${row.glos}" zal worden verwijderd uit Signbank (#${row.signbank}). Doorgaan?`,
    async () => runSignbankOperation({
      title:   `Loskoppelen — ${row.glos || '#' + row.id} (was #${row.signbank})`,
      busyMsg: 'Verzoek tot verwijdering…',
      row,
      call:    () => api.deleteFromSignbank(row.id),
      onOk:    (res) => {
        row.signbank = null;
        makeCtx().refreshRow(row);
        toast(`Losgekoppeld (was #${res.previous_glossid})`, 'success');
      },
    })
  );
}

/**
 * Run a Signbank operation and render its full result in the existing
 * #signbankModal (same one the push_gloss flow uses). Doesn't auto-close.
 */
async function runSignbankOperation({ title, busyMsg, row, call, onOk }) {
  $('#signbankTitle').textContent = title;
  $('#signbankRetryBtn').onclick = () => runSignbankOperation({ title, busyMsg, row, call, onOk });

  const body = $('#signbankBody');
  const status = $('#signbankFooterStatus');
  clearChildren(body);
  body.appendChild(renderSourceSection(row));
  body.appendChild(renderBanner('info', busyMsg, ''));
  status.className = 'signbank-status busy';
  status.textContent = 'Bezig…';
  $('#signbankModal').classList.remove('hidden');

  let res;
  try {
    res = await call();
  } catch (e) {
    clearChildren(body);
    body.appendChild(renderSourceSection(row));
    body.appendChild(renderBanner('error', 'Verzoek mislukt', e.message));
    status.className = 'signbank-status';
    status.textContent = '';
    return;
  }

  // Re-render with full detail.
  clearChildren(body);
  body.appendChild(renderSourceSection(row));
  body.appendChild(renderSignbankResultBanner(res));
  body.appendChild(renderLogSection(res.log || []));
  // The broadcast endpoint nests the underlying request/response inside `create`.
  const httpReq  = res.request  || res.create?.request  || null;
  const httpRes  = httpReq ? {
    response:     res.response     ?? res.create?.response,
    raw_excerpt:  res.raw_excerpt  ?? res.create?.raw_excerpt,
    content_type: res.content_type ?? res.create?.content_type,
    status:       res.status       ?? res.create?.status,
    duration_ms:  res.duration_ms  ?? res.create?.duration_ms,
    http_error:   res.http_error   ?? res.create?.http_error,
  } : null;
  if (httpReq) body.appendChild(renderRequestSection(httpReq));
  if (httpRes && (httpRes.response !== undefined && httpRes.response !== null))
    body.appendChild(renderResponseSection(httpRes));

  status.className = 'signbank-status';
  status.textContent = res.ok
    ? `Klaar — HTTP ${httpRes?.status ?? ''} in ${httpRes?.duration_ms ?? '?'} ms`
    : `Mislukt — HTTP ${httpRes?.status ?? ''} in ${httpRes?.duration_ms ?? '?'} ms`;

  if (res.ok && typeof onOk === 'function') onOk(res);
}

/* ---------- Signbank compare modal ---------- */

function setupCompareModal() {
  const modal = $('#sbCompareModal');
  modal.addEventListener('click', ev => {
    if (ev.target.matches('[data-close]') || ev.target === modal) modal.classList.add('hidden');
  });
}

async function compareGloss(row) {
  const modal  = $('#sbCompareModal');
  const body   = $('#sbCompareBody');
  const status = $('#sbCompareStatus');
  const link   = $('#sbCompareOpenLink');
  $('#sbCompareTitle').textContent = `Vergelijken met Signbank — ${row.glos || '#' + row.id} (#${row.signbank})`;
  link.hidden = false;
  link.href = `https://signbank.cls.ru.nl/dictionary/gloss/${encodeURIComponent(row.signbank)}.html`;

  clearChildren(body);
  body.appendChild(renderBanner('info', 'Bezig met ophalen van Signbank…', ''));
  status.className = 'signbank-status busy';
  status.textContent = 'Ophalen…';
  modal.classList.remove('hidden');

  let res;
  try {
    res = await api.fetchSignbankGloss(row.id);
  } catch (e) {
    clearChildren(body);
    body.appendChild(renderBanner('error', 'Ophalen mislukt', e.message));
    status.className = 'signbank-status';
    status.textContent = '';
    return;
  }

  clearChildren(body);
  if (!res.ok) {
    const detail = res.error
      || (res.response && (res.response.errors?.[0]?.message || res.response.detail))
      || `HTTP ${res.status}`;
    body.appendChild(renderBanner('error', 'Signbank gaf een fout', detail));
    if (res.response) body.appendChild(renderResponseSection({ response: res.response, content_type: 'application/json' }));
    status.className = 'signbank-status';
    status.textContent = '';
    return;
  }

  const mismatches = res.mismatch_count || 0;
  body.appendChild(renderBanner(mismatches ? 'info' : 'success',
    mismatches ? `${mismatches} verschil${mismatches === 1 ? '' : 'len'} met Signbank`
               : 'Alles komt overeen met Signbank',
    `glossid #${res.glossid} · ${res.duration_ms} ms`));

  // Media side-by-side
  body.appendChild(renderCompareMedia(row, res));

  // Comparison table
  body.appendChild(renderCompareTable(res.fields));

  // Extras (read-only fields)
  body.appendChild(renderCompareExtras(res.remote_extra));

  status.className = 'signbank-status';
  status.textContent = `Klaar — ${res.duration_ms} ms`;
}

function renderCompareMedia(row, res) {
  const wrap = el('section', { class: 'sb-compare-media' });

  // Local pane: latest matched_transcription M-frame, fallback to zelfopname
  const localPane = el('div', { class: 'pane' });
  localPane.appendChild(el('span', { class: 'pane-label' }, 'signCollect'));
  let localSrc = null;
  if (row.thumbnail_video?.m_file) {
    const folder = row.thumbnail_video.post_processed === 1 ? 'post' : 'raw';
    localSrc = `https://signcollect.nl/gebarenoverleg_media/studioFilesMini/${folder}/${encodeURIComponent(row.thumbnail_video.m_file.replace(/\.\w+$/, ''))}.mp4`;
  } else if ((row.zelfopname || []).length) {
    localSrc = `/uploads/${encodeURIComponent(row.zelfopname[0])}`;
  }
  if (localSrc) {
    localPane.appendChild(el('video', { src: localSrc, muted: true, autoplay: true, loop: true, playsinline: true }));
  } else {
    localPane.classList.add('empty');
    localPane.appendChild(el('span', {}, '— geen video —'));
  }

  // Signbank pane: Video URL from get_gloss_data
  const remotePane = el('div', { class: 'pane' });
  remotePane.appendChild(el('span', { class: 'pane-label' }, 'Signbank'));
  const sbVideoPath = res.remote_extra?.Video;
  if (sbVideoPath) {
    const sbVideoUrl = sbVideoPath.startsWith('http')
      ? sbVideoPath
      : 'https://signbank.cls.ru.nl' + sbVideoPath.replace(/^\/+/, '/');
    remotePane.appendChild(el('video', { src: sbVideoUrl, muted: true, autoplay: true, loop: true, playsinline: true, crossorigin: 'anonymous' }));
  } else {
    remotePane.classList.add('empty');
    remotePane.appendChild(el('span', {}, '— Signbank heeft geen video —'));
  }

  wrap.append(localPane, remotePane);
  return wrap;
}

function renderCompareTable(fields) {
  const tbl = el('table', { class: 'sb-compare-table' });
  const thead = el('thead', {}, el('tr', {},
    el('th', { class: 'icon-state' }, ''),
    el('th', {}, 'Veld'),
    el('th', {}, 'signCollect'),
    el('th', {}, 'Signbank'),
  ));
  const tbody = el('tbody', {});
  fields.forEach(f => {
    const tr = el('tr', { class: f.matches ? '' : 'mismatch' });
    tr.appendChild(el('td', { class: 'icon-state' },
      f.matches
        ? el('i', { class: 'fas fa-check ok' })
        : el('i', { class: 'fas fa-triangle-exclamation bad' })
    ));
    tr.appendChild(el('td', {}, f.label));
    tr.appendChild(el('td', { class: f.local  === '' ? 'empty' : '' }, f.local  === '' ? '— leeg —' : f.local));
    tr.appendChild(el('td', { class: f.remote === '' ? 'empty' : '' }, f.remote === '' ? '— leeg —' : f.remote));
    tbody.appendChild(tr);
  });
  tbl.append(thead, tbody);
  return tbl;
}

function renderCompareExtras(extra) {
  if (!extra) return document.createTextNode('');
  const dl = el('dl', { class: 'sb-compare-extras' });
  Object.entries(extra).forEach(([k, v]) => {
    if (v === null || v === undefined || v === '') return;
    dl.appendChild(el('dt', {}, k));
    dl.appendChild(el('dd', {}, Array.isArray(v) ? v.join(' · ') : String(v)));
  });
  return dl;
}

function renderSignbankResultBanner(res) {
  if (res.ok) {
    const id = res.glossid || res.previous_glossid;
    const detail = id ? `glossid: ${id}` : '';
    return renderBanner('success', 'Signbank: succes', detail);
  }
  let detail;
  const httpResponse = res.response ?? res.create?.response;
  if (httpResponse && typeof httpResponse === 'object') {
    if (Array.isArray(httpResponse.errors)) {
      detail = httpResponse.errors
        .map(e => typeof e === 'string' ? e : (e.message || e.code || JSON.stringify(e)))
        .join(' · ');
    } else if (httpResponse._html_error) {
      detail = `${httpResponse.h1 || httpResponse.title || 'Server error'} — ${httpResponse.detail || ''}`.trim();
    } else if (httpResponse.error) {
      detail = httpResponse.error;
    } else {
      detail = JSON.stringify(httpResponse).slice(0, 200);
    }
  } else if (typeof httpResponse === 'string') {
    detail = httpResponse.slice(0, 200);
  } else {
    detail = `HTTP ${res.status ?? '?'}`;
  }
  return renderBanner('error', 'Signbank gaf een fout', detail);
}

/* ---------- Context toggle (Signbank / Signio) ---------- */

function setupContextToggle() {
  const opts = document.querySelectorAll('.context-toggle .ctx-opt');
  // Reflect current state on the buttons + label
  opts.forEach(b => b.classList.toggle('active', b.dataset.ctx === state.context));
  $('#contextTag').textContent = state.context === 'signbank' ? 'Signbank' : 'Signio';
  applyContextClass();
  opts.forEach(btn => {
    btn.addEventListener('click', () => {
      const next = btn.dataset.ctx;
      if (next === state.context) return;
      state.context = next;
      opts.forEach(b => b.classList.toggle('active', b.dataset.ctx === next));
      $('#contextTag').textContent = next === 'signbank' ? 'Signbank' : 'Signio';
      applyContextClass();
      state.page = 1;
      refresh();
    });
  });
}

function applyContextClass() {
  document.body.classList.toggle('ctx-signbank', state.context === 'signbank');
}

/* ---------- Navigation drawer ---------- */

function setupNavDrawer() {
  const drawer  = $('#navDrawer');
  const overlay = $('#navDrawerOverlay');

  // Conditional sections
  if ((state.user.role || '').toLowerCase() === 'admin') {
    document.querySelectorAll('.nav-drawer .admin-only').forEach(s => s.hidden = false);
  }
  if ((state.user.username || '').toLowerCase() === 'gomer' || String(state.user.userId) === '1') {
    document.querySelectorAll('.nav-drawer .gomer-only').forEach(s => s.hidden = false);
  }

  const open = () => {
    drawer.classList.remove('hidden');
    overlay.classList.remove('hidden');
    drawer.setAttribute('aria-hidden', 'false');
  };
  const close = () => {
    drawer.classList.add('hidden');
    overlay.classList.add('hidden');
    drawer.setAttribute('aria-hidden', 'true');
  };

  $('#burgerBtn').addEventListener('click', open);
  overlay.addEventListener('click', close);
  drawer.querySelectorAll('[data-drawer-close]').forEach(b => b.addEventListener('click', close));
  window.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && !drawer.classList.contains('hidden')) close();
  });

  $('#navLogout').addEventListener('click', (ev) => {
    ev.preventDefault();
    // Clear the shared sessionObject cookie on the apex domain and the local path.
    document.cookie = 'sessionObject=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=.signcollect.nl';
    document.cookie = 'sessionObject=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
    location.href = '/login.html';
  });

  // Default closed
  close();
}

/* ---------- Phonology modal ---------- */

function setupPhonologyModal() {
  const modal = $('#phonologyModal');
  modal.addEventListener('click', (ev) => {
    if (ev.target.matches('[data-close]') || ev.target === modal) closePhonologyModal();
  });
}

let phonologySaveTimer = null;

async function openPhonologyModal(row) {
  $('#phonologyTitle').textContent = `Fonologie — ${row.glos || '#' + row.id}`;
  const body = $('#phonologyBody');
  clearChildren(body);
  body.appendChild(el('div', { class: 'empty-state' }, el('i', { class: 'fas fa-spinner fa-spin' }), el('p', {}, 'Laden…')));
  $('#phonologyStatus').textContent = '';
  $('#phonologyModal').classList.remove('hidden');

  try {
    const form = await buildPhonologyForm({
      row,
      onSave: (fields) => savePhonologyDebounced(row, fields),
    });
    clearChildren(body);
    body.appendChild(form);
  } catch (e) {
    clearChildren(body);
    body.appendChild(el('div', { class: 'empty-state' }, `Fout bij laden: ${e.message}`));
  }
}

function savePhonologyDebounced(row, fields) {
  const status = $('#phonologyStatus');
  status.className = 'phono-status saving';
  status.textContent = 'Opslaan…';
  clearTimeout(phonologySaveTimer);
  phonologySaveTimer = setTimeout(async () => {
    try {
      await api.save(row.id, fields);
      status.className = 'phono-status saved';
      status.textContent = 'Opgeslagen ✓';
      setTimeout(() => {
        if (status.classList.contains('saved')) {
          status.className = 'phono-status';
          status.textContent = '';
        }
      }, 1800);
    } catch (e) {
      status.className = 'phono-status';
      status.textContent = '';
      toast('Opslaan mislukt: ' + e.message, 'error');
    }
  }, 250);
}

function closePhonologyModal() {
  clearTimeout(phonologySaveTimer);
  $('#phonologyModal').classList.add('hidden');
}

/* ---------- Confirm modal ---------- */

let confirmCallback = null;

function setupConfirmModal() {
  const modal = $('#confirmModal');
  modal.addEventListener('click', ev => {
    if (ev.target.matches('[data-close]') || ev.target === modal) closeModal('#confirmModal');
  });
  $('#confirmOk').addEventListener('click', async () => {
    const cb = confirmCallback;
    confirmCallback = null;
    closeModal('#confirmModal');
    if (cb) {
      try { await cb(); } catch (e) { toast(e.message, 'error'); }
    }
  });
}

function openConfirmModal(msg, onYes) {
  $('#confirmBody').textContent = msg;
  confirmCallback = onYes;
  $('#confirmModal').classList.remove('hidden');
}

init().catch(e => {
  const pre = el('pre', { style: 'padding:24px;color:#b00' }, 'Init failed: ' + e.message);
  clearChildren(document.body);
  document.body.appendChild(pre);
});
