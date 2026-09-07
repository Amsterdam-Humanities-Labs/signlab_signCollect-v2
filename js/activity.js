// User activity page (activity.html).
//
// Renders php_api/activity_log.php: a per-user aggregate and the raw event
// log, both scoped to a date window, with the log paged.
//
// Standalone module rather than part of main.js - this is a sibling admin page
// like users.html, not a view of the gloss table - so it talks to its endpoint
// directly instead of going through js/api.js, whose every call carries the
// active dataset. Activity is not dataset-scoped.

import { t, setLanguage, applyI18nToDom } from './i18n.js';

const $ = (sel) => document.querySelector(sel);

const state = {
  userId:   '',
  from:     '',
  to:       '',
  page:     1,
  pageSize: 50,
};

/* ---------- helpers ---------- */

function esc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// MySQL hands back 'YYYY-MM-DD HH:MM:SS'; drop the seconds, keep it sortable.
function fmtWhen(v) {
  if (!v) return null;
  return String(v).slice(0, 16).replace('T', ' ');
}

function whenCell(v) {
  const s = fmtWhen(v);
  return s ? `<span class="when">${esc(s)}</span>`
           : `<span class="never">${esc(t('activity.never'))}</span>`;
}

async function getJson(url) {
  const res = await fetch(url, { credentials: 'same-origin' });
  let data = null;
  try { data = await res.json(); } catch { /* not json */ }
  if (res.status === 401) {
    location.href = `/login.html?redirect=${encodeURIComponent(location.pathname)}`;
    throw new Error('unauthorized');
  }
  if (!res.ok) {
    const err = new Error((data && (data.message || data.error)) || res.statusText);
    err.status = res.status;
    throw err;
  }
  return data;
}

/* ---------- rendering ---------- */

function renderCards(data) {
  const activeUsers = data.users.filter(u => u.visits > 0).length;
  const windowVisits = data.users.reduce((n, u) => n + u.visits, 0);
  const cards = [
    [windowVisits,      t('activity.card.visits')],
    [activeUsers,       t('activity.card.active_users')],
    [data.topPages.length ? data.topPages.length : 0, t('activity.card.pages')],
    [data.total,        t('activity.card.shown')],
  ];
  $('#summaryCards').innerHTML = cards.map(([v, label]) =>
    `<div class="card"><div class="value">${esc(v)}</div><div class="label">${esc(label)}</div></div>`
  ).join('');
}

function renderUsers(data) {
  const rows = data.users.map(u => {
    const role = u.role === 'admin'
      ? `<span class="badge badge-admin"><i class="fas fa-shield-alt"></i> ${esc(t('activity.role.admin'))}</span>`
      : `<span class="badge badge-user"><i class="fas fa-user"></i> ${esc(t('activity.role.user'))}</span>`;
    const blocked = u.blocked
      ? `<span class="badge badge-blocked">${esc(t('activity.blocked'))}</span>` : '';
    const lastPage = u.last_page
      ? `<span class="page-name">${esc(u.last_page)}</span>`
      : `<span class="never">${esc(t('activity.never'))}</span>`;
    const selected = String(u.userId) === String(state.userId) ? ' is-selected' : '';
    return `<tr class="clickable${selected}" data-user="${esc(u.userId)}" title="${esc(t('activity.users.filter_hint'))}">
        <td><strong>${esc(u.user)}</strong> <span class="muted">#${esc(u.userId)}</span></td>
        <td>${role}${blocked}</td>
        <td>${whenCell(u.last_seen)}</td>
        <td>${lastPage}</td>
        <td class="num">${esc(u.visits)}</td>
      </tr>`;
  });
  $('#usersTable').innerHTML = rows.join('');
}

function renderTopPages(data) {
  if (!data.topPages.length) {
    $('#pagesTable').innerHTML =
      `<tr><td colspan="3" class="muted">${esc(t('activity.events.empty'))}</td></tr>`;
    return;
  }
  $('#pagesTable').innerHTML = data.topPages.map(p => `<tr>
      <td><span class="page-name">${esc(p.page || '—')}</span></td>
      <td>${whenCell(p.last_visit)}</td>
      <td class="num">${esc(p.visits)}</td>
    </tr>`).join('');
}

function renderEvents(data) {
  const empty = data.rows.length === 0;
  $('#emptyState').hidden = !empty;
  $('#eventsTable').innerHTML = data.rows.map(r => {
    const who = r.username
      ? `<strong>${esc(r.username)}</strong> <span class="muted">#${esc(r.userId)}</span>`
      // The account is gone but its log rows are not.
      : `<span class="never">${esc(t('activity.deleted_user'))} #${esc(r.userId)}</span>`;
    return `<tr>
        <td>${whenCell(r.visited_at)}</td>
        <td>${who}</td>
        <td><span class="page-name">${esc(r.page || '—')}</span></td>
      </tr>`;
  }).join('');

  const first = data.total === 0 ? 0 : (data.page - 1) * data.pageSize + 1;
  const last  = Math.min(data.page * data.pageSize, data.total);
  $('#resultMeta').textContent = t('activity.events.meta', {
    first, last, total: data.total, from: data.window.from, to: data.window.to,
  });
}

function renderPager(data) {
  const pager = $('#pager');
  if (data.pages <= 1) { pager.innerHTML = ''; return; }
  pager.innerHTML =
    `<button type="button" class="btn" id="pagePrev"${data.page <= 1 ? ' disabled' : ''}>
       <i class="fas fa-chevron-left"></i> ${esc(t('activity.pager.prev'))}</button>
     <span class="page-of">${esc(t('activity.pager.page_of', { page: data.page, pages: data.pages }))}</span>
     <button type="button" class="btn" id="pageNext"${data.page >= data.pages ? ' disabled' : ''}>
       ${esc(t('activity.pager.next'))} <i class="fas fa-chevron-right"></i></button>`;
  const prev = $('#pagePrev'), next = $('#pageNext');
  if (prev) prev.addEventListener('click', () => { state.page = Math.max(1, state.page - 1); load(); });
  if (next) next.addEventListener('click', () => { state.page = data.page + 1; load(); });
}

// The picker is rebuilt from every load so a newly created account shows up,
// but the current selection has to survive that.
function syncUserOptions(data) {
  const sel = $('#filterUser');
  const keep = String(state.userId || '');
  const all = sel.querySelector('option[value=""]');
  sel.innerHTML = '';
  sel.appendChild(all);
  data.users.forEach(u => {
    const opt = document.createElement('option');
    opt.value = String(u.userId);
    opt.textContent = `${u.user} (#${u.userId})`;
    sel.appendChild(opt);
  });
  sel.value = keep;
}

/* ---------- data ---------- */

async function load() {
  const params = new URLSearchParams({
    page: String(state.page),
    pageSize: String(state.pageSize),
  });
  if (state.userId) params.set('userId', String(state.userId));
  if (state.from)   params.set('from', state.from);
  if (state.to)     params.set('to', state.to);

  let data;
  try {
    data = await getJson(`php_api/activity_log.php?${params}`);
  } catch (err) {
    if (err.status === 403) { showDenied(); return; }
    $('#resultMeta').textContent = t('activity.error', { message: err.message });
    return;
  }

  // The endpoint clamps page and fills in the default window; mirror whatever
  // it decided so the controls never disagree with what is on screen.
  state.page     = data.page;
  state.pageSize = data.pageSize;
  state.from     = data.window.from;
  state.to       = data.window.to;
  $('#filterFrom').value = data.window.from;
  $('#filterTo').value   = data.window.to;
  $('#filterPageSize').value = String(data.pageSize);

  syncUserOptions(data);
  renderCards(data);
  renderUsers(data);
  renderTopPages(data);
  renderEvents(data);
  renderPager(data);
}

function showDenied() {
  $('#activityBody').hidden = true;
  $('#deniedBox').hidden = false;
}

/* ---------- wiring ---------- */

function bind() {
  $('#filterForm').addEventListener('submit', (ev) => {
    ev.preventDefault();
    state.userId   = $('#filterUser').value;
    state.from     = $('#filterFrom').value;
    state.to       = $('#filterTo').value;
    state.pageSize = parseInt($('#filterPageSize').value, 10) || 50;
    state.page     = 1;
    load();
  });

  $('#resetBtn').addEventListener('click', () => {
    state.userId = ''; state.from = ''; state.to = ''; state.page = 1; state.pageSize = 50;
    $('#filterUser').value = '';
    $('#filterFrom').value = '';
    $('#filterTo').value = '';
    load();
  });

  // Clicking a row in the per-user table filters the log to that user, and
  // clicking the selected one again clears the filter.
  $('#usersTable').addEventListener('click', (ev) => {
    const tr = ev.target.closest('tr[data-user]');
    if (!tr) return;
    const id = tr.dataset.user;
    state.userId = String(state.userId) === String(id) ? '' : id;
    state.page = 1;
    load();
  });
}

async function boot() {
  let me = null;
  try {
    me = await getJson('php_api/current_user.php');
  } catch {
    // 401 already redirected; anything else falls through to the endpoint,
    // which is the authority on access anyway.
  }
  if (me && me.language) setLanguage(me.language);
  applyI18nToDom();

  if (me && (me.role || '').toLowerCase() !== 'admin') { showDenied(); return; }

  $('#activityBody').hidden = false;
  bind();
  load();
}

boot();
