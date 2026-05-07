# menu_beta — Gloss Management Interface

## Goal
A clean rebuild of the gloss editor at `/web/menu_beta/`. Users browse and edit glosses (sign language entries) from `form_data`, with paginated table, filters, inline editing, self-capture video recording, and a list of studio videos linked via `matched_transcriptions.m_transcription = form_data.id`.

## Authentication
- Reuse `/web/login.html` flow.
- Frontend reads `sessionObject` cookie (JSON: `{userId, username, role, expiresAt}`) on `.signcollect.nl` domain. If missing/invalid, redirect to `/login.html?redirect=<current_url>`.
- PHP endpoints read the same cookie server-side, JSON-decode, and use `userId`. Endpoints that mutate data require a valid session.

## Data model (verified)

### `form_data` (one row per gloss)
| Field | Notes |
|---|---|
| `id` | PK |
| `glos`, `glos_engels` | Text labels (NL/EN) |
| `wie` | JSON array of userIds (string) — owners/recorders |
| `thema` | Single string |
| `labels` | JSON array of label names |
| `glosZichtbaar` | `0` active (default), `1` hidden — flipped from earlier; matches user's "delete sets =1" instruction |
| `zelfopname` | Mixed: bare filename or JSON array of filenames. **New writes always normalize to JSON array.** |
| `senses` | JSON array of NL sense strings |
| `sensesEngels` | JSON array of EN sense strings |
| `control_nodig` | JSON array of userIds (subset of `wie`) who still need to check this gloss. Empty array = klaar. |
| `logboek` | Append-only audit log (newline-separated) |

### `matched_transcriptions` (studio videos)
- `m_transcription` stores the matching `form_data.id` as a string.
- `m_file` is the studio video filename, `thumbnail` flag indicates a thumbnail exists.

### `users`
- `userId`, `user`, `role`. Used to map `wie`/`control_nodig` userIds → display names.

### `labels`
- Catalog: `id`, `label`, `color`. Drives label filter dropdown.

## File layout
```
/web/menu_beta/
├── index.html
├── css/{theme,table,filters,modals,video}.css
├── js/{api,filters,table,pagination,recorder,senses,main}.js
├── php_api/
│   ├── db.php
│   ├── session.php
│   ├── glosses_list.php
│   ├── glosses_save.php
│   ├── glosses_create.php
│   ├── glosses_delete.php
│   ├── upload_video.php
│   ├── delete_video.php
│   ├── filters_options.php
│   └── current_user.php
└── docs/spec.md
```

## Endpoints

### `GET php_api/current_user.php`
Returns `{userId, username, role}` from cookie, or `401`.

### `GET php_api/filters_options.php`
Returns `{themas: [...], labels: [{id, label, color}], users: [{userId, user}]}`. Cached client-side per session.

### `POST php_api/glosses_list.php`
Body: `{search, thema, labels[], statuses[], mineOnly, page}`.

Default behavior (no statuses):
- Excludes hidden rows: `(glosZichtbaar = 0 OR glosZichtbaar IS NULL)`
- If `mineOnly=true`: restricts to rows where `wie LIKE '%"<currentUserId>"%'` (matches well-formed JSON entries; legacy formats like `[1]` / `['1']` won't match).

Filters compose as AND. Statuses (multi-select) compose as AND:
| Status key | SQL fragment |
|---|---|
| `hidden` | `glosZichtbaar = 1` (also disables the default "exclude hidden" rule) |
| `no_label` | `(labels IS NULL OR labels = '' OR labels = '[]')` |
| `no_thema` | `(thema IS NULL OR thema = '')` |
| `no_zelfopname` | `(zelfopname IS NULL OR zelfopname = '' OR zelfopname = '[]')` |
| `no_studio_video` | `NOT EXISTS (SELECT 1 FROM matched_transcriptions mt WHERE CAST(mt.m_transcription AS UNSIGNED) = form_data.id)` |

Search: `(glos LIKE ? OR glos_engels LIKE ? OR senses LIKE ? OR sensesEngels LIKE ?)` with `%term%`.

Labels (multi): `labels LIKE '%"L1"%' AND labels LIKE '%"L2"%'`.

Page size: `25`. Returns:
```json
{
  "rows": [{
    "id": 123,
    "glos": "...", "glos_engels": "...",
    "wie": ["1","5"],
    "thema": "...", "labels": ["..."],
    "glosZichtbaar": 1,
    "zelfopname": ["hash.webm"],
    "senses": ["..."], "sensesEngels": ["..."],
    "control_nodig": ["5"],
    "studio_videos": [{"id": 222, "m_file": "M2024..._0015.wav", "thumbnail": 1}]
  }],
  "total": 27699,
  "page": 1,
  "pageSize": 25
}
```

`studio_videos` is gathered in a single follow-up query: `SELECT id, m_transcription, m_file, thumbnail FROM matched_transcriptions WHERE m_transcription IN (<page-ids>)`.

### `POST php_api/glosses_save.php`
Body: `{id, fields: {<field>: <value>}}`. Whitelist of editable fields: `glos`, `glos_engels`, `thema`, `labels`, `senses`, `sensesEngels`, `control_nodig`, `glosZichtbaar`, `zelfopname`. Appends to `logboek`. Returns updated row.

### `POST php_api/glosses_create.php`
Body: `{glos, glos_engels?, thema?, labels?, senses?, sensesEngels?}`. Sets `wie = [currentUserId]`, `glosZichtbaar = 0` (active), `control_nodig = []`. Returns new row.

### `POST php_api/glosses_delete.php`
Body: `{id}`. Soft-hide: `UPDATE form_data SET glosZichtbaar = 1` + log entry.

### `POST php_api/upload_video.php`
Multipart: `file` (webm blob) + `id`. Saves to `/web/uploads/<sha256>.webm` (hash from file content), appends filename to row's `zelfopname` JSON array (creates array if previously bare/empty), logs to `logboek`. Returns `{filename, zelfopname: [...]}`.

### `POST php_api/delete_video.php`
Body: `{id, filename}`. Removes filename from `zelfopname` JSON array. Does NOT delete the file from disk (videos may be referenced elsewhere). Returns updated array.

## Frontend

### Layout
```
┌──────────────────────────────────────────────────────────────┐
│ Header: signCollect — menu_beta            [user] [logout]   │
├──────────────────────────────────────────────────────────────┤
│ [search] [thema▼] [labels(multi)▼] [status(multi)▼] [+ Add]  │
├──────────────────────────────────────────────────────────────┤
│ Row: thumb │ Glos NL  │ Glos EN  │ wie │ Gec │ ⋮              │
│            │ Senses NL [+]                                    │
│            │ Senses EN [+]                                    │
│            │ Studio videos: [thumb][thumb]…                   │
├──────────────────────────────────────────────────────────────┤
│           « 1 2 3 … N »   showing 1–25 of 27,699              │
└──────────────────────────────────────────────────────────────┘
```

### Row interactions
- **Thumbnail/preview**: First entry in `zelfopname` → `<video>` element with `poster` from a thumbnail (if generated; otherwise just the video first frame). On `mouseenter` → `play()`, on `mouseleave` → `pause()` + reset to 0.
- **Glos NL / Glos EN**: `<input>` text, debounced `change`/`blur` save (500ms).
- **wie**: read-only display of usernames mapped from userIds (existing menu pattern lets non-owners see but not edit; v1 of beta will be read-only).
- **Gecontroleerd**: dropdown of users in `wie`, checkbox per user. Checked = in `control_nodig` (still needs to check). Empty `control_nodig` = klaar (green badge). Saves on change.
- **⋮ menu**: Record self-capture · Toggle visibility · Delete (soft-hide) · Open studio video list.
- **Senses NL/EN**: each is a list of `<input>` rows with × button per row + a `+` button that adds a blank row. Save on blur as JSON array.
- **Studio videos**: small thumbnails (clickable to open a viewer) of each `matched_transcriptions` row whose `m_transcription = id`.

### Record self-capture flow
1. Click record from row menu → modal opens with live `<video>` from `getUserMedia({video: true, audio: false})`.
2. Start/stop button. On stop, `MediaRecorder` produces a `Blob` (`video/webm`).
3. POST blob to `upload_video.php` with `id`. Server hashes content (SHA-256), writes `/web/uploads/<hash>.webm`, updates `zelfopname`. Returns updated array.
4. UI refreshes the row's video.

### Add gloss modal
Fields: Glos NL\* (required), Glos EN, Thema (free text + autocomplete from existing themas), Labels (multi-select from `labels` table). On save, calls `glosses_create.php` and prepends to current page (or jumps to a fresh empty filter view).

## Open items / assumptions
- `wie` is **read-only** in v1; existing UI in `/web/menu/` uses checkbox dropdown for it but the user did not request edit ability for beta.
- Delete = soft-hide via `glosZichtbaar = 0` (interpreting the user's "yes, set glosZichtbaar" answer as toggle-to-hidden, since the existing `verbergGlos` does this).
- No thumbnail-generation pipeline in v1 — `<video>` element shows first frame natively. A future task could pre-generate JPGs.
- All endpoints respond JSON; PHP errors return `{error: "..."}` with appropriate HTTP status.
