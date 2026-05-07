import { el, toast } from './util.js';
import { api } from './api.js';

export const PHONOLOGY_FIELDS = [
  { key: 'Handeness',                  label: 'Handedness',                    type: 'select' },
  { key: 'strongHand',                 label: 'Strong Hand',                   type: 'select' },
  { key: 'weakHand',                   label: 'Weak Hand',                     type: 'select' },
  { key: 'HandshapeChange',            label: 'Handshape Change',              type: 'select' },
  { key: 'RelationArticulators',       label: 'Relation Between Articulators', type: 'select' },
  { key: 'handLocation',               label: 'Location',                      type: 'select' },
  { key: 'ContactType',                label: 'Contact Type',                  type: 'select' },
  { key: 'MovementShape',              label: 'Movement Shape',                type: 'select' },
  { key: 'MovementDirection',          label: 'Movement Direction',            type: 'select' },
  { key: 'RepeatedMovement',           label: 'Repeated Movement',             type: 'select' },
  { key: 'AlternatingMovement',        label: 'Alternating Movement',          type: 'select' },
  { key: 'relativeOrienationMovement', label: 'Relative Orientation: Movement',type: 'select' },
  { key: 'relativeOrienationLocation', label: 'Relative Orientation: Location',type: 'select' },
  { key: 'orientationChange',          label: 'Orientation Change',            type: 'select' },
  { key: 'virtualObjectt',             label: 'Virtual object',                type: 'text'   },
  { key: 'phonologyOther',             label: 'Phonology: other',              type: 'text'   },
  { key: 'mouthGesture',               label: 'Mouth Gesture',                 type: 'text'   },
  { key: 'mouthing',                   label: 'Mouthing',                      type: 'text'   },
  { key: 'phoneticVariation',          label: 'Phonetic Variation',            type: 'text'   },
];

export const FASE_FIELDS = [
  { key: 'fonologie_fase1', label: 'Fonologie Fase 1 Klaar?' },
  { key: 'fonologie_fase2', label: 'Fonologie Fase 2 Klaar?' },
];

let selectsDataPromise = null;
function loadSelectsData() {
  if (!selectsDataPromise) {
    selectsDataPromise = fetch('data/phonology_options.json')
      .then(r => r.ok ? r.json() : Promise.reject(new Error('phonology_options fetch failed')))
      .catch(err => { selectsDataPromise = null; throw err; });
  }
  return selectsDataPromise;
}

export async function buildPhonologyForm({ row, onSave }) {
  const [opts, current] = await Promise.all([
    loadSelectsData(),
    api.getPhonology(row.id),
  ]);

  const wrap = el('div', { class: 'phonology-grid' });

  // Two-column grid of fields
  PHONOLOGY_FIELDS.forEach(f => {
    const fieldWrap = el('label', { class: 'phono-field' });
    fieldWrap.appendChild(el('span', { class: 'phono-label' }, f.label));

    if (f.type === 'select') {
      const select = el('select', {
        class: 'phono-select',
        onchange: (ev) => {
          const v = ev.target.value;
          current[f.key] = v;
          onSave({ [f.key]: v });
        }
      });
      select.appendChild(el('option', { value: '' }, '— —'));
      const items = opts[f.key] || [];
      items.forEach(o => {
        const isCurrent = String(o.value) === String(current[f.key] || '');
        select.appendChild(el('option', { value: o.value, selected: isCurrent }, o.NL || o.EN || o.value));
      });
      fieldWrap.appendChild(select);
    } else {
      const input = el('input', {
        type: 'text',
        class: 'phono-input',
        value: current[f.key] || '',
      });
      let saveTimer;
      const commit = () => {
        clearTimeout(saveTimer);
        const v = input.value.trim();
        current[f.key] = v;
        onSave({ [f.key]: v });
      };
      input.addEventListener('input', () => {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(commit, 500);
      });
      input.addEventListener('blur', commit);
      fieldWrap.appendChild(input);
    }

    wrap.appendChild(fieldWrap);
  });

  // Fase toggles span the full row at bottom
  const faseRow = el('div', { class: 'phono-fase-row' });
  FASE_FIELDS.forEach(f => {
    const checked = String(current[f.key] || '') === '1';
    const toggle = el('label', { class: 'phono-fase' });
    const input = el('input', {
      type: 'checkbox',
      checked,
      onchange: (ev) => {
        const v = ev.target.checked ? '1' : '0';
        current[f.key] = v;
        onSave({ [f.key]: v });
      }
    });
    toggle.append(
      input,
      el('span', { class: 'fase-label' }, f.label),
    );
    faseRow.appendChild(toggle);
  });

  return el('div', { class: 'phonology-body' }, wrap, faseRow);
}
