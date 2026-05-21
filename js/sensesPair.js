import { el, toast } from './util.js';
import { t } from './i18n.js';

export function renderSensesPair({ nl, en, onChange }) {
  // Pair the two arrays by index. Length = max of the two; missing slots become "".
  let pairs = pairUp(nl, en);

  const wrap   = el('div', { class: 'senses-pair-block' });
  const list   = el('div', { class: 'senses-pair-rows' });
  const warning = el('div', { class: 'senses-warning hidden' },
    el('i', { class: 'fas fa-triangle-exclamation' }),
    ' ' + t('senses.both_required')
  );
  const addBtn = el('button', {
    type: 'button',
    class: 'senses-add',
    onclick: () => { pairs.push({ nl: '', en: '' }); render(); commit({ skipWarning: true }); focusLast(); },
  }, t('senses.add_pair'));

  function pairUp(nl, en) {
    const max = Math.max(nl?.length || 0, en?.length || 0);
    const out = [];
    for (let i = 0; i < max; i++) out.push({ nl: nl?.[i] || '', en: en?.[i] || '' });
    return out;
  }

  function readArrays() {
    return {
      nl: pairs.map(p => p.nl.trim()),
      en: pairs.map(p => p.en.trim()),
    };
  }

  function isIncomplete() {
    return pairs.some(p => {
      const haveNl = !!p.nl.trim();
      const haveEn = !!p.en.trim();
      return haveNl !== haveEn;
    });
  }

  function commit({ skipWarning } = {}) {
    const arrays = readArrays();
    const incomplete = isIncomplete();
    warning.classList.toggle('hidden', !incomplete);
    onChange?.(arrays, { incomplete });
    if (incomplete && !skipWarning) {
      toast(t('senses.incomplete'), 'error');
    }
  }

  function render() {
    while (list.firstChild) list.removeChild(list.firstChild);
    if (!pairs.length) pairs.push({ nl: '', en: '' });
    pairs.forEach((p, idx) => {
      const nlInput = el('input', {
        type: 'text', value: p.nl, placeholder: 'Sense NL…',
        class: 'sense-input' + (p.nl && !p.en ? ' missing-pair' : ''),
      });
      const enInput = el('input', {
        type: 'text', value: p.en, placeholder: 'Sense EN…',
        class: 'sense-input' + (p.en && !p.nl ? ' missing-pair' : ''),
      });
      const handleInput = (which, input) => {
        p[which] = input.value;
        // toggle missing class without full rerender (preserves focus)
        nlInput.classList.toggle('missing-pair', p.nl.trim() && !p.en.trim());
        enInput.classList.toggle('missing-pair', p.en.trim() && !p.nl.trim());
        warning.classList.toggle('hidden', !isIncomplete());
      };
      nlInput.addEventListener('input', () => handleInput('nl', nlInput));
      enInput.addEventListener('input', () => handleInput('en', enInput));
      nlInput.addEventListener('blur', () => commit());
      enInput.addEventListener('blur', () => commit());
      const removeBtn = el('button', {
        type: 'button', class: 'btn-icon', title: t('btn.delete'),
        onclick: () => { pairs.splice(idx, 1); render(); commit({ skipWarning: true }); },
      }, el('i', { class: 'fas fa-times' }));
      list.appendChild(el('div', { class: 'senses-pair-row' }, nlInput, enInput, removeBtn));
    });
    warning.classList.toggle('hidden', !isIncomplete());
  }

  function focusLast() {
    const inputs = list.querySelectorAll('.sense-input');
    if (inputs.length) inputs[inputs.length - 2 < 0 ? 0 : inputs.length - 2].focus();
  }

  wrap.append(list, addBtn, warning);
  render();
  return wrap;
}
