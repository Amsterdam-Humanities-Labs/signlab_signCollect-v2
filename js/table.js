import { el, debounce, toast } from './util.js';
import { api } from './api.js';
import { renderSenses } from './senses.js';
import { renderLabelEditor } from './labelEditor.js';

const UPLOADS_BASE = '/uploads';
const STUDIO_BASE  = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini';

function studioMp4Url(basename, postProcessed) {
  if (!basename) return null;
  const stem = basename.replace(/\.\w+$/, '');
  const folder = postProcessed === 1 ? 'post' : 'raw';
  return `${STUDIO_BASE}/${folder}/${encodeURIComponent(stem)}.mp4`;
}

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
    videoSrc = studioMp4Url(row.thumbnail_video.m_file, row.thumbnail_video.post_processed);
  } else if (row.zelfopname.length) {
    videoSrc = `${UPLOADS_BASE}/${encodeURIComponent(row.zelfopname[0])}`;
  }

  const thumbWrap = el('div', { class: videoSrc ? 'thumb-wrap' : 'thumb-wrap empty' });
  if (videoSrc) {
    const v = el('video', {
      src: videoSrc,
      muted: true, playsinline: true, preload: 'metadata', loop: true,
    });
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
    thumbWrap.appendChild(el('i', { class: 'fas fa-video-slash' }));
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

  return col;
}

function renderMainCol(row, ctx) {
  const col = el('div', { class: 'col-main' });

  const fields = el('div', { class: 'glos-fields' });
  fields.append(
    field('Glos NL', row.glos, val => save(row, { glos: val })),
    field('Glos EN', row.glos_engels, val => save(row, { glos_engels: val })),
  );
  col.appendChild(fields);

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

  const sensesPair = el('div', { class: 'senses-pair' });
  const sensesNL = el('div', { class: 'meta-block' });
  sensesNL.append(
    el('span', { class: 'meta-label' }, 'Senses NL'),
    renderSenses(row.senses, { onChange: arr => { row.senses = arr; save(row, { senses: arr }); } }),
  );
  const sensesEN = el('div', { class: 'meta-block' });
  sensesEN.append(
    el('span', { class: 'meta-label' }, 'Senses EN'),
    renderSenses(row.sensesEngels, { onChange: arr => { row.sensesEngels = arr; save(row, { sensesEngels: arr }); }, placeholder: 'Sense (EN)…' }),
  );
  sensesPair.append(sensesNL, sensesEN);
  col.appendChild(sensesPair);

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
  if (!row.wie.length) {
    gecBlock.appendChild(el('span', { class: 'gec-state klaar' }, el('i', { class: 'fas fa-check' }), 'klaar'));
  } else {
    const allKlaar = row.control_nodig.length === 0;
    const summary = el('summary', {},
      el('span', {
        class: 'gec-state ' + (allKlaar ? 'klaar' : 'bezig')
      },
        el('i', { class: 'fas ' + (allKlaar ? 'fa-check' : 'fa-clock') }),
        allKlaar ? 'klaar' : `niet klaar (${row.control_nodig.length})`
      )
    );
    const opts = el('div', { class: 'gec-options' });
    row.wie.forEach(uid => {
      const checked = row.control_nodig.includes(uid);
      const opt = el('label', {},
        el('input', {
          type: 'checkbox',
          checked,
          onchange: (ev) => {
            const set = new Set(row.control_nodig);
            if (ev.target.checked) set.add(uid); else set.delete(uid);
            row.control_nodig = Array.from(set);
            save(row, { control_nodig: row.control_nodig });
            ctx.refreshRow(row);
          }
        }),
        ctx.userName(uid) + (checked ? ' — moet nog' : ' — klaar')
      );
      opts.appendChild(opt);
    });
    const det = el('details', { class: 'gec-control' }, summary, opts);
    gecBlock.appendChild(det);
  }
  col.appendChild(gecBlock);

  return col;
}

function renderActionsCol(row, ctx, rowWrap) {
  const col = el('div', { class: 'col-actions' });

  const summary = el('summary', {}, el('i', { class: 'fas fa-ellipsis-vertical' }));
  const pop = el('div', { class: 'menu-pop' });
  pop.appendChild(itemBtn('record', 'fa-video', 'Zelfopname maken', () => ctx.openRecord(row)));
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

function field(label, value, onSave) {
  const input = el('input', { type: 'text', value: value || '' });
  const commit = debounce(() => onSave(input.value.trim()), 400);
  input.addEventListener('input', commit);
  input.addEventListener('blur',  () => onSave(input.value.trim()));
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
