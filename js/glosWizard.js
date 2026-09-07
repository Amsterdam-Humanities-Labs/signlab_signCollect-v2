import { el, toast } from './util.js';
import { api } from './api.js';
import { t } from './i18n.js';

/**
 * The Glos Wizard, ported from the legacy menu_old interface.
 *
 * It answers one question before a sign is collected: does this already
 * exist? A word goes in; out come the matching glosses from both this
 * collection and the Signbank ECV, side by side, each with the video to
 * check it against. From there the user either adopts a Signbank gloss -
 * which creates a local row already carrying its id, senses and phonology -
 * or creates a new gloss under a name the wizard proves is free.
 *
 * The batch list is the same feature the old wizard had: paste a column of
 * words, work through them one at a time without retyping.
 */

const state = {
  batch: [],       // words still to work through, [] when not in batch mode
  batchIndex: 0,
  suggestion: null, // last wizard_suggest.php answer for the current input
  results: [],
};

/** Gloss names are uppercase and hyphenated; normalise as the user types. */
export function normaliseGlos(v) {
  return String(v || '').toUpperCase().trim().replace(/\s+/g, '-');
}

const $ = (sel) => document.querySelector(sel);

export function setupGlosWizard() {
  const modal = $('#glosWizardModal');
  if (!modal) return;

  modal.addEventListener('click', (ev) => {
    if (ev.target.matches('[data-close]') || ev.target === modal) closeGlosWizard();
  });

  const input = $('#wizardGlosInput');
  input.addEventListener('input', () => {
    const caretAtEnd = input.selectionStart === input.value.length;
    input.value = normaliseGlos(input.value);
    if (caretAtEnd) input.setSelectionRange(input.value.length, input.value.length);
    state.suggestion = null;
    setCreateLabel(t('wizard.btn.create'));
  });
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') { ev.preventDefault(); search(); }
  });

  $('#wizardSearchBtn').addEventListener('click', search);
  $('#wizardCreateBtn').addEventListener('click', create);
  $('#wizardBatchStartBtn').addEventListener('click', batchStart);
  $('#wizardBatchNextBtn').addEventListener('click', batchNext);
  $('#wizardBatchList').addEventListener('input', (ev) => {
    // Grow with its content - the list is usually a dozen short lines.
    ev.target.style.height = 'auto';
    ev.target.style.height = ev.target.scrollHeight + 'px';
    resetBatch();
  });
}

export function openGlosWizard() {
  const modal = $('#glosWizardModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  setMessage('');
  setTimeout(() => $('#wizardGlosInput').focus(), 50);
}

export function closeGlosWizard() {
  $('#glosWizardModal').classList.add('hidden');
}

/* ---------- search ---------- */

async function search() {
  const q = $('#wizardGlosInput').value.trim();
  if (!q) return;

  const box = $('#wizardResults');
  box.replaceChildren(el('div', { class: 'empty-state' },
    el('i', { class: 'fas fa-spinner fa-spin' }),
    el('p', {}, t('wizard.searching'))));

  try {
    const res = await api.wizardSearch(q);
    state.results = res.results || [];
    renderResults(res);
  } catch (e) {
    box.replaceChildren(el('div', { class: 'empty-state' }, t('toast.error_loading') + ': ' + e.message));
  }
}

function renderResults(res) {
  const box = $('#wizardResults');
  box.replaceChildren();

  if (!res.ecv) {
    box.appendChild(el('div', { class: 'wizard-banner warn' },
      el('i', { class: 'fas fa-triangle-exclamation' }),
      el('span', {}, t('wizard.no_ecv'))));
  }

  if (!state.results.length) {
    box.appendChild(el('div', { class: 'empty-state' },
      el('i', { class: 'fas fa-folder-open' }),
      el('p', {}, t('wizard.no_results'))));
    return;
  }

  const grid = el('div', { class: 'wizard-grid' });
  state.results.forEach((item, i) => grid.appendChild(renderCard(item, i)));
  box.appendChild(grid);

  if (res.truncated) {
    box.appendChild(el('p', { class: 'wizard-truncated' }, t('wizard.truncated')));
  }
}

function renderCard(item, index) {
  const isSignbank = item.source === 'signbank';

  const title = item.link
    ? el('a', { href: item.link, target: '_blank', rel: 'noopener' }, item.glos)
    : el('span', {}, item.glos);

  const reason = (item.reason && item.reason.length ? item.reason : item.senses || []).join(', ');

  const card = el('article', { class: `wizard-card ${item.source}` },
    el('header', {},
      el('h4', {}, title),
      el('span', { class: `wizard-src ${item.source}` }, isSignbank ? 'Signbank' : 'signCollect'),
    ),
    reason ? el('p', { class: 'wizard-reason' }, reason) : null,
    item.glos_engels ? el('p', { class: 'wizard-en' }, item.glos_engels) : null,
  );

  if (item.video) card.appendChild(previewVideo(item));

  if (isSignbank) {
    card.appendChild(el('footer', {},
      el('button', {
        type: 'button',
        class: 'btn btn-primary btn-sm',
        onclick: (ev) => adopt(item, ev.currentTarget),
      }, el('i', { class: 'fas fa-plus' }), ' ' + t('wizard.btn.adopt'))));
  }

  card.dataset.index = String(index);
  return card;
}

/**
 * Cards preview on hover, the way the old wizard's did: silent, from the
 * start, and stopped again on the way out. Signbank's own video URLs need a
 * session on signbank.cls.ru.nl, so a Signbank card plays the local copy
 * under /uploads if one was ever fetched, and simply shows nothing if not.
 */
function previewVideo(item) {
  const src = item.source === 'signbank'
    ? '/uploads/' + encodeURIComponent(item.glos) + '.mp4'
    : item.video;

  const video = el('video', { class: 'wizard-video', muted: true, preload: 'none', playsInline: true, src });
  video.addEventListener('mouseover', () => { video.currentTime = 0; video.play().catch(() => {}); });
  video.addEventListener('mouseout',  () => { video.pause(); video.currentTime = 0; });
  video.addEventListener('error', () => video.remove());
  return video;
}

/* ---------- create ---------- */

/** Adopt a Signbank gloss: a local row carrying its id, senses and phonology. */
async function adopt(item, button) {
  button.disabled = true;
  try {
    await api.create({
      glos: item.glos,
      glos_engels: item.glos_engels,
      senses: item.senses || [],
      sensesEngels: item.sensesEngels || [],
      signbank: item.signbank,
      phonology: item.phonology || {},
      // Signbank has already described the sign, so neither fonologie phase
      // is outstanding for this row.
      fonologie_fase1: 1,
      fonologie_fase2: 1,
    });
    toast(t('wizard.toast.adopted', { glos: item.glos }), 'success');
    button.replaceWith(el('span', { class: 'wizard-done' },
      el('i', { class: 'fas fa-check' }), ' ' + t('wizard.adopted')));
  } catch (e) {
    button.disabled = false;
    toast(t('wizard.toast.create_failed', { msg: e.message }), 'error');
  }
}

/**
 * Creating a new gloss takes two clicks, as it always has. The first asks
 * wizard_suggest.php what the name should be and shows the answer - "free"
 * or "taken, here is the next letter". The second creates it, under the
 * name the user has now seen.
 */
async function create() {
  const raw = $('#wizardGlosInput').value.trim();
  if (!raw) { setMessage(t('wizard.msg.empty'), 'warn'); return; }

  const btn = $('#wizardCreateBtn');
  btn.disabled = true;
  try {
    // Phase one whenever the field no longer holds the name we last
    // proposed - including the very first click, and any hand edit after
    // one. Phase two only ever creates a name the user has been shown.
    if (!state.suggestion || normaliseGlos(raw) !== state.suggestion.glos) {
      const s = await api.wizardSuggest(raw);
      state.suggestion = s;
      if (!s.glos) { setMessage(t('wizard.msg.no_free_name'), 'warn'); return; }
      $('#wizardGlosInput').value = s.glos;
      setMessage(s.exists ? t('wizard.msg.exists', { glos: s.glos }) : t('wizard.msg.free', { glos: s.glos }),
                 s.exists ? 'warn' : 'ok');
      setCreateLabel(t('wizard.btn.confirm'));
      search();
      return;
    }

    const s = state.suggestion;
    await api.create({ glos: s.glos, senses: s.senses || [] });
    setMessage(t('wizard.msg.created', { glos: s.glos }), 'ok');
    toast(t('toast.gloss_created'), 'success');
    state.suggestion = null;
    setCreateLabel(t('wizard.btn.create'));
    if (state.batch.length) batchNext(); else $('#wizardGlosInput').value = '';
  } catch (e) {
    setMessage(e.message, 'warn');
  } finally {
    btn.disabled = false;
  }
}

/* ---------- batch list ---------- */

function batchStart() {
  const words = $('#wizardBatchList').value.split('\n').map(normaliseGlos).filter(Boolean);
  if (!words.length) { setMessage(t('wizard.msg.empty_list'), 'warn'); return; }

  state.batch = words;
  state.batchIndex = 0;
  $('#wizardBatchList').disabled = true;
  $('#wizardBatchStartBtn').hidden = true;
  $('#wizardBatchNextBtn').hidden = false;
  loadBatchWord();
}

function batchNext() {
  state.batchIndex += 1;
  if (state.batchIndex >= state.batch.length) { resetBatch(); return; }
  loadBatchWord();
}

function loadBatchWord() {
  state.suggestion = null;
  setCreateLabel(t('wizard.btn.create'));
  $('#wizardGlosInput').value = state.batch[state.batchIndex];
  $('#wizardBatchProgress').textContent =
    t('wizard.batch.progress', { n: state.batchIndex + 1, total: state.batch.length });
  search();
}

function resetBatch() {
  state.batch = [];
  state.batchIndex = 0;
  $('#wizardBatchList').disabled = false;
  $('#wizardBatchStartBtn').hidden = false;
  $('#wizardBatchNextBtn').hidden = true;
  $('#wizardBatchProgress').textContent = '';
}

/* ---------- small helpers ---------- */

function setCreateLabel(text) {
  $('#wizardCreateBtn').querySelector('.label').textContent = text;
}

function setMessage(text, kind) {
  const box = $('#wizardMessage');
  box.className = 'wizard-message' + (kind ? ' ' + kind : '');
  box.textContent = text || '';
}
