// Lightweight i18n for menu_beta.
//
// Usage:
//   import { t, setLanguage, getLanguage } from './i18n.js';
//   setLanguage(state.user.language);        // 'nl' (default) or 'en'
//   el('button', {}, t('btn.delete'));       // → "Verwijderen" or "Delete"
//   t('confirm.delete_gloss', { name: 'X' }) // → "Glos \"X\" verbergen?"
//
// HTML side: any element with data-i18n="some.key" gets its textContent
// replaced. Elements with data-i18n-attr="placeholder:key,title:key" get
// the named attributes replaced. Call applyI18nToDom() after setLanguage().

let _lang = 'nl';

export function setLanguage(code) {
  _lang = (code === 'en' || code === 'eng' || code === 'english') ? 'en' : 'nl';
  document.documentElement.lang = _lang;
}
export function getLanguage() { return _lang; }

export function t(key, params) {
  const entry = STRINGS[key];
  let s = entry ? (entry[_lang] || entry.nl || key) : key;
  if (params) {
    for (const k in params) s = s.replaceAll(`{${k}}`, String(params[k]));
  }
  return s;
}

/**
 * Replace textContent / attributes for every [data-i18n] / [data-i18n-attr]
 * element under `root` (default: document). Safe to call repeatedly.
 */
export function applyI18nToDom(root = document) {
  root.querySelectorAll('[data-i18n]').forEach(el => {
    el.textContent = t(el.getAttribute('data-i18n'));
  });
  root.querySelectorAll('[data-i18n-attr]').forEach(el => {
    const pairs = el.getAttribute('data-i18n-attr').split(',');
    pairs.forEach(pair => {
      const [attr, key] = pair.split(':').map(s => s.trim());
      if (attr && key) el.setAttribute(attr, t(key));
    });
  });
}

// ─────────────────────────────────────────────────────────────────────────
// Translation dictionary. Keys grouped by area. nl values are the source
// of truth (they're what the codebase has always shipped); en values are
// translations.
// ─────────────────────────────────────────────────────────────────────────
const STRINGS = {
  // ── Hamburger / nav drawer ────────────────────────────────────────────
  'menu.title':            { nl: 'Navigatie',               en: 'Navigation' },
  'menu.section.main':     { nl: 'Hoofdmenu',               en: 'Main menu' },
  'menu.section.manage':   { nl: 'Beheer',                  en: 'Manage' },
  'menu.section.admin':    { nl: 'Admin',                   en: 'Admin' },
  'menu.section.stats':    { nl: 'Statistieken',            en: 'Statistics' },
  'menu.section.account':  { nl: 'Account',                 en: 'Account' },
  'menu.item.glos_wizard': { nl: 'Glos Wizard',             en: 'Gloss Wizard' },
  'menu.item.video_crop':  { nl: 'Video Crop fix',          en: 'Video crop fix' },
  'menu.item.studio':      { nl: 'Studio Videos',           en: 'Studio videos' },
  'menu.item.mocap':       { nl: 'Motion Capture',          en: 'Motion capture' },
  'menu.item.zinnen':      { nl: 'Zinnen Interface',        en: 'Sentences interface' },
  'menu.item.nmm':         { nl: 'NMM Site',                en: 'NMM site' },
  'menu.item.hh':          { nl: 'Health Holland Interface',en: 'Health Holland interface' },
  'menu.item.downloader':  { nl: 'Video Downloader',        en: 'Video downloader' },
  'menu.item.labels':      { nl: 'Labels toevoegen',        en: 'Add labels' },
  'menu.item.batch':       { nl: 'Batch toevoegen',         en: 'Batch add' },
  'menu.item.themas':      { nl: 'Themas Aanpassen',        en: 'Manage themes' },
  'menu.item.users':       { nl: 'Gebruikers Beheren',      en: 'Manage users' },
  'menu.item.user_stats':  { nl: 'Gebruikersactiviteit',    en: 'User activity' },
  'menu.logout':           { nl: 'Uitloggen',               en: 'Log out' },

  // ── Filter bar / search ───────────────────────────────────────────────
  'filter.search.placeholder': { nl: 'Zoek glos…',          en: 'Search gloss…' },
  'filter.thema':              { nl: 'Thema',               en: 'Theme' },
  'filter.thema.all':          { nl: 'Alle themas',         en: 'All themes' },
  'filter.label':              { nl: 'Label',               en: 'Label' },
  'filter.label.add':          { nl: '+ label…',            en: '+ label…' },
  'label.create_new':          { nl: 'nieuw label aanmaken', en: 'create new label' },
  'label.create_placeholder':  { nl: 'Nieuw label…',         en: 'New label…' },
  'toast.label_created':       { nl: 'Label aangemaakt',     en: 'Label created' },
  'toast.label_exists':        { nl: 'Label bestond al',     en: 'Label already exists' },
  'toast.label_create_failed': { nl: 'Label aanmaken mislukt: {msg}',
                                 en: 'Create label failed: {msg}' },
  'filter.owner':              { nl: 'Eigenaar',            en: 'Owner' },
  'filter.owner.everyone':     { nl: 'Iedereen',            en: 'Everyone' },
  'filter.owner.add':          { nl: '+ gebruiker…',        en: '+ user…' },
  'filter.status':             { nl: 'Status',              en: 'Status' },
  'filter.status.hidden':           { nl: 'Verborgen',         en: 'Hidden' },
  'filter.status.no_label':         { nl: 'Geen labels',        en: 'No labels' },
  'filter.status.no_thema':         { nl: 'Geen thema',         en: 'No theme' },
  'filter.status.no_zelfopname':    { nl: 'Geen zelfopname',    en: 'No selfie video' },
  'filter.status.has_zelfopname':   { nl: 'Met zelfopname',     en: 'Has selfie video' },
  'filter.status.no_studio_video':  { nl: 'Geen studio-video',  en: 'No studio video' },
  'filter.status.has_studio_video': { nl: 'Met studio-video',   en: 'Has studio video' },
  'filter.status.extern_duplicate': { nl: 'Externe duplicaten', en: 'External duplicates' },
  'filter.sort':                    { nl: 'Sorteren',           en: 'Sort' },
  'filter.sort.glos_az':            { nl: 'Glos A→Z',           en: 'Gloss A→Z' },
  'filter.sort.glos_za':            { nl: 'Glos Z→A',           en: 'Gloss Z→A' },
  'filter.sort.newest':             { nl: 'Nieuwste eerst',     en: 'Newest first' },
  'filter.sort.oldest':             { nl: 'Oudste eerst',       en: 'Oldest first' },
  'filter.sort.latest_capture':     { nl: 'Recentste opname',   en: 'Latest capture' },
  'filter.sort.oldest_capture':     { nl: 'Oudste opname',      en: 'Oldest capture' },
  'filter.reset':                   { nl: 'Reset filters',      en: 'Reset filters' },

  // ── Row labels ────────────────────────────────────────────────────────
  'row.gloss':               { nl: 'Glos',                en: 'Gloss' },
  'row.gloss_engels':        { nl: 'Engelse glos',        en: 'English gloss' },
  'row.thema':               { nl: 'Thema',               en: 'Theme' },
  'row.labels':              { nl: 'Labels',              en: 'Labels' },
  'row.senses':              { nl: 'Betekenissen',        en: 'Senses' },
  'row.wie':                 { nl: 'Wie',                 en: 'Owners' },
  'row.gecontroleerd':       { nl: 'Gecontroleerd',       en: 'Checked' },
  'row.gec.done':            { nl: 'klaar',               en: 'done' },
  'row.gec.busy':            { nl: 'niet klaar',          en: 'not done' },
  'row.gec.toggle_hint':     { nl: 'Klik om te wisselen tussen klaar / niet klaar',
                               en: 'Click to toggle between done / not done' },

  // ── Row actions menu ──────────────────────────────────────────────────
  'rowmenu.record':                { nl: 'Zelfopname maken',       en: 'Record selfie video' },
  'rowmenu.phonology':             { nl: 'Fonologie',              en: 'Phonology' },
  'rowmenu.hide':                  { nl: 'Verbergen / tonen',      en: 'Hide / show' },
  'rowmenu.delete':                { nl: 'Verwijderen',            en: 'Delete' },
  'rowmenu.broadcast':             { nl: 'Push naar Signbank',     en: 'Push to Signbank' },
  'rowmenu.disconnect':            { nl: 'Loskoppelen van Signbank',en: 'Disconnect from Signbank' },
  'rowmenu.compare':               { nl: 'Vergelijken met Signbank',en: 'Compare with Signbank' },
  'rowmenu.delete_zelfopname_all': { nl: 'Alle zelfopnames verwijderen', en: 'Delete all selfie videos' },
  'rowmenu.notes':                 { nl: 'Notities',                en: 'Notes' },
  'rowmenu.logbook':               { nl: 'Logboek',                 en: 'Logbook' },

  // ── Notes modal ───────────────────────────────────────────────────────
  'notes.title':                 { nl: 'Notities',                  en: 'Notes' },
  'notes.placeholder':           { nl: 'Schrijf een notitie…',      en: 'Write a note…' },
  'notes.submit':                { nl: 'Plaatsen',                  en: 'Post' },
  'notes.empty':                 { nl: 'Nog geen notities. Begin het gesprek.',
                                   en: 'No notes yet. Start the conversation.' },
  'notes.load_failed':           { nl: 'Notities laden mislukt: {msg}', en: 'Failed to load notes: {msg}' },
  'notes.post_failed':           { nl: 'Plaatsen mislukt: {msg}',       en: 'Posting failed: {msg}' },

  // ── Logbook modal ─────────────────────────────────────────────────────
  'logbook.title':               { nl: 'Logboek',                       en: 'Logbook' },
  'logbook.empty':               { nl: 'Nog geen logboek-vermeldingen.',en: 'No logbook entries yet.' },
  'logbook.load_failed':         { nl: 'Logboek laden mislukt: {msg}',  en: 'Failed to load logbook: {msg}' },

  // ── Toasts / status banners ───────────────────────────────────────────
  'toast.saved':                 { nl: 'Opgeslagen',             en: 'Saved' },
  'toast.save_failed':           { nl: 'Opslaan mislukt',        en: 'Save failed' },
  'toast.gloss_hidden':          { nl: 'Glos verborgen',         en: 'Gloss hidden' },
  'toast.video_uploaded':        { nl: 'Zelfopname geüpload',    en: 'Selfie video uploaded' },
  'toast.video_deleted':         { nl: 'Zelfopname verwijderd',  en: 'Selfie video deleted' },
  'toast.no_changes':            { nl: 'Geen wijzigingen',       en: 'No changes' },
  'toast.connected':             { nl: 'Verbonden met Signbank', en: 'Connected to Signbank' },
  'toast.disconnected':          { nl: 'Losgekoppeld van Signbank', en: 'Disconnected from Signbank' },
  'toast.error_loading':         { nl: 'Fout bij laden',         en: 'Error loading' },
  'toast.wait_compare_loaded':   { nl: 'Wacht tot de vergelijking geladen is', en: 'Wait for the comparison to finish loading' },
  'toast.no_differences':        { nl: 'Geen verschillen om te pushen', en: 'No differences to push' },
  'toast.gloss_created':         { nl: 'Glos aangemaakt',                en: 'Gloss created' },
  'toast.gloss_create_failed':   { nl: 'Aanmaken mislukt: {msg}',        en: 'Create failed: {msg}' },

  // ── Add-gloss modal ───────────────────────────────────────────────────
  'add.title':                   { nl: 'Nieuwe glos',                    en: 'New gloss' },
  'add.submit':                  { nl: 'Aanmaken',                       en: 'Create' },
  'add.field.senses_nl':         { nl: 'Senses NL',                      en: 'Senses NL' },
  'add.field.senses_en':         { nl: 'Senses EN',                      en: 'Senses EN' },
  'add.error.missing_fields':    { nl: 'Vul eerst alle velden in: {fields}',
                                   en: 'Fill in all fields first: {fields}' },

  // ── Confirm prompts ───────────────────────────────────────────────────
  'confirm.hide_gloss':          { nl: 'Glos "{name}" verbergen?',           en: 'Hide gloss "{name}"?' },
  'confirm.delete_zelfopname':   { nl: 'Deze zelfopname verwijderen?',        en: 'Delete this selfie video?' },
  'confirm.delete_all_zelfopname':{ nl: 'Alle {n} zelfopnames verwijderen?', en: 'Delete all {n} selfie videos?' },
  'confirm.broadcast':           { nl: 'Glos "{name}" naar Signbank pushen?',en: 'Push gloss "{name}" to Signbank?' },
  'confirm.disconnect':          { nl: 'Loskoppelen van Signbank glos #{id}?', en: 'Disconnect from Signbank gloss #{id}?' },
  'confirm.force_push':          { nl: 'Alles naar Signbank pushen?',         en: 'Push everything to Signbank?' },
  'confirm.force_pull':          { nl: 'Alles van Signbank ophalen?',         en: 'Pull everything from Signbank?' },
  'confirm.push_diff':           { nl: 'Push {n} verschillen naar Signbank? Velden: {fields}',
                                   en: 'Push {n} differences to Signbank? Fields: {fields}' },
  'confirm.yes':                 { nl: 'Ja, doorgaan',            en: 'Yes, continue' },
  'confirm.no':                  { nl: 'Annuleren',               en: 'Cancel' },

  // ── Compare / broadcast modal ─────────────────────────────────────────
  'sb.compare.title':            { nl: 'Vergelijken met Signbank — {name} (#{id})',
                                   en: 'Compare with Signbank — {name} (#{id})' },
  'sb.fetching':                 { nl: 'Bezig met ophalen van Signbank…',    en: 'Fetching from Signbank…' },
  'sb.status.busy':              { nl: 'Ophalen…',                            en: 'Loading…' },
  'sb.btn.open_in_signbank':     { nl: 'Open in Signbank',                    en: 'Open in Signbank' },
  'sb.btn.force_push':           { nl: 'Push alles → Signbank',               en: 'Push all → Signbank' },
  'sb.btn.force_pull':           { nl: 'Pull alles ← Signbank',               en: 'Pull all ← Signbank' },
  'sb.btn.push_diff':            { nl: 'Push verschillen',                    en: 'Push differences' },
  'sb.diff.count':               { nl: '{n} verschillen met Signbank glossid #{id} · {ms} ms',
                                   en: '{n} differences with Signbank glossid #{id} · {ms} ms' },
  'sb.diff.field':               { nl: 'Veld',                  en: 'Field' },
  'sb.diff.local':               { nl: 'signCollect',           en: 'signCollect' },
  'sb.diff.remote':              { nl: 'Signbank',              en: 'Signbank' },
  'sb.diff.match':               { nl: 'gelijk',                en: 'match' },
  'sb.diff.mismatch':            { nl: 'verschillend',          en: 'mismatch' },
  'sb.video.no_local':           { nl: '— geen video —',        en: '— no video —' },
  'sb.video.no_remote':          { nl: '— Signbank heeft geen video —', en: '— Signbank has no video —' },
  'sb.op.busy':                  { nl: '{verb}: bezig met versturen…', en: '{verb}: sending…' },
  'sb.op.done':                  { nl: '{verb}: voltooid',              en: '{verb}: completed' },
  'sb.op.partial':               { nl: '{verb}: deels gelukt',          en: '{verb}: partially succeeded' },
  'sb.op.failed':                { nl: '{verb}: mislukt',               en: '{verb}: failed' },
  'sb.op.field_to_glossid':      { nl: '{n} velden naar Signbank glossid #{id}',
                                   en: '{n} fields to Signbank glossid #{id}' },
  'sb.op.summary':               { nl: '{ok} ok · {fail} mislukt',      en: '{ok} ok · {fail} failed' },
  'sb.section.timeline':         { nl: 'Tijdlijn',                      en: 'Timeline' },
  'sb.section.per_field':        { nl: 'Per-veld resultaat',            en: 'Per-field result' },
  'sb.section.pull_mapping':     { nl: 'Pull-mapping (Signbank → form_data)',
                                   en: 'Pull mapping (Signbank → form_data)' },
  'sb.ok_count':                 { nl: '✓ Geslaagd ({n})',              en: '✓ Succeeded ({n})' },
  'sb.fail_count':               { nl: '✗ Mislukt ({n})',               en: '✗ Failed ({n})' },
  'sb.push.verb':                { nl: 'Push',                          en: 'Push' },
  'sb.pull.verb':                { nl: 'Pull',                          en: 'Pull' },

  // ── Phonology modal ───────────────────────────────────────────────────
  'phon.title':                  { nl: 'Fonologie — {name}',            en: 'Phonology — {name}' },
  'phon.loading':                { nl: 'Laden…',                        en: 'Loading…' },
  'phon.saving':                 { nl: 'Opslaan…',                      en: 'Saving…' },
  'phon.saved':                  { nl: 'Opgeslagen',                    en: 'Saved' },
  'phon.fase1':                  { nl: 'Fonologie Fase 1 Klaar?',       en: 'Phonology Phase 1 Done?' },
  'phon.fase2':                  { nl: 'Fonologie Fase 2 Klaar?',       en: 'Phonology Phase 2 Done?' },

  // ── Record (selfie) modal ─────────────────────────────────────────────
  'record.title':                { nl: 'Zelfopname',                    en: 'Selfie recording' },
  'record.start':                { nl: 'Start',                         en: 'Start' },
  'record.stop':                 { nl: 'Stop',                          en: 'Stop' },
  'record.retry':                { nl: 'Opnieuw',                       en: 'Retry' },
  'record.upload':               { nl: 'Uploaden',                      en: 'Upload' },
  'record.cancel':                { nl: 'Annuleren',                    en: 'Cancel' },
  'record.status.ready':         { nl: 'Gereed',                        en: 'Ready' },
  'record.status.recording':     { nl: 'Opnemen…',                      en: 'Recording…' },
  'record.status.uploading':     { nl: 'Uploaden…',                     en: 'Uploading…' },
  'record.status.done':          { nl: 'Klaar',                         en: 'Done' },

  // ── Studio modal ──────────────────────────────────────────────────────
  'studio.title':                { nl: 'Studio-opnames — {name}',       en: 'Studio recordings — {name}' },
  'studio.cam.l':                { nl: 'Links',                         en: 'Left' },
  'studio.cam.m':                { nl: 'Midden',                        en: 'Center' },
  'studio.cam.r':                { nl: 'Rechts',                        en: 'Right' },
  'studio.deleted':              { nl: 'verwijderd',                    en: 'deleted' },
  'studio.delete':               { nl: 'Verwijderen',                   en: 'Delete' },
  'studio.no_videos':            { nl: 'Geen studio-opnames',           en: 'No studio recordings' },

  // ── Empty state ───────────────────────────────────────────────────────
  'empty.title':                 { nl: 'Geen resultaten',               en: 'No results' },
  'empty.hint':                  { nl: 'Pas de filters aan of zoek opnieuw.', en: 'Adjust the filters or search again.' },

  // ── Pagination / count ────────────────────────────────────────────────
  'count.rows':                  { nl: '{n} rijen',                     en: '{n} rows' },
  'count.page':                  { nl: 'Pagina {p} van {total}',        en: 'Page {p} of {total}' },
  'pager.prev':                  { nl: 'Vorige',                        en: 'Previous' },
  'pager.next':                  { nl: 'Volgende',                      en: 'Next' },

  // ── Common buttons ────────────────────────────────────────────────────
  'btn.save':                    { nl: 'Opslaan',                       en: 'Save' },
  'btn.cancel':                  { nl: 'Annuleren',                     en: 'Cancel' },
  'btn.close':                   { nl: 'Sluiten',                       en: 'Close' },
  'btn.delete':                  { nl: 'Verwijderen',                   en: 'Delete' },
  'btn.add':                     { nl: 'Toevoegen',                     en: 'Add' },
  'btn.retry':                   { nl: 'Opnieuw',                       en: 'Retry' },

  // ── Misc ──────────────────────────────────────────────────────────────
  'header.subtitle':             { nl: 'Glos-beheer',                   en: 'Gloss management' },
  'ctx.signio':                  { nl: 'Signio',                        en: 'Signio' },
  'ctx.signbank':                { nl: 'Signbank',                      en: 'Signbank' },

  // ── User dropdown / multi-select ─────────────────────────────────────────
  'multi.all':                   { nl: '— alle —',                      en: '— all —' },
  'multi.none':                  { nl: '— geen —',                      en: '— none —' },
  'multi.n_selected':            { nl: '{n} geselecteerd',              en: '{n} selected' },
  'multi.you_suffix':            { nl: '(jij)',                         en: '(you)' },

  // ── Duplicates ────────────────────────────────────────────────────────────
  'duplicate.warning':           { nl: 'Waarschuwing: duplicaat gedetecteerd voor "{glos}". {n} rijen hieronder.',
                                   en: 'Warning: duplicate detected for "{glos}". {n} rows below.' },
  'duplicate.of':                { nl: 'Duplicaat van:',                en: 'Duplicate of:' },

  // ── Pagination / count (extra) ────────────────────────────────────────────
  'count.showing':               { nl: 'Toont {from}–{to} van {total}', en: 'Showing {from}–{to} of {total}' },

  // ── Overscroll ────────────────────────────────────────────────────────────
  'overscroll.release':          { nl: 'Loslaten…',                     en: 'Release…' },
  'overscroll.page':             { nl: 'pagina {p} / {total}',          en: 'page {p} / {total}' },

  // ── Studio ────────────────────────────────────────────────────────────────
  'studio.count_simple':         { nl: '{n} opnames',                   en: '{n} recordings' },
  'studio.count_with_deleted':   { nl: '{n} opnames (waarvan {d} verwijderd)',
                                   en: '{n} recordings ({d} deleted)' },
  'studio.confirm_delete_one':   { nl: 'Studio-opname "{name}" verwijderen?',
                                   en: 'Delete studio recording "{name}"?' },

  // ── Recorder ─────────────────────────────────────────────────────────────
  'recorder.start':              { nl: 'Opname starten',                en: 'Start recording' },
  'recorder.stop':               { nl: 'Stoppen & opslaan',             en: 'Stop & save' },
  'recorder.no_camera':          { nl: 'Camera-toegang geweigerd',      en: 'Camera access denied' },
  'recorder.start_failed':       { nl: 'Kon niet starten',              en: 'Could not start' },

  // ── Signbank operation lifecycle ──────────────────────────────────────────
  'sb.busy.broadcast':           { nl: 'Bezig met broadcasten naar Signbank…',
                                   en: 'Broadcasting to Signbank…' },
  'sb.busy.disconnect':          { nl: 'Verzoek tot verwijdering…',     en: 'Requesting removal…' },
  'sb.op_title.broadcast':       { nl: 'Broadcast naar Signbank — {name}',
                                   en: 'Broadcast to Signbank — {name}' },
  'sb.op_title.disconnect':      { nl: 'Loskoppelen — {name}',          en: 'Disconnect — {name}' },
  'sb.status.done_http':         { nl: 'Klaar — HTTP {status} in {ms} ms',
                                   en: 'Done — HTTP {status} in {ms} ms' },
  'sb.status.failed_http':       { nl: 'Mislukt — HTTP {status} in {ms} ms',
                                   en: 'Failed — HTTP {status} in {ms} ms' },
  'sb.status.done_ms':           { nl: 'Klaar — {ms} ms',              en: 'Done — {ms} ms' },
  'sb.compare.all_match':        { nl: 'Alles komt overeen met Signbank',
                                   en: 'Everything matches Signbank' },
  'sb.error.request_failed':     { nl: 'Verzoek mislukt',               en: 'Request failed' },
  'sb.error.fetch_failed':       { nl: 'Ophalen mislukt',               en: 'Fetch failed' },
  'sb.section.source':           { nl: 'Bron — form_data rij',          en: 'Source — form_data row' },
  'sb.btn.retry':                { nl: 'Opnieuw verzenden',             en: 'Resend' },
  'sb.badge_tooltip':            { nl: 'Verbonden met Signbank glos #{id}',
                                   en: 'Connected to Signbank gloss #{id}' },

  // ── Confirm modal title ───────────────────────────────────────────────────
  'confirm.title':               { nl: 'Weet je het zeker?',            en: 'Are you sure?' },

  // ── Row actions extra ─────────────────────────────────────────────────────
  'rowmenu.show':                { nl: 'Zichtbaar maken',               en: 'Show' },

  // ── Row thema empty ───────────────────────────────────────────────────────
  'row.thema_none':              { nl: '— geen thema —',                en: '— no theme —' },

  // ── Senses pair editor ────────────────────────────────────────────────────
  'senses.both_required':        { nl: 'Senses NL en EN moeten beide ingevuld zijn',
                                   en: 'Senses NL and EN must both be filled in' },
  'senses.incomplete':           { nl: 'Let op: niet alle sense-paren NL/EN zijn ingevuld',
                                   en: 'Note: not all sense pairs (NL/EN) are filled in' },
  'senses.add_pair':             { nl: '+ sense paar toevoegen',        en: '+ add sense pair' },

  // ── Thumb prompt ─────────────────────────────────────────────────────────
  'thumb.click_to_record':       { nl: 'Klik om zelfopname te maken',   en: 'Click to record selfie video' },
};
