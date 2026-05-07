export function debounce(fn, ms = 400) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

export function el(tag, attrs = {}, ...children) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === false || v == null) continue;
    if (k === 'class') e.className = v;
    else if (k === 'dataset') Object.assign(e.dataset, v);
    else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k in e && typeof v !== 'string') e[k] = v;
    else e.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (c == null || c === false) continue;
    e.appendChild(c.nodeType ? c : document.createTextNode(c));
  }
  return e;
}

export function escapeHtml(s) {
  return String(s ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#39;');
}

export function toast(msg, kind = 'info') {
  const stack = document.getElementById('toastStack');
  const t = el('div', { class: `toast ${kind}` }, msg);
  stack.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateY(8px)'; }, 2400);
  setTimeout(() => t.remove(), 2700);
}

export function fmtCount(n) {
  return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// Best-effort extraction of userIds (or username strings) from the messy
// legacy `wie` formats: `["1"]`, `[1]`, `['1']`, `["[\"1\"]"]`, `["annika"]`...
export function extractUserTokens(wieArray) {
  const out = [];
  if (!Array.isArray(wieArray)) return out;
  for (const raw of wieArray) {
    if (raw == null) continue;
    let s = String(raw).trim();
    // unwrap one level of nested JSON-encoded array (e.g. `["1"]` stored as a single element)
    if (/^\[.*\]$/.test(s)) {
      try {
        const inner = JSON.parse(s);
        if (Array.isArray(inner)) { inner.forEach(v => out.push(cleanToken(v))); continue; }
      } catch {}
    }
    out.push(cleanToken(s));
  }
  return out.filter(Boolean);
}

function cleanToken(v) {
  return String(v ?? '').replace(/^['"\[\]\s]+|['"\[\]\s]+$/g, '').trim();
}
