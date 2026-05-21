# LSM dataset support — design

**Date:** 2026-05-21
**Status:** Draft, awaiting implementation
**Scope:** menu_beta repo only (the studio/recording pipeline that writes new `matched_transcriptions` rows is out of scope — flagged as a follow-up).

## Goal

Introduce a multi-dataset framework in menu_beta so:

- User `inocencio` defaults to an "LSM" dataset that lives in a separate table `lsm_data` (same schema as `form_data`).
- Other users default to "NGT" (the existing `form_data`) with no UI change.
- All existing `form_data` queries become dataset-aware via a thin registry.
- `matched_transcriptions` joins/subqueries are filtered by `zOg` per the active dataset so LSM and NGT glosses with colliding ids don't pick up each other's studio videos.
- Future datasets can be added by appending one entry to a PHP config file.

## Datasets registry — new file `php_api/datasets.php`

Whitelisted lookup keyed by short code. Each entry: table name, UI label, `zOg` value used in matched_transcriptions, Signbank dataset id + acronym for the sync helpers.

```php
function datasets_registry(): array {
    return [
        'ngt' => [
            'code'                => 'ngt',
            'label'               => 'NGT',
            'table'               => 'form_data',
            'zOg'                 => null,   // default-bucket dataset; matched_transcriptions has many legacy zOg values
            'signbank_dataset_id' => 2,      // local; 5 on production
            'signbank_acronym'    => 'NGT',
        ],
        'lsm' => [
            'code'                => 'lsm',
            'label'               => 'LSM',
            'table'               => 'lsm_data',
            'zOg'                 => 'lsm',  // strict tag — new matched_transcriptions rows for LSM get this
            'signbank_dataset_id' => null,   // filled after creating a local-Signbank LSM dataset
            'signbank_acronym'    => 'LSM',
        ],
    ];
}
```

Helpers:

- `dataset_resolve(?string $code, array $userAllowed): array|false` — return the entry the caller asked for *if* it's both in the registry and in `$userAllowed`. If `$code` is null, fall back to the first allowed dataset. If `$code` is set but not allowed, return `false` so the caller can emit a 403. Never silently rewrite a forbidden request to an allowed one.
- `dataset_table(string $code): string` — return the table name *only* if `$code` is in the registry. Safe to interpolate into SQL with backticks because the value comes from a hardcoded whitelist.
- `dataset_zog_predicate(string $code, string $mtAlias='mt'): string` — for tagged datasets (`zOg` not null) returns `mt.zOg = '<value>'`. For the default-bucket dataset (`zOg` null) returns `(mt.zOg IS NULL OR mt.zOg NOT IN (<all tagged values>))`. Adding a new tagged dataset automatically tightens NGT's predicate.

## DB migration — `migrations/2026-05-21-add-datasets.sql`

```sql
-- New columns on users
ALTER TABLE users
  ADD COLUMN default_dataset  VARCHAR(16) NOT NULL DEFAULT 'ngt',
  ADD COLUMN allowed_datasets JSON         NULL;

-- Backfill everyone to NGT-only
UPDATE users
  SET allowed_datasets = JSON_ARRAY('ngt')
  WHERE allowed_datasets IS NULL;

-- inocencio → LSM only
UPDATE users
  SET default_dataset  = 'lsm',
      allowed_datasets = JSON_ARRAY('lsm')
  WHERE username = 'inocencio';

-- New LSM glosses table — same shape as form_data
CREATE TABLE lsm_data LIKE form_data;
```

Notes:

- `CREATE TABLE … LIKE` clones columns, types, indexes, AUTO_INCREMENT — but not foreign keys or triggers. The new table starts empty.
- `allowed_datasets` is JSON; requires MySQL 5.7+. Confirm before running.
- The migration script will add `IF NOT EXISTS` guards where MySQL supports them so the script is rerunnable in a dev environment.

## Backend refactor: dataset-aware queries

Every endpoint that touches `form_data` reads the active dataset from the request, validates it against the caller's `allowed_datasets`, and routes via the registry.

Pattern:

```php
$user    = require_session();
$allowed = json_decode($user['allowed_datasets'] ?? '["ngt"]', true) ?: ['ngt'];
$ds      = dataset_resolve($body['dataset'] ?? null, $allowed);
if ($ds === false) json_response(['error' => 'forbidden_dataset'], 403);
$table   = $ds['table'];     // 'form_data' or 'lsm_data' — whitelisted
$mtZog   = dataset_zog_predicate($ds['code']);   // "mt.zOg = 'lsm'" or "(mt.zOg IS NULL OR mt.zOg NOT IN ('lsm'))"

// instead of: FROM form_data
$sql = "SELECT … FROM `$table` WHERE …";

// matched_transcriptions joins/subqueries: append the zOg predicate
//   e.g. EXISTS (SELECT 1 FROM matched_transcriptions mt
//                WHERE mt.m_transcription = f.id AND $mtZog)
```

Security: `dataset_resolve` returns `false` when the caller asks for a dataset not in their `allowed_datasets`. The shared snippet above wraps that with an explicit 403:

```php
if ($ds === false) json_response(['error' => 'forbidden_dataset'], 403);
```

That way a forged `dataset=lsm` from a non-inocencio user gets a clear failure rather than silently returning NGT data labelled as LSM.

Files touched (~13):

- `php_api/`: `glosses_list.php`, `glosses_save.php`, `glosses_create.php`, `glosses_delete.php`, `delete_video.php`, `upload_video.php`, `studio_video_delete.php`, `filters_options.php`
- `signbank_sync/`: `broadcast_gloss.php`, `delete_gloss.php`, `fetch_gloss.php`, `force_push.php`, `force_pull.php`, `sync_helpers.php`
- `php_api/current_user.php`: also returns `datasets` (allowed list with `{code,label}` entries), `defaultDataset`, `activeDataset`.

Signbank sync change worth flagging: `config.php` currently holds a single `dataset_id`/`dataset_acronym`. Those move out of config into the registry per-dataset. `config.php` keeps only `base_url`, `public_url`, `api_key`, `auth_scheme`, `timeout_seconds` (auth + transport, not data). A new helper `signbank_dataset_for(string $code)` returns the id/acronym the sync endpoints need.

## Frontend: dataset switcher

`index.html`: small selector in the header. Hidden when `state.user.datasets.length <= 1`, so NGT-only users see no UI change.

`main.js`:

```js
// init()
state.dataset = state.user.activeDataset || 'ngt';
state.allowedDatasets = state.user.datasets || [{code:'ngt',label:'NGT'}];
if (state.allowedDatasets.length > 1) renderDatasetSwitcher();

// on switcher change
state.dataset = newCode;
localStorage.setItem('menu_beta.dataset', newCode);
state.page = 1;
await loadList();
```

`api.js`: extend `call()` to attach `dataset: state.dataset` to every JSON body and `?dataset=…` to GETs. One change covers all endpoints.

The existing Signio/Signbank toggle stays as is — it operates *inside* the active dataset (filters by `extern` on whichever table is active). For inocencio: no dataset switcher (only one allowed), same Signio/Signbank toggle, all filtering inside `lsm_data`.

## Testing

Manual:

- Log in as a normal user → list loads from `form_data`, no dataset switcher visible, save/broadcast/etc. unchanged.
- Log in as `inocencio` → list loads from `lsm_data` (empty initially), no dataset switcher visible (single allowed dataset), Signio/Signbank toggle still works.
- Seed a few fixture rows in `lsm_data` plus a `matched_transcriptions(m_transcription=<lsm row id>, zOg='lsm', …)` and confirm studio-video filters/joins use the LSM bucket.
- Collision check: an NGT row whose id matches an LSM row id does *not* pick up the LSM studio video, and vice versa.
- Forged dataset: a non-inocencio user calling an endpoint with `dataset=lsm` in the body gets 403.

## Rollout order

1. Add `datasets.php`, helpers, and `current_user.php` changes — no behavior change yet (default `ngt` for everyone, no allowed_datasets column read yet).
2. Run the migration (new columns, `lsm_data`, set inocencio).
3. Refactor PHP endpoints to read `dataset` and route table + zOg predicate.
4. Add JS switcher + thread `dataset` through `api.js`.
5. Smoke-test as a normal user, then as inocencio.
6. Create the local-Signbank LSM dataset (Django admin), fill its id into `datasets.php`.

## Out of scope (follow-ups)

- The external studio/recording pipeline that INSERTs `matched_transcriptions` rows must be told to set `zOg='lsm'` for LSM glosses — that change lives in *that* system, not menu_beta.
- Admin UI for managing user→dataset assignments. For now, assignments are set via SQL.
- LSM-specific UI niceties (different theme color, badge, dedicated landing). Defer until inocencio is actually using it.
