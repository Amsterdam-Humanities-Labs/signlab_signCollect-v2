import { el } from './util.js';
import { t } from './i18n.js';

export function renderSenses(values, { onChange, placeholder = 'Sense…' } = {}) {
  const wrap = el('div', { class: 'senses-block' });
  const list = el('div', { class: 'senses-rows' });
  const addBtn = el('button', {
    type: 'button',
    class: 'senses-add',
    onclick: () => { addRow(''); commit(); }
  }, '+ ' + t('btn.add').toLowerCase());

  function commit() {
    const arr = Array.from(list.querySelectorAll('input')).map(i => i.value.trim()).filter(Boolean);
    onChange?.(arr);
  }

  function addRow(val) {
    const input = el('input', {
      type: 'text', value: val, placeholder,
      onblur: commit,
    });
    input.addEventListener('keydown', ev => {
      if (ev.key === 'Enter') { ev.preventDefault(); addRow(''); commit(); list.lastElementChild.querySelector('input').focus(); }
    });
    const remove = el('button', {
      type: 'button', class: 'btn-icon',
      title: t('btn.delete'),
      onclick: () => { row.remove(); commit(); }
    }, el('i', { class: 'fas fa-times' }));
    const row = el('div', { class: 'senses-row' }, input, remove);
    list.appendChild(row);
  }

  (values && values.length ? values : ['']).forEach(v => addRow(v));
  wrap.append(list, addBtn);
  return wrap;
}
