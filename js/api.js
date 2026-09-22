const BASE = 'php_api';

// Set by main.js after current_user.php resolves so every request carries
// the active dataset. Falls back to 'ngt' until then.
let _dataset = 'ngt';
export function setActiveDataset(code) { _dataset = String(code || 'ngt'); }
export function getActiveDataset() { return _dataset; }

async function call(path, opts = {}) {
  const method = (opts.method || 'GET').toUpperCase();
  let url = `${BASE}/${path}`;
  if (method === 'GET') {
    url += (url.includes('?') ? '&' : '?') + 'dataset=' + encodeURIComponent(_dataset);
  }
  const res = await fetch(url, { credentials: 'same-origin', ...opts });
  if (res.status === 401) {
    const here = encodeURIComponent(location.pathname);
    location.href = `/login.html?redirect=${here}`;
    throw new Error('unauthorized');
  }
  let data = null;
  try { data = await res.json(); } catch { /* non-json */ }
  if (!res.ok) {
    const msg = (data && data.error) || res.statusText || 'request failed';
    throw new Error(msg);
  }
  return data;
}

const post = (path, body) => call(path, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ ...(body || {}), dataset: _dataset }),
});

export const api = {
  currentUser:    ()      => call('current_user.php'),
  filterOptions:  ()      => call('filters_options.php'),
  list:           (q)     => post('glosses_list.php', q),
  save:           (id, fields) => post('glosses_save.php', { id, fields }),
  create:         (fields)     => post('glosses_create.php', fields),
  remove:         (id)         => post('glosses_delete.php', { id }),
  uploadVideo: (id, blob) => {
    const fd = new FormData();
    fd.append('id', id);
    fd.append('dataset', _dataset);
    fd.append('file', blob, 'recording.webm');
    return call('upload_video.php', { method: 'POST', body: fd });
  },
  deleteVideo:    (id, filename) => post('delete_video.php', { id, filename }),
  deleteStudioVideo: (id) => post('studio_video_delete.php', { id }),
  undeleteStudioVideo: (id) => post('studio_video_undelete.php', { id }),
  getPhonology:   (id) => call(`phonology_get.php?id=${encodeURIComponent(id)}`),
  createLabel:    (label, color) => post('labels_create.php', { label, color }),
  // Glos Wizard: search both collections at once, then ask what a new
  // gloss should be called before creating it.
  wizardSearch:   (q) => call(`wizard_search.php?q=${encodeURIComponent(q)}`),
  wizardSuggest:  (glos) => call(`wizard_suggest.php?glos=${encodeURIComponent(glos)}`),
  notesList:      (id) => call(`notes_list.php?id=${encodeURIComponent(id)}`),
  notesAdd:       (id, note_text) => post('notes_add.php', { id, note_text }),
  logbookGet:     (id) => call(`logbook_get.php?id=${encodeURIComponent(id)}`),
  broadcastToSignbank: (id) => post('../signbank_sync/broadcast_gloss.php', { id }),
  deleteFromSignbank:  (id) => post('../signbank_sync/delete_gloss.php', { id }),
  fetchSignbankGloss:  (id) => call(`../signbank_sync/fetch_gloss.php?id=${encodeURIComponent(id)}`),
  forcePushToSignbank: (id, onlyFields) => post('../signbank_sync/force_push.php',
    onlyFields ? { id, only_fields: onlyFields } : { id }),
  forcePullFromSignbank: (id) => post('../signbank_sync/force_pull.php', { id }),
};
