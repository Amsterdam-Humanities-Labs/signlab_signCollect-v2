import { el, debounce, toast, extractUserTokens } from './util.js';
import { api } from './api.js';
import { renderSensesPair } from './sensesPair.js';
import { renderLabelEditor } from './labelEditor.js';

const UPLOADS_BASE = '/uploads';
const STUDIO_BASE  = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini';
const LSM_BASE     = '/uploads/lsm';

function studioMp4Url(basename, postProcessed) {
  if (!basename) return null;
  const stem = basename.replace(/\.\w+$/, '');
  const folder = postProcessed === 1 ? 'post' : 'raw';
  return `${STUDIO_BASE}/${folder}/${encodeURIComponent(stem)}.mp4`;
}

// LSM matched_transcriptions are uploaded by lsm_video_upload.php to
// /web/uploads/lsm/<gloss_id>_<sha-prefix>.<ext> — `m_file` already
// carries the full filename with extension, no transcoding involved.
function lsmMp4Url(filename) {
  if (!filename) return null;
  return `${LSM_BASE}/${encodeURIComponent(filename)}`;
}

// FIFO loader with bounded concurrency. Sources attach in render order,
// max N at a time so the browser doesn't stall fetching 50 videos at once.
const THUMB_LOAD_CONCURRENCY = 4;
const thumbQueue = [];
let thumbActive = 0;
let thumbQueueId = 0;

function pumpThumbQueue() {
  while (thumbActive < THUMB_LOAD_CONCURRENCY && thumbQueue.length) {
    const job = thumbQueue.shift();
    if (job.cancelled) continue;
    thumbActive++;
    const release = () => {
      thumbActive--;
      job.video.removeEventListener('loadedmetadata', release);
      job.video.removeEventListener('error', release);
      pumpThumbQueue();
    };
    job.video.addEventListener('loadedmetadata', release, { once: true });
    job.video.addEventListener('error', release, { once: true });
    job.video.src = job.src;
  }
}

function queueThumbLoad(video, src) {
  const job = { video, src, cancelled: false, id: ++thumbQueueId };
  thumbQueue.push(job);
  pumpThumbQueue();
  return job;
}

function resetThumbQueue() {
  thumbQueue.forEach(j => { j.cancelled = true; });
  thumbQueue.length = 0;
}

export { resetThumbQueue };

export function renderRow(row, ctx) {
  const wrap = el('article', {
    class: 'gloss-row' + (row.glosZichtbaar === 1 ? ' hidden-row' : ''),
    dataset: { id: row.id },
  });

  wrap.append(
    renderThumbCol(row, ctx, wrap),
    renderMainCol(row, ctx),
    renderMetaCol(row, ctx),
    renderActionsCol(row, ctx, wrap),
  );
  return wrap;
}

function renderThumbCol(row, ctx, rowWrap) {
  const col = el('div', { class: 'col-thumb' });

  let videoSrc = null;
  if (row.thumbnail_video && row.thumbnail_video.m_file) {
    videoSrc = (ctx.datasetCode && ctx.datasetCode() === 'lsm')
      ? lsmMp4Url(row.thumbnail_video.m_file)
      : studioMp4Url(row.thumbnail_video.m_file, row.thumbnail_video.post_processed);
  } else if (row.zelfopname.length) {
    videoSrc = `${UPLOADS_BASE}/${encodeURIComponent(row.zelfopname[0])}`;
  }

  const thumbWrap = el('div', { class: videoSrc ? 'thumb-wrap' : 'thumb-wrap empty' });
  if (videoSrc) {
    const v = el('video', {
      muted: true, playsinline: true, preload: 'metadata', loop: true,
    });
    queueThumbLoad(v, videoSrc);
    thumbWrap.appendChild(v);
    rowWrap.addEventListener('mouseenter', () => v.play().catch(() => {}));
    rowWrap.addEventListener('mouseleave', () => { v.pause(); v.currentTime = 0; });

    const applyAspect = () => {
      if (v.videoWidth && v.videoHeight) {
        thumbWrap.style.setProperty('--natural-aspect', `${v.videoWidth} / ${v.videoHeight}`);
      }
    };
    v.addEventListener('loadedmetadata', applyAspect);
    thumbWrap.addEventListener('click', () => {
      applyAspect();
      thumbWrap.classList.add('zoomed');
    });
    thumbWrap.addEventListener('mouseleave', () => thumbWrap.classList.remove('zoomed'));
  } else {
    thumbWrap.classList.add('record-prompt');
    thumbWrap.title = 'Klik om zelfopname te maken';
    thumbWrap.appendChild(el('i', { class: 'fas fa-video-slash' }));
    thumbWrap.appendChild(el('span', { class: 'record-prompt-label' }, 'Maak zelfopname'));
    thumbWrap.addEventListener('click', () => ctx.openRecord(row));
  }
  col.appendChild(thumbWrap);

  const files = el('div', { class: 'thumb-files' });
  row.zelfopname.forEach((fname, idx) => {
    const pill = el('span', {
      class: 'file-pill' + (idx === 0 ? ' active' : ''),
      title: fname,
    },
      el('i', { class: 'fas fa-film' }),
      `#${idx + 1}`,
      el('button', {
        type: 'button', class: 'x', title: 'Verwijderen',
        onclick: async (ev) => {
          ev.stopPropagation();
          ctx.openConfirm(`Zelfopname ${idx + 1} verwijderen uit deze glos?`, async () => {
            const upd = await api.deleteVideo(row.id, fname);
            row.zelfopname = upd.zelfopname;
            ctx.refreshRow(row);
          });
        }
      }, '×')
    );
    pill.addEventListener('click', () => {
      const v = thumbWrap.querySelector('video');
      if (v) {
        v.src = `${UPLOADS_BASE}/${encodeURIComponent(fname)}`;
        v.play().catch(() => {});
      }
      files.querySelectorAll('.file-pill').forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
    });
    files.appendChild(pill);
  });
  if (row.zelfopname.length > 1) col.appendChild(files);

  col.appendChild(el('button', {
    type: 'button',
    class: 'thumb-action-btn',
    onclick: () => ctx.openPhonology(row),
  },
    el('i', { class: 'fas fa-hand-spock' }),
    'Fonologie bewerken',
  ));

  col.appendChild(el('div', { class: 'row-id' },
    el('i', { class: 'fas fa-hashtag' }),
    String(row.id),
  ));

  if (row.signbank) {
    col.appendChild(el('a', {
      class: 'signbank-badge',
      href: `${ctx.signbankBaseUrl ? ctx.signbankBaseUrl() : 'https://signbank.cls.ru.nl'}/dictionary/gloss/${encodeURIComponent(row.signbank)}.html`,
      target: '_blank',
      title: `Verbonden met Signbank glos #${row.signbank}`,
    },
      el('i', { class: 'fas fa-link' }),
      `SB #${row.signbank}`,
    ));
  }

  return col;
}

function renderMainCol(row, ctx) {
  const col = el('div', { class: 'col-main' });

  const fields = el('div', { class: 'glos-fields' });
  fields.append(
    field('Glos NL', row.glos,        val => save(row, { glos: val }), { uppercase: true }),
    field('Glos EN', row.glos_engels, val => save(row, { glos_engels: val }), { uppercase: true }),
  );
  col.appendChild(fields);

  if (row.duplicates && row.duplicates.length) {
    const dupBlock = el('div', { class: 'duplicate-hint' });
    dupBlock.appendChild(el('i', { class: 'fas fa-clone' }));
    dupBlock.appendChild(el('span', { class: 'duplicate-label' }, 'Duplicaat van: '));
    row.duplicates.forEach((d, i) => {
      if (i > 0) dupBlock.appendChild(document.createTextNode(', '));
      const tokens     = extractUserTokens(d.wie);
      const ownerNames = tokens.map(t => /^\d+$/.test(t) ? ctx.userName(t) : t)
                              .filter(Boolean).join(', ') || '—';
      const externFlag = String(d.extern || '') === '1' ? ' · extern' : '';
      const glos = d.glos || '?';
      const chip = el('span', {
        class: 'duplicate-chip',
        title: `id ${d.id}`,
      }, `${glos} — ${ownerNames}${externFlag} (id ${d.id})`);
      dupBlock.appendChild(chip);
    });
    col.appendChild(dupBlock);
  }

  const themaBlock = el('div', { class: 'meta-block' });
  themaBlock.append(
    el('span', { class: 'meta-label' }, 'Thema'),
    renderThemaSelect(row, ctx),
  );
  col.appendChild(themaBlock);

  const labelsBlock = el('div', { class: 'meta-block' });
  labelsBlock.append(
    el('span', { class: 'meta-label' }, 'Labels'),
    renderLabelEditor({
      value: row.labels,
      options: ctx.labelOptions().map(l => ({ value: l.label, label: l.label, color: l.color })),
      onChange: (arr) => { row.labels = arr; save(row, { labels: arr }); }
    }),
  );
  col.appendChild(labelsBlock);

  const sensesBlock = el('div', { class: 'meta-block' });
  sensesBlock.append(
    el('span', { class: 'meta-label' }, 'Senses (NL / EN)'),
    renderSensesPair({
      nl: row.senses,
      en: row.sensesEngels,
      onChange: ({ nl, en }) => {
        row.senses = nl;
        row.sensesEngels = en;
        save(row, { senses: nl, sensesEngels: en });
      },
    }),
  );
  col.appendChild(sensesBlock);

  if (row.studio_videos.length) {
    const studio = el('div', { class: 'studio-videos' });
    const btn = el('button', {
      type: 'button',
      class: 'studio-open-btn',
      onclick: () => ctx.openStudio(row),
    },
      el('i', { class: 'fas fa-clapperboard' }),
      `Studio video's`
    );
    studio.appendChild(btn);
    col.appendChild(studio);
  }

  return col;
}

function renderMetaCol(row, ctx) {
  const col = el('div', { class: 'col-meta' });

  const wieBlock = el('div', { class: 'meta-block' });
  wieBlock.append(
    el('span', { class: 'meta-label' }, 'Wie'),
    renderLabelEditor({
      value: row.wie,
      options: ctx.userOptions(),
      onChange: (newWie) => {
        const removed = row.wie.filter(uid => !newWie.includes(String(uid)));
        const updates = { wie: newWie };
        if (removed.length) {
          row.control_nodig = row.control_nodig.filter(uid => !removed.includes(String(uid)));
          updates.control_nodig = row.control_nodig;
        }
        row.wie = newWie;
        save(row, updates).then(() => ctx.refreshRow(row));
      },
      placeholder: '+ gebruiker…',
      allowFreeText: false,
    }),
  );
  col.appendChild(wieBlock);

  const gecBlock = el('div', { class: 'meta-block' });
  gecBlock.append(el('span', { class: 'meta-label' }, 'Gecontroleerd'));
  const isKlaar = String(row.fonologie_fase1 ?? '') === '1';
  const toggle = el('button', {
    type: 'button',
    class: 'gec-state gec-toggle ' + (isKlaar ? 'klaar' : 'bezig'),
    title: 'Klik om te wisselen tussen klaar / niet klaar',
    onclick: () => {
      const next = isKlaar ? '0' : '1';
      row.fonologie_fase1 = next;
      save(row, { fonologie_fase1: next });
      ctx.refreshRow(row);
    },
  },
    el('i', { class: 'fas ' + (isKlaar ? 'fa-check' : 'fa-clock') }),
    isKlaar ? 'klaar' : 'niet klaar',
  );
  gecBlock.appendChild(toggle);
  col.appendChild(gecBlock);

  return col;
}

function renderActionsCol(row, ctx, rowWrap) {
  const col = el('div', { class: 'col-actions' });

  const summary = el('summary', {}, el('i', { class: 'fas fa-ellipsis-vertical' }));
  const pop = el('div', { class: 'menu-pop' });
  pop.appendChild(itemBtn('record', 'fa-video', 'Zelfopname maken', () => ctx.openRecord(row)));
  if (ctx.contextIsSignbank()) {
    if (row.signbank) {
      pop.appendChild(itemBtn('signbank-compare', 'fa-magnifying-glass',
        `Vergelijken met Signbank (#${row.signbank})`, () => ctx.compareWithSignbank(row)));
      pop.appendChild(itemBtn('signbank-disconnect', 'fa-link-slash',
        `Loskoppelen van Signbank (#${row.signbank})`, () => ctx.disconnectSignbank(row), true));
    } else {
      pop.appendChild(itemBtn('signbank-broadcast', 'fa-cloud-arrow-up',
        'Broadcast naar Signbank', () => ctx.broadcastToSignbank(row)));
    }
  }
  if (row.zelfopname.length > 0) {
    const label = row.zelfopname.length === 1
      ? 'Zelfopname verwijderen'
      : `Zelfopnames verwijderen (${row.zelfopname.length})`;
    pop.appendChild(itemBtn('delete-zelfopname', 'fa-video-slash', label, () => ctx.deleteAllZelfopname(row), true));
  }
  pop.appendChild(itemBtn('toggle-vis', row.glosZichtbaar === 0 ? 'fa-eye-slash' : 'fa-eye',
    row.glosZichtbaar === 0 ? 'Verbergen' : 'Zichtbaar maken',
    () => {
      const next = row.glosZichtbaar === 0 ? 1 : 0;
      row.glosZichtbaar = next;
      save(row, { glosZichtbaar: next });
      ctx.refreshRow(row);
    }));
  pop.appendChild(itemBtn('delete', 'fa-trash', 'Verwijderen', () => {
    ctx.openConfirm(`Glos "${row.glos || '#' + row.id}" verbergen?`, async () => {
      await api.remove(row.id);
      row.glosZichtbaar = 1;
      ctx.refreshRow(row);
      toast('Glos verborgen', 'success');
    });
  }, true));

  const det = el('details', { class: 'row-menu' }, summary, pop);
  // close menu after click
  det.addEventListener('click', ev => {
    if (ev.target.closest('.menu-pop button')) det.removeAttribute('open');
  });
  col.appendChild(det);

  return col;
}

function field(label, value, onSave, opts = {}) {
  const input = el('input', { type: 'text', value: value || '' });
  if (opts.uppercase) input.classList.add('field-uppercase');
  const norm = (s) => opts.uppercase ? s.trim().toUpperCase() : s.trim();
  const commit = debounce(() => onSave(norm(input.value)), 400);
  input.addEventListener('input', commit);
  input.addEventListener('blur',  () => onSave(norm(input.value)));
  return el('label', { class: 'field' }, label, input);
}

function renderThemaSelect(row, ctx) {
  const themas = ctx.themaOptions();
  const select = el('select', {
    class: 'thema-select' + (row.thema ? '' : ' empty'),
    onchange: (ev) => {
      const v = ev.target.value;
      row.thema = v;
      ev.target.classList.toggle('empty', !v);
      save(row, { thema: v });
    }
  });
  select.appendChild(el('option', { value: '' }, '— geen thema —'));
  // ensure current value is present even if not in catalog
  const set = new Set(themas);
  if (row.thema && !set.has(row.thema)) {
    select.appendChild(el('option', { value: row.thema, selected: true }, row.thema + ' (custom)'));
  }
  themas.forEach(t => {
    select.appendChild(el('option', { value: t, selected: t === row.thema }, t));
  });
  return select;
}

function itemBtn(kind, icon, text, onclick, danger = false) {
  return el('button', {
    type: 'button',
    class: danger ? 'danger' : '',
    onclick,
  }, el('i', { class: 'fas ' + icon }), text);
}

async function save(row, fields) {
  try {
    await api.save(row.id, fields);
  } catch (e) {
    toast('Opslaan mislukt: ' + e.message, 'error');
  }
}
