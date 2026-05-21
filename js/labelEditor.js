import { el } from './util.js';
import { t } from './i18n.js';

export function renderLabelEditor({
  value,
  options,
  onChange,
  placeholder = '+ label…',
  allowFreeText = true,
}) {
  const opts = options.map(o => ({ ...o, value: o.value ?? o.label }));
  let values = [...(value || [])].map(String);

  const wrap = el('div', { class: 'label-editor' });
  const pillRow = el('div', { class: 'label-pills' });
  const inputWrap = el('div', { class: 'label-input-wrap' });
  const input = el('input', {
    type: 'text',
    class: 'label-input',
    placeholder,
    autocomplete: 'off',
    spellcheck: false,
  });
  const suggestions = el('div', { class: 'label-suggestions' });

  function findOpt(val)   { return opts.find(o => String(o.value) === String(val)); }
  function labelFor(val)  { return findOpt(val)?.label ?? val; }

  function commit() { onChange?.([...values]); }

  function clearChildren(node) { while (node.firstChild) node.removeChild(node.firstChild); }

  function renderPills() {
    clearChildren(pillRow);
    values.forEach(val => {
      const meta = findOpt(val);
      const pill = el('span', {
        class: 'label-pill',
        style: meta?.color ? `--pill-color:${meta.color}` : '',
      },
        labelFor(val),
        el('button', {
          type: 'button',
          class: 'pill-x',
          title: t('btn.delete'),
          onclick: () => {
            values = values.filter(x => String(x) !== String(val));
            renderPills();
            commit();
          }
        }, '×')
      );
      pillRow.appendChild(pill);
    });
  }

  function renderSuggestions(q) {
    clearChildren(suggestions);
    const lower = q.toLowerCase();
    const candidates = opts
      .filter(o => !values.some(v => String(v) === String(o.value)))
      .filter(o => !lower
        || (o.label || '').toLowerCase().includes(lower)
        || String(o.value).toLowerCase().includes(lower))
      .slice(0, 12);

    if (allowFreeText && !candidates.length && q) {
      suggestions.appendChild(el('div', {
        class: 'suggestion create',
        onmousedown: (ev) => { ev.preventDefault(); addByText(q); }
      },
        el('i', { class: 'fas fa-plus' }),
        ` "${q}" ${t('btn.add').toLowerCase()}`
      ));
    }
    candidates.forEach(o => {
      suggestions.appendChild(el('div', {
        class: 'suggestion',
        onmousedown: (ev) => { ev.preventDefault(); addByValue(o.value); }
      },
        o.color ? el('span', { class: 'swatch', style: `background:${o.color}` }) : null,
        o.label
      ));
    });
    suggestions.classList.toggle('open', !!suggestions.children.length);
  }

  function addByValue(val) {
    val = String(val);
    if (!val) return;
    if (!values.some(v => String(v) === val)) {
      values.push(val);
      renderPills();
      commit();
    }
    input.value = '';
    renderSuggestions('');
    input.focus();
  }

  function addByText(text) {
    text = text.trim();
    if (!text) return;
    const exact = opts.find(o => (o.label || '').toLowerCase() === text.toLowerCase());
    if (exact) addByValue(exact.value);
    else if (allowFreeText) addByValue(text);
    else {
      input.value = '';
      input.focus();
    }
  }

  input.addEventListener('focus', () => renderSuggestions(input.value));
  input.addEventListener('input', () => renderSuggestions(input.value));
  input.addEventListener('keydown', ev => {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      const first = suggestions.querySelector('.suggestion');
      if (first) first.dispatchEvent(new MouseEvent('mousedown'));
      else addByText(input.value);
    } else if (ev.key === 'Backspace' && !input.value && values.length) {
      values.pop(); renderPills(); commit();
    } else if (ev.key === 'Escape') {
      suggestions.classList.remove('open');
      input.blur();
    }
  });
  input.addEventListener('blur', () => {
    setTimeout(() => suggestions.classList.remove('open'), 100);
  });

  inputWrap.append(input, suggestions);
  wrap.append(pillRow, inputWrap);
  renderPills();
  return wrap;
}
