# LSM Dataset Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a multi-dataset framework in menu_beta so user `inocencio` defaults to a new LSM dataset (separate `lsm_data` table) while everyone else stays on NGT (`form_data`) with no UI change.

**Architecture:** A hardcoded PHP registry (`php_api/datasets.php`) defines each dataset's table name, label, signbank id/acronym, and matched_transcriptions `zOg` filter clause. Per-user `default_dataset` + `allowed_datasets` columns on `users` gate access. Every endpoint that touches `form_data` reads `dataset` from the request, validates it against the caller's allowed list, then routes the table name + matched_transcriptions predicate through the registry. The JS dataset switcher is only rendered when a user has access to more than one dataset.

**Tech Stack:** PHP 8, MySQL (5.7+ for JSON columns), vanilla JS (no framework), Bootstrap CSS. No test framework — verification is curl + manual browser check.

**Testing note:** This codebase has no automated test harness. Each task's "verify" step is either a curl-based smoke test or a manual browser check. Where curl works without auth, the plan shows the exact command. For session-gated endpoints the verification is a browser action.

---

## Task 1: Datasets registry + helpers

**Files:**
- Create: `php_api/datasets.php`

- [ ] **Step 1: Write the new file**

```php
<?php
/**
 * Hardcoded datasets registry.
 * Adding a new dataset = one entry here + (separately) creating the table.
 *
 *   code:                 short identifier, used in URLs/requests
 *   label:                shown in the UI switcher
 *   table:                MySQL table — MUST be a whitelisted value (never
 *                         user-supplied) because callers interpolate it
 *                         straight into SQL via backticks
 *   matched_zog_clause:   SQL fragment that goes inside the
 *                         matched_transcriptions EXISTS subqueries; uses
 *                         the placeholders {mt} (alias of matched_transcriptions)
 *                         and {f} (alias of the gloss table). Set per-dataset
 *                         because NGT's existing logic depends on extern;
 *                         LSM uses a simple zOg='lsm' tag.
 *   signbank_dataset_id:  numeric id on the connected Signbank install,
 *                         or null if this dataset isn't synced to Signbank yet
 *   signbank_acronym:     dataset acronym on the connected Signbank install
 */
function datasets_registry(): array {
    return [
        'ngt' => [
            'code'                => 'ngt',
            'label'               => 'NGT',
            'table'               => 'form_data',
            'matched_zog_clause'  => "(({f}.extern = '1' AND {mt}.zOg IN ('labels','extern'))"
                                   . " OR ({f}.extern IS NULL AND {mt}.zOg = 'Glos'))",
            'signbank_dataset_id' => 2,            // local install
            'signbank_acronym'    => 'NGT',
        ],
        'lsm' => [
            'code'                => 'lsm',
            'label'               => 'LSM',
            'table'               => 'lsm_data',
            'matched_zog_clause'  => "{mt}.zOg = 'lsm'",
            'signbank_dataset_id' => null,         // fill after creating local LSM dataset
            'signbank_acronym'    => 'LSM',
        ],
    ];
}

/**
 * Resolve a dataset code against the caller's allowed list.
 *   - $code === null              → return the first allowed dataset (default).
 *   - $code in registry + allowed → return its entry.
 *   - $code set but not allowed   → return false so caller can emit 403.
 *   - $code set but not in reg    → return false (treat unknown as forbidden).
 * Never silently rewrite a forbidden request to an allowed one.
 */
function dataset_resolve(?string $code, array $userAllowed) {
    $reg = datasets_registry();
    if ($code === null || $code === '') {
        foreach ($userAllowed as $c) if (isset($reg[$c])) return $reg[$c];
        return $reg['ngt']; // belt-and-braces: registry always has ngt
    }
    if (!isset($reg[$code])) return false;
    if (!in_array($code, $userAllowed, true)) return false;
    return $reg[$code];
}

/** Whitelisted table-name lookup — safe to interpolate via backticks. */
function dataset_table(string $code): string {
    $reg = datasets_registry();
    return $reg[$code]['table'] ?? 'form_data';
}

/**
 * Render the matched_transcriptions zOg/extern clause for $code with the
 * caller's chosen aliases. Used inside the studio-video EXISTS subqueries.
 */
function dataset_matched_zog_clause(string $code, string $mtAlias = 'mt', string $fAlias = 'f'): string {
    $reg = datasets_registry();
    $tpl = $reg[$code]['matched_zog_clause'] ?? "{mt}.zOg = '_never_matches_'";
    return strtr($tpl, ['{mt}' => $mtAlias, '{f}' => $fAlias]);
}

/**
 * Build a `EXISTS (SELECT 1 FROM matched_transcriptions ... )` predicate
 * for "this gloss row has at least one non-deleted studio video".
 */
function dataset_studio_video_exists(string $code, string $fAlias): string {
    $mtZog = dataset_matched_zog_clause($code, 'mt', $fAlias);
    return "EXISTS (SELECT 1 FROM matched_transcriptions mt
                    WHERE mt.m_transcription REGEXP '^[0-9]+$'
                      AND CAST(mt.m_transcription AS UNSIGNED) = $fAlias.id
                      AND (mt.added IS NULL OR UPPER(mt.added) <> 'DELETE')
                      AND $mtZog)";
}

/** Default dataset code returned when a user record has no default set. */
function dataset_default_code(): string { return 'ngt'; }
```

- [ ] **Step 2: PHP syntax check**

```bash
php -l /web/menu_beta/php_api/datasets.php
```

Expected: `No syntax errors detected ...`

- [ ] **Step 3: Smoke-test the helpers from CLI**

```bash
php -r 'require "/web/menu_beta/php_api/datasets.php";
  var_dump(dataset_resolve("lsm", ["lsm"])["table"]);              // string(8) "lsm_data"
  var_dump(dataset_resolve("lsm", ["ngt"]));                       // bool(false)
  var_dump(dataset_resolve(null, ["lsm","ngt"])["table"]);         // string(8) "lsm_data" (first allowed)
  echo dataset_matched_zog_clause("lsm"), PHP_EOL;                 // mt.zOg = '\''lsm'\''
  echo dataset_studio_video_exists("ngt","form_data"), PHP_EOL;'
```

Expected: matches the comments above; the NGT EXISTS output includes the existing `extern` logic.

- [ ] **Step 4: Commit**

```bash
cd /web/menu_beta
git add php_api/datasets.php
git commit -m "datasets: hardcoded registry + helpers (no callers yet)"
```

---

## Task 2: Per-user dataset access helper

**Files:**
- Modify: `php_api/session.php` (append a new function)

- [ ] **Step 1: Append `current_user_datasets()` helper**

Append to `/web/menu_beta/php_api/session.php` (after `require_session`):

```php
/**
 * Resolve the active dataset for the current request.
 *  - Looks up the caller's allowed_datasets/default_dataset in `users`.
 *  - Reads the requested dataset code from $body['dataset'] / $_GET['dataset'].
 *  - On forbidden_dataset, emits 403 and exits.
 *
 * Returns the registry entry array (never false).
 */
function require_dataset(PDO $pdo, array $session, $bodyOrNull = null): array {
    require_once __DIR__ . '/datasets.php';

    $stmt = $pdo->prepare("SELECT default_dataset, allowed_datasets FROM users WHERE userId = ?");
    $stmt->execute([(int)$session['userId']]);
    $row = $stmt->fetch() ?: [];

    $default = $row['default_dataset'] ?? dataset_default_code();
    $allowed = [];
    if (!empty($row['allowed_datasets'])) {
        $d = json_decode($row['allowed_datasets'], true);
        if (is_array($d)) $allowed = array_values(array_filter($d, 'is_string'));
    }
    if (!$allowed) $allowed = [$default ?: dataset_default_code()];

    $requested = null;
    if (is_array($bodyOrNull) && isset($bodyOrNull['dataset'])) $requested = (string)$bodyOrNull['dataset'];
    elseif (isset($_GET['dataset']))                            $requested = (string)$_GET['dataset'];

    // null requested → pick the user's default if it's in their allowed list,
    // otherwise the first allowed dataset.
    if ($requested === null || $requested === '') {
        $requested = in_array($default, $allowed, true) ? $default : $allowed[0];
    }

    $ds = dataset_resolve($requested, $allowed);
    if ($ds === false) json_response(['error' => 'forbidden_dataset', 'requested' => $requested], 403);
    return $ds;
}
```

- [ ] **Step 2: PHP syntax check**

```bash
php -l /web/menu_beta/php_api/session.php
```

Expected: `No syntax errors detected ...`

- [ ] **Step 3: Commit**

```bash
cd /web/menu_beta
git add php_api/session.php
git commit -m "session: add require_dataset() helper (reads users.default_dataset / allowed_datasets)"
```

---

## Task 3: Schema migration script

**Files:**
- Create: `migrations/2026-05-21-add-datasets.sql`

- [ ] **Step 1: Confirm MySQL version supports JSON columns**

```bash
mysql --version
```

Expected: `mysql Ver 8.0...` or `... 5.7...` (JSON requires 5.7+). If older, escalate — don't proceed.

- [ ] **Step 2: Write the migration file**

```sql
-- 2026-05-21 — add multi-dataset support

-- 1. Per-user dataset settings on the existing users table.
-- ALTER TABLE … ADD COLUMN IF NOT EXISTS is MySQL 8.0+. If on 5.7,
-- run the ALTERs without IF NOT EXISTS and accept the error on re-run.
ALTER TABLE users
  ADD COLUMN default_dataset  VARCHAR(16) NOT NULL DEFAULT 'ngt',
  ADD COLUMN allowed_datasets JSON         NULL;

-- 2. Backfill: every existing user gets NGT-only access.
UPDATE users
  SET allowed_datasets = JSON_ARRAY('ngt')
  WHERE allowed_datasets IS NULL;

-- 3. inocencio → LSM only.
UPDATE users
  SET default_dataset  = 'lsm',
      allowed_datasets = JSON_ARRAY('lsm')
  WHERE username = 'inocencio';

-- 4. New LSM glosses table — exact same shape as form_data.
--    LIKE clones columns, types, indexes, AUTO_INCREMENT — but not
--    triggers or foreign keys (form_data has none of either).
CREATE TABLE IF NOT EXISTS lsm_data LIKE form_data;
```

- [ ] **Step 3: Commit the script (do NOT run it yet — that's Task 4)**

```bash
cd /web/menu_beta
git add migrations/2026-05-21-add-datasets.sql
git commit -m "migrations: add datasets schema (users columns + lsm_data table)"
```

---

## Task 4: Apply the migration

> Touches production data. Requires user confirmation before each statement. **Pause here** and ask the user "ready to run the migration against `admin_gebarenoverleg`?" If yes:

- [ ] **Step 1: Backup snapshot of `users` table only**

```bash
mysqldump --single-transaction admin_gebarenoverleg users > /tmp/users-pre-lsm-migration.sql
ls -la /tmp/users-pre-lsm-migration.sql
```

Expected: a non-empty .sql file.

- [ ] **Step 2: Run the migration**

```bash
mysql admin_gebarenoverleg < /web/menu_beta/migrations/2026-05-21-add-datasets.sql
```

Expected: no errors.

- [ ] **Step 3: Verify**

```bash
mysql admin_gebarenoverleg -e "SHOW COLUMNS FROM users LIKE 'default_dataset';
                               SHOW COLUMNS FROM users LIKE 'allowed_datasets';
                               SELECT username, default_dataset, allowed_datasets FROM users WHERE username='inocencio';
                               SHOW TABLES LIKE 'lsm_data';
                               SELECT COUNT(*) AS lsm_rows FROM lsm_data;"
```

Expected:
- Both new columns exist
- inocencio row shows `default_dataset='lsm'`, `allowed_datasets=["lsm"]`
- `lsm_data` table exists, 0 rows

No commit (no files changed).

---

## Task 5: current_user.php exposes dataset info

**Files:**
- Modify: `php_api/current_user.php`

- [ ] **Step 1: Edit to include datasets payload**

Replace the file body (between the `require_once` block and the final `json_response`) so the final shape is:

```php
<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/datasets.php';
require_once __DIR__ . '/../signbank_sync/client.php';

$s = require_session();
$cfg = signbank_config();
$s['signbankPublicUrl'] = rtrim((string)($cfg['public_url'] ?? 'https://signbank.cls.ru.nl'), '/');

// Look up persisted user prefs (gracefully tolerates an unmigrated `users` table).
$defaultContext = 'signio';
$defaultDataset = dataset_default_code();
$allowed        = [$defaultDataset];
try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT default_context, default_dataset, allowed_datasets FROM users WHERE userId = ?");
    $stmt->execute([(int)$s['userId']]);
    $row = $stmt->fetch();
    if ($row) {
        if (in_array($row['default_context'] ?? null, ['signio','signbank'], true)) {
            $defaultContext = $row['default_context'];
        }
        if (!empty($row['default_dataset'])) $defaultDataset = $row['default_dataset'];
        if (!empty($row['allowed_datasets'])) {
            $d = json_decode($row['allowed_datasets'], true);
            if (is_array($d)) $allowed = array_values(array_filter($d, 'is_string'));
        }
    }
} catch (Throwable $e) {
    // leave defaults
}
$s['defaultContext'] = $defaultContext;

// datasets payload for the JS switcher
$reg = datasets_registry();
$s['datasets'] = array_values(array_map(fn($c) => [
    'code'  => $c,
    'label' => $reg[$c]['label'] ?? $c,
], array_filter($allowed, fn($c) => isset($reg[$c]))));
$s['defaultDataset'] = in_array($defaultDataset, $allowed, true) ? $defaultDataset
                       : ($allowed[0] ?? dataset_default_code());
$s['activeDataset']  = $s['defaultDataset'];

json_response($s);
```

- [ ] **Step 2: PHP syntax check**

```bash
php -l /web/menu_beta/php_api/current_user.php
```

Expected: `No syntax errors ...`

- [ ] **Step 3: Browser smoke test**

Load `https://signcollect.nl/menu_beta/index.html` while logged in as a normal NGT user. Open DevTools → Network → click `current_user.php` → response should include:

```json
{ ...,
  "datasets":[{"code":"ngt","label":"NGT"}],
  "defaultDataset":"ngt",
  "activeDataset":"ngt"
}
```

Log out, log in as `inocencio`, reload, response should show:

```json
{ ...,
  "datasets":[{"code":"lsm","label":"LSM"}],
  "defaultDataset":"lsm",
  "activeDataset":"lsm"
}
```

- [ ] **Step 4: Commit**

```bash
cd /web/menu_beta
git add php_api/current_user.php
git commit -m "current_user: return datasets / defaultDataset / activeDataset for the JS switcher"
```

---

## Task 6: Refactor `glosses_list.php` (heaviest)

**Files:**
- Modify: `php_api/glosses_list.php`

The current file uses `form_data` hardcoded in many places, including the `WHERE` predicates inside `$statusMap`, the `ORDER BY`, the `SELECT` columns, and the `latest_capture` join. We're going to (a) alias the gloss table as `f`, (b) source the table name from the registry, and (c) replace the hardcoded matched_transcriptions zOg/extern logic with `dataset_studio_video_exists(…, 'f')`.

- [ ] **Step 1: Add the dataset routing at the top**

After the existing `$session = require_session();` block, insert:

```php
require_once __DIR__ . '/datasets.php';
$pdo = db();
$ds      = require_dataset($pdo, $session, $body);
$table   = $ds['table'];                                       // e.g. form_data | lsm_data
$studio  = dataset_studio_video_exists($ds['code'], 'f');      // EXISTS subquery, aliased gloss table = f
$mtZog   = dataset_matched_zog_clause($ds['code'], 'mt', 'f'); // bare AND-fragment for free-form joins
```

Then move the existing `$pdo = db();` (further down in the file) up to this block so we only call `db()` once.

- [ ] **Step 2: Replace `$statusMap` entries**

Find the `$statusMap = [...]` array and replace the `no_studio_video`, `has_studio_video`, `extern_duplicate` entries:

```php
$statusMap = [
    'hidden'           => 'glosZichtbaar = 1',
    'no_label'         => '(labels IS NULL OR labels = \'\' OR labels = \'[]\')',
    'no_thema'         => '(thema IS NULL OR thema = \'\')',
    'no_zelfopname'    => '(zelfopname IS NULL OR zelfopname = \'\' OR zelfopname = \'[]\')',
    'has_zelfopname'   => '(zelfopname IS NOT NULL AND zelfopname <> \'\' AND zelfopname <> \'[]\')',
    'no_studio_video'  => 'NOT ' . $studio,
    'has_studio_video' => $studio,
    'extern_duplicate' => "extern = '1' AND glos IS NOT NULL AND glos <> ''
                            AND glos IN (
                              SELECT glos FROM `$table`
                              WHERE extern = '1' AND glos IS NOT NULL AND glos <> ''
                                AND (glosZichtbaar = 0 OR glosZichtbaar IS NULL)
                              GROUP BY glos HAVING COUNT(*) > 1
                            )",
];
```

- [ ] **Step 3: Replace the COUNT, ORDER BY, and SELECT queries**

Find the three SQL strings further down and update each:

```php
$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM `$table` f {$whereSql}");
$countStmt->execute($args);
$total = (int)$countStmt->fetch()['c'];

// ... (sortMap stays as-is but every `form_data.` → `f.`)

$sortMap = [
    'newest'         => 'f.id DESC',
    'oldest'         => 'f.id ASC',
    'glos_az'        => "(f.glos IS NULL OR f.glos = '') ASC, f.glos ASC, f.id DESC",
    'glos_za'        => "(f.glos IS NULL OR f.glos = '') ASC, f.glos DESC, f.id DESC",
    'latest_capture' => 'COALESCE(_lc.max_id, 0) DESC, f.id DESC',
    'oldest_capture' => '(_lc.max_id IS NULL) ASC, _lc.max_id ASC, f.id ASC',
];

$captureJoin = '';
if ($needsCaptureJoin) {
    $captureJoin = "LEFT JOIN (
        SELECT CAST(m_transcription AS UNSIGNED) AS gid, MAX(id) AS max_id
        FROM matched_transcriptions
        WHERE m_transcription REGEXP '^[0-9]+$'
          AND (added IS NULL OR UPPER(added) <> 'DELETE')
          AND $mtZog
        GROUP BY CAST(m_transcription AS UNSIGNED)
    ) _lc ON _lc.gid = f.id";
}
$orderBy = 'ORDER BY ' . ($externDupActive ? 'f.glos ASC, f.id DESC' : ($sortMap[$sort] ?? $sortMap['glos_az']));

$listSql = "SELECT f.id, f.glos, f.glos_engels, f.wie,
                   f.thema, f.labels, f.glosZichtbaar,
                   f.zelfopname, f.senses, f.sensesEngels,
                   f.control_nodig,
                   f.fonologie_fase1, f.fonologie_fase2,
                   f.signbank
            FROM `$table` f
            $captureJoin
            $whereSql
            $orderBy
            LIMIT $pageSize OFFSET $offset";
```

Note: `$mtZog` inside `$captureJoin` is critical so the "latest capture" sort doesn't pick up matched_transcriptions from a different dataset (where ids collide). If a previous `$mtZog` was *only* `mt.zOg = 'lsm'`, the NGT predicate it returns is the full `(...extern...)` clause and works identically to before for NGT.

- [ ] **Step 4: Verify the existing WHERE predicates that reference bare column names still work**

The bare column references in `$where[]` (e.g. `'thema = ?'`, `'labels LIKE ?'`, `'extern = \'1\''`) still work without an alias because MySQL resolves unqualified columns against the single FROM table. No change needed there.

- [ ] **Step 5: PHP syntax check**

```bash
php -l /web/menu_beta/php_api/glosses_list.php
```

Expected: `No syntax errors ...`

- [ ] **Step 6: Browser smoke test as NGT user**

Open `https://signcollect.nl/menu_beta/index.html`. Switch through every status filter (no_studio_video, has_studio_video, extern_duplicate, etc.) and both sort orders (latest_capture / oldest_capture). Compare row counts and ordering against pre-change behavior — they must be identical.

- [ ] **Step 7: Browser smoke test as inocencio**

Log in as inocencio. List should be empty (lsm_data has no rows yet). No errors in DevTools console.

- [ ] **Step 8: Commit**

```bash
cd /web/menu_beta
git add php_api/glosses_list.php
git commit -m "glosses_list: route table + matched_transcriptions predicate through dataset registry"
```

---

## Task 7: Refactor `glosses_save.php`

**Files:**
- Modify: `php_api/glosses_save.php`

- [ ] **Step 1: Insert dataset routing right after `require_session`**

```php
require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];
```

(Remove the later `$pdo = db();` so we only call it once.)

- [ ] **Step 2: Replace `form_data` references**

In the UPDATE and the follow-up SELECT, change `form_data` → `` `$table` ``:

```php
$stmt = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?");
$stmt->execute($args);

$row = $pdo->prepare(
    "SELECT id, glos, glos_engels, wie, thema, labels, glosZichtbaar,
            zelfopname, senses, sensesEngels, control_nodig, signbank,
            Handeness, strongHand, weakHand, HandshapeChange, RelationArticulators,
            handLocation, ContactType, MovementShape, MovementDirection,
            RepeatedMovement, AlternatingMovement,
            relativeOrienationMovement, relativeOrienationLocation, orientationChange,
            virtualObjectt, phonologyOther, mouthGesture, mouthing, phoneticVariation
     FROM `$table` WHERE id = ?"
);
```

- [ ] **Step 3: Pass dataset code into `signbank_auto_sync_fields`**

The signbank sync helper currently reads dataset id from `signbank_sync/config.php`. After Task 13 it will take a dataset code. For now, add a TODO comment near the call so we don't forget:

```php
// TODO(LSM-task-13): pass $ds['code'] once signbank_auto_sync_fields supports it
$sync = signbank_auto_sync_fields($pdo, $id, $changedOnly);
```

- [ ] **Step 4: PHP syntax check + browser save smoke test**

```bash
php -l /web/menu_beta/php_api/glosses_save.php
```

In the browser as a normal NGT user, edit any field on a row → Save → confirm the request payload now includes `"dataset":"ngt"` (DevTools Network tab) and the row updates successfully.

- [ ] **Step 5: Commit**

```bash
cd /web/menu_beta
git add php_api/glosses_save.php
git commit -m "glosses_save: route UPDATE/SELECT through dataset registry"
```

---

## Task 8: Refactor `glosses_create.php`

**Files:**
- Modify: `php_api/glosses_create.php`

- [ ] **Step 1: Read the existing file to see where INSERT INTO form_data lives**

```bash
grep -n "form_data\|require_session" /web/menu_beta/php_api/glosses_create.php
```

- [ ] **Step 2: Insert dataset routing after `require_session`**

```php
require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];
```

- [ ] **Step 3: Replace `INSERT INTO form_data` → `INSERT INTO `$table``, and any read-back SELECT**

Find every `form_data` reference in the file and replace with `` `$table` ``.

- [ ] **Step 4: PHP syntax + create-row browser smoke test**

```bash
php -l /web/menu_beta/php_api/glosses_create.php
```

Browser: as a normal user, create a new gloss → confirm it appears in the NGT list. As inocencio, create a new gloss → confirm it appears in the LSM list (lsm_data should now have 1 row).

- [ ] **Step 5: Commit**

```bash
cd /web/menu_beta
git add php_api/glosses_create.php
git commit -m "glosses_create: route INSERT through dataset registry"
```

---

## Task 9: Refactor `glosses_delete.php`

**Files:**
- Modify: `php_api/glosses_delete.php`

- [ ] **Step 1: Apply the same pattern**

After `require_session`:

```php
require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];
```

Replace every `form_data` → `` `$table` ``.

- [ ] **Step 2: PHP syntax + browser delete smoke test**

```bash
php -l /web/menu_beta/php_api/glosses_delete.php
```

Delete a test row in the NGT list — confirm it's gone. Repeat as inocencio.

- [ ] **Step 3: Commit**

```bash
cd /web/menu_beta
git add php_api/glosses_delete.php
git commit -m "glosses_delete: route DELETE through dataset registry"
```

---

## Task 10: Refactor `upload_video.php` + `delete_video.php`

**Files:**
- Modify: `php_api/upload_video.php`
- Modify: `php_api/delete_video.php`

- [ ] **Step 1: upload_video.php — insert dataset routing**

After `require_session`:

```php
require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $_POST);   // multipart upload → use $_POST
$table = $ds['table'];
```

Replace every `form_data` → `` `$table` `` (in the SELECT zelfopname / UPDATE lines).

Add the same TODO comment near `signbank_upload_video_for`:

```php
// TODO(LSM-task-13): pass $ds['code'] once signbank_upload_video_for supports it
$push = signbank_upload_video_for($pdo, $id, $dest);
```

- [ ] **Step 2: delete_video.php — same pattern**

After `require_session`:

```php
require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];
```

Replace every `form_data` → `` `$table` ``.

- [ ] **Step 3: PHP syntax check**

```bash
php -l /web/menu_beta/php_api/upload_video.php /web/menu_beta/php_api/delete_video.php
```

- [ ] **Step 4: Browser smoke test**

Record + upload a selfie video on a row in the NGT list. Delete it. Both should work.

- [ ] **Step 5: Commit**

```bash
cd /web/menu_beta
git add php_api/upload_video.php php_api/delete_video.php
git commit -m "video endpoints: route through dataset registry"
```

---

## Task 11: Refactor `studio_video_delete.php`

**Files:**
- Modify: `php_api/studio_video_delete.php`

This file does `UPDATE matched_transcriptions` to set `added='DELETE'` for a given gloss id. We need to add the dataset's `matched_zog_clause` to the WHERE so we never accidentally mark an LSM matched_transcription as deleted while logged into NGT.

This file's existing UPDATE doesn't filter by `zOg` at all — it marks all matched_transcriptions for the given gloss id as `added='DELETE'`. Keeping that behavior untouched for NGT (which has many legacy zOg values) and adding a strict `zOg='lsm'` predicate only for LSM avoids changing what an NGT delete does today.

- [ ] **Step 1: Read the existing file to see exact column names / parameter binding**

```bash
cat /web/menu_beta/php_api/studio_video_delete.php
```

- [ ] **Step 2: Apply dataset routing + branch by dataset code**

After `require_session`:

```php
require_once __DIR__ . '/datasets.php';
$pdo = db();
$ds  = require_dataset($pdo, $session, $body);
```

Then split the existing UPDATE:

```php
if ($ds['code'] === 'lsm') {
    $stmt = $pdo->prepare("UPDATE matched_transcriptions
                           SET added = 'DELETE'
                           WHERE m_transcription = ? AND zOg = 'lsm'");
} else {
    // NGT (and any future default-bucket dataset) — preserve existing
    // behavior: delete all matched_transcriptions for this gloss id
    // regardless of zOg, because legacy NGT rows use many zOg values.
    $stmt = $pdo->prepare("UPDATE matched_transcriptions
                           SET added = 'DELETE'
                           WHERE m_transcription = ?");
}
$stmt->execute([$id]);
```

Adjust column names if the actual file uses different ones (`mt.added`, `mt.m_transcription`, parameter binding via `:id`, etc.) — keep the existing style, only change the WHERE clause.

- [ ] **Step 3: PHP syntax + smoke test**

```bash
php -l /web/menu_beta/php_api/studio_video_delete.php
```

Browser as NGT user: hide a studio video on a row → confirm it disappears from the list.

- [ ] **Step 4: Commit**

```bash
cd /web/menu_beta
git add php_api/studio_video_delete.php
git commit -m "studio_video_delete: LSM scopes to zOg='lsm'; NGT unchanged"
```

---

## Task 12: Refactor `filters_options.php`

**Files:**
- Modify: `php_api/filters_options.php`

- [ ] **Step 1: Inspect existing queries**

```bash
cat /web/menu_beta/php_api/filters_options.php
```

This file likely returns distinct values for filter dropdowns (themas, labels, users). Each query that reads from `form_data` needs to route through `$table`.

- [ ] **Step 2: Apply dataset routing**

After `require_session`:

```php
require_once __DIR__ . '/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $_GET);   // GET request
$table = $ds['table'];
```

Replace every `form_data` → `` `$table` ``.

- [ ] **Step 3: PHP syntax + browser smoke test**

```bash
php -l /web/menu_beta/php_api/filters_options.php
```

Browser: filter dropdowns populate normally for NGT. For inocencio they should populate from `lsm_data` (likely empty until rows are created).

- [ ] **Step 4: Commit**

```bash
cd /web/menu_beta
git add php_api/filters_options.php
git commit -m "filters_options: route distinct queries through dataset registry"
```

---

## Task 13: Signbank sync — drop dataset_id from config, take it from registry

**Files:**
- Modify: `signbank_sync/config.php` (remove `dataset_id`, `dataset_acronym`)
- Modify: `signbank_sync/client.php` (add `signbank_dataset_info_for(string $code)` helper)
- Modify: `signbank_sync/sync_helpers.php` (add `$datasetCode` parameter to sync functions)

- [ ] **Step 1: Add helper to client.php**

Append to `/web/menu_beta/signbank_sync/client.php`:

```php
/**
 * Returns the dataset id + acronym for use against the connected
 * Signbank install, looked up from datasets.php by dataset code.
 *   ['id' => 2, 'acronym' => 'NGT']
 * Returns null when the dataset has no Signbank counterpart (id is null
 * in the registry) — callers should treat that as "no-op the sync".
 */
function signbank_dataset_info_for(string $code): ?array {
    require_once __DIR__ . '/../php_api/datasets.php';
    $reg = datasets_registry();
    if (!isset($reg[$code])) return null;
    if ($reg[$code]['signbank_dataset_id'] === null) return null;
    return [
        'id'      => (string)$reg[$code]['signbank_dataset_id'],
        'acronym' => (string)$reg[$code]['signbank_acronym'],
    ];
}
```

- [ ] **Step 2: Update `signbank_auto_sync_fields` to take a dataset code**

In `signbank_sync/sync_helpers.php`, change the signature and use the helper:

```php
function signbank_auto_sync_fields(PDO $pdo, int $form_data_id, array $changed, string $datasetCode = 'ngt'): ?array {
    $sb = signbank_dataset_info_for($datasetCode);
    if ($sb === null) return null;
    $glossid = signbank_get_connected_glossid($pdo, $form_data_id, $datasetCode);
    if ($glossid === null) return null;
    $payload = signbank_build_update_payload($changed);
    if (!$payload) return null;
    $path = '/dictionary/api_update_gloss/' . rawurlencode($sb['id']) . '/' . rawurlencode($glossid) . '/';
    $res = signbank_request('POST', $path, $payload);
    $res['fields_sent'] = array_keys($payload);
    $res['glossid']     = $glossid;
    return $res;
}
```

Likewise `signbank_get_connected_glossid` needs a dataset code so it queries the right table:

```php
function signbank_get_connected_glossid(PDO $pdo, int $form_data_id, string $datasetCode = 'ngt'): ?string {
    require_once __DIR__ . '/../php_api/datasets.php';
    $table = dataset_table($datasetCode);
    $stmt = $pdo->prepare("SELECT signbank FROM `$table` WHERE id = ?");
    $stmt->execute([$form_data_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $sb = trim((string)($row['signbank'] ?? ''));
    return $sb === '' ? null : $sb;
}
```

And `signbank_upload_video_for` likewise:

```php
function signbank_upload_video_for(PDO $pdo, int $form_data_id, string $localPath, string $datasetCode = 'ngt'): ?array {
    $sb = signbank_dataset_info_for($datasetCode);
    if ($sb === null) return null;
    $glossid = signbank_get_connected_glossid($pdo, $form_data_id, $datasetCode);
    if ($glossid === null) return null;
    // ... existing body unchanged ...
}
```

And `signbank_set_connection` likewise:

```php
function signbank_set_connection(PDO $pdo, int $form_data_id, ?string $glossid, string $logEntry, string $datasetCode = 'ngt'): void {
    require_once __DIR__ . '/../php_api/datasets.php';
    $table = dataset_table($datasetCode);
    $stmt = $pdo->prepare(
        "UPDATE `$table`
         SET signbank = ?,
             logboek  = CONCAT_WS('\n', NULLIF(CONVERT(logboek USING utf8mb4), ''), ?)
         WHERE id = ?"
    );
    $stmt->execute([$glossid, $logEntry, $form_data_id]);
}
```

- [ ] **Step 3: Remove `dataset_id`, `dataset_acronym` from `signbank_sync/config.php`**

Drop those two entries. The file becomes:

```php
<?php
return [
    'base_url'        => 'http://127.0.0.1:8889',
    'public_url'      => 'https://signcollect.nl/signbank_local',
    'api_key'         => 'localdev-0116a05933f4ab86ed9143ae309c5072',
    'auth_scheme'     => 'bearer',
    'timeout_seconds' => 30,
];
```

- [ ] **Step 4: PHP syntax check**

```bash
php -l /web/menu_beta/signbank_sync/client.php /web/menu_beta/signbank_sync/sync_helpers.php /web/menu_beta/signbank_sync/config.php
```

- [ ] **Step 5: Commit**

```bash
cd /web/menu_beta
git add signbank_sync/client.php signbank_sync/sync_helpers.php signbank_sync/config.php
git commit -m "signbank_sync: dataset id/acronym come from datasets.php, not config.php"
```

---

## Task 14: Update callers of the signbank sync helpers

**Files:**
- Modify: `php_api/glosses_save.php` (resolve the TODO from Task 7)
- Modify: `php_api/upload_video.php` (resolve the TODO from Task 10)
- Modify: `signbank_sync/broadcast_gloss.php`
- Modify: `signbank_sync/delete_gloss.php`
- Modify: `signbank_sync/fetch_gloss.php`
- Modify: `signbank_sync/force_push.php`
- Modify: `signbank_sync/force_pull.php`

- [ ] **Step 1: glosses_save.php — pass `$ds['code']`**

Replace the TODO line:

```php
$sync = signbank_auto_sync_fields($pdo, $id, $changedOnly, $ds['code']);
```

- [ ] **Step 2: upload_video.php — pass `$ds['code']`**

```php
$push = signbank_upload_video_for($pdo, $id, $dest, $ds['code']);
```

- [ ] **Step 3: broadcast_gloss.php**

After `require_session`, add:

```php
require_once __DIR__ . '/../php_api/datasets.php';
$pdo   = db();
$ds    = require_dataset($pdo, $session, $body);
$table = $ds['table'];
```

Replace every `form_data` → `` `$table` `` in this file. Where it constructs the Signbank create URL it uses `$cfg['dataset_id']` — switch to `signbank_dataset_info_for($ds['code'])`:

```php
$sb = signbank_dataset_info_for($ds['code']);
if ($sb === null) json_response(['ok'=>false, 'error'=>'dataset_not_synced'], 400);
// then build the path with $sb['id'] / $sb['acronym']
```

Pass `$ds['code']` to `signbank_set_connection` and any other sync helpers.

- [ ] **Step 4: delete_gloss.php — same pattern**

Add the dataset routing block, replace `form_data` → `` `$table` ``, use `signbank_dataset_info_for` for the dataset id in the URL, pass `$ds['code']` to `signbank_set_connection`.

- [ ] **Step 5: fetch_gloss.php — same pattern**

Add routing, swap `form_data` → `$table`, swap `$cfg['dataset_id']` → `signbank_dataset_info_for($ds['code'])['id']`.

- [ ] **Step 6: force_push.php, force_pull.php — same pattern**

Same dataset routing + table swap + `signbank_dataset_info_for` for the URL.

- [ ] **Step 7: PHP syntax check for everything**

```bash
php -l /web/menu_beta/php_api/glosses_save.php \
       /web/menu_beta/php_api/upload_video.php \
       /web/menu_beta/signbank_sync/broadcast_gloss.php \
       /web/menu_beta/signbank_sync/delete_gloss.php \
       /web/menu_beta/signbank_sync/fetch_gloss.php \
       /web/menu_beta/signbank_sync/force_push.php \
       /web/menu_beta/signbank_sync/force_pull.php
```

- [ ] **Step 8: Browser smoke test — NGT side**

As a normal NGT user:
- Edit a Signbank-connected row → confirm auto-sync still works (DevTools response includes `signbank_sync.ok=true`).
- Open the compare modal for a connected row → still loads.
- Push verschillen → still uploads a video.

- [ ] **Step 9: Commit**

```bash
cd /web/menu_beta
git add php_api/glosses_save.php php_api/upload_video.php \
        signbank_sync/broadcast_gloss.php signbank_sync/delete_gloss.php \
        signbank_sync/fetch_gloss.php signbank_sync/force_push.php \
        signbank_sync/force_pull.php
git commit -m "signbank_sync callers: thread dataset code through every endpoint"
```

---

## Task 15: Thread `dataset` through `api.js`

**Files:**
- Modify: `js/api.js`

- [ ] **Step 1: Add a module-level holder + setter**

Replace the top of `/web/menu_beta/js/api.js` with:

```js
const BASE = 'php_api';

// Set by main.js after current_user.php resolves so every request carries
// the active dataset. Falls back to 'ngt' until then.
let _dataset = 'ngt';
export function setActiveDataset(code) { _dataset = String(code || 'ngt'); }
export function getActiveDataset() { return _dataset; }
```

- [ ] **Step 2: Have `call()` append `?dataset=…` to GETs and merge `dataset` into POST bodies**

Replace `call()` and `post()`:

```js
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
```

- [ ] **Step 3: Patch `uploadVideo` (multipart) to add dataset to the FormData**

```js
uploadVideo: (id, blob) => {
  const fd = new FormData();
  fd.append('id', id);
  fd.append('dataset', _dataset);
  fd.append('file', blob, 'recording.webm');
  return call('upload_video.php', { method: 'POST', body: fd });
},
```

- [ ] **Step 4: Wire `setActiveDataset` from `main.js`**

In `/web/menu_beta/js/main.js`, change the import:

```js
import { api, setActiveDataset } from './api.js';
```

In `init()`, after `state.user = await api.currentUser();`, add:

```js
state.dataset = state.user.activeDataset || 'ngt';
setActiveDataset(state.dataset);
```

- [ ] **Step 5: Browser smoke test**

Reload the menu — every XHR in DevTools Network should carry `?dataset=ngt` (GET) or include `"dataset":"ngt"` in the body (POST). For inocencio, every request carries `lsm`.

- [ ] **Step 6: Commit**

```bash
cd /web/menu_beta
git add js/api.js js/main.js
git commit -m "api.js: thread active dataset through every request"
```

---

## Task 16: Dataset switcher UI

**Files:**
- Modify: `index.html` (add the selector markup)
- Modify: `js/main.js` (render + handle change)
- Modify: `css/style.css` or wherever existing toggles live (style the selector to match the Signio/Signbank toggle)

- [ ] **Step 1: Find where the Signio/Signbank toggle lives**

```bash
grep "signio\|signbank" /web/menu_beta/index.html | head -10
grep "renderContextToggle\|context-toggle" /web/menu_beta/js/main.js | head -10
```

- [ ] **Step 2: Add the selector to `index.html`**

Insert a `<div id="datasetSwitcher" class="dataset-switcher" hidden></div>` next to the Signio/Signbank toggle (e.g. in the same header row).

- [ ] **Step 3: Render the switcher conditionally in `main.js`**

In `init()`, after `setActiveDataset(state.dataset);`, add:

```js
renderDatasetSwitcher();
```

And add this function (near the existing context-toggle render):

```js
function renderDatasetSwitcher() {
  const wrap = $('#datasetSwitcher');
  if (!wrap) return;
  const datasets = state.user.datasets || [];
  if (datasets.length <= 1) { wrap.hidden = true; return; }
  wrap.hidden = false;
  wrap.replaceChildren();
  datasets.forEach(d => {
    const btn = el('button', {
      type: 'button',
      class: 'ds-btn' + (d.code === state.dataset ? ' active' : ''),
      onclick: () => onDatasetChange(d.code),
    }, d.label);
    wrap.appendChild(btn);
  });
}

async function onDatasetChange(code) {
  if (code === state.dataset) return;
  state.dataset = code;
  setActiveDataset(code);
  localStorage.setItem('menu_beta.dataset', code);
  state.page = 1;
  renderDatasetSwitcher();
  await loadList();
}
```

- [ ] **Step 4: Persist the last-chosen dataset across reloads**

At the top of `init()` (before `await api.currentUser()`), pre-seed from localStorage so the very first request carries the user's last choice (assuming server confirms it's still allowed):

```js
const saved = localStorage.getItem('menu_beta.dataset');
if (saved) setActiveDataset(saved);  // request will be 403'd if no longer allowed
```

After `currentUser()` returns, reconcile:

```js
const allowedCodes = (state.user.datasets || []).map(d => d.code);
state.dataset = (saved && allowedCodes.includes(saved)) ? saved : (state.user.activeDataset || 'ngt');
setActiveDataset(state.dataset);
```

- [ ] **Step 5: CSS — style the switcher to match the existing context toggle**

Open `css/style.css`, find `.context-toggle` (or similar), and add a sibling rule:

```css
.dataset-switcher { display:inline-flex; gap:4px; margin-left:8px; }
.dataset-switcher .ds-btn { padding:4px 10px; border:1px solid var(--border); background:transparent; cursor:pointer; border-radius:4px; }
.dataset-switcher .ds-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); }
```

- [ ] **Step 6: Browser smoke test**

- Normal NGT user: switcher hidden (only one allowed dataset).
- inocencio: switcher hidden too (only one allowed dataset).
- Manually update one test user via SQL to have `allowed_datasets=JSON_ARRAY('ngt','lsm')` and log in → switcher shows NGT|LSM, clicking flips the list and re-issues every request with the new dataset.

- [ ] **Step 7: Commit**

```bash
cd /web/menu_beta
git add index.html js/main.js css/style.css
git commit -m "ui: dataset switcher (hidden when user has only one dataset)"
```

---

## Task 17: End-to-end smoke test

No code changes — manual checklist.

- [ ] **Step 1: As a normal NGT user**, run through:
  - List loads (no errors)
  - Save a field on a row — confirm auto-sync to Signbank still works
  - Record + upload a selfie video — confirm Signbank receives it
  - Open compare modal — confirm Signbank fields render
  - Push verschillen — confirm video + fields succeed
  - Hide a studio video — confirm it disappears

- [ ] **Step 2: As `inocencio`**, run through:
  - List loads (empty, no errors)
  - Switcher is hidden (only LSM allowed)
  - Signio/Signbank toggle still works within LSM
  - Create a new gloss → confirm it shows in the list (after switching to the matching extern view)
  - Save a field on the new gloss → no Signbank sync (because `signbank_dataset_id` is null) — confirm response `signbank_sync` is null and the menu shows no error

- [ ] **Step 3: Forbidden dataset check**

```bash
curl -sS -X POST -b "sessionObject=<copy from normal user's browser>" \
     -H "Content-Type: application/json" \
     -d '{"dataset":"lsm","search":""}' \
     https://signcollect.nl/menu_beta/php_api/glosses_list.php \
  | head -c 200
```

Expected: `{"error":"forbidden_dataset","requested":"lsm"}`. Status 403.

- [ ] **Step 4: No commit needed.** If anything failed, file a follow-up note in the spec and fix before moving on.

---

## Task 18: Create the local-Signbank LSM dataset, fill its id

**Files:**
- Modify: `php_api/datasets.php`

- [ ] **Step 1: Create the dataset via Django shell**

```bash
cd /home/gomer/Global-signbank
/home/gomer/signbank-venv/bin/python bin/develop.py shell <<'PY'
from signbank.dictionary.models import Dataset, Language
nl = Language.objects.get(language_code_2char='nl')
en = Language.objects.get(language_code_2char='en')
ds, created = Dataset.objects.get_or_create(
    acronym='LSM',
    defaults={'name': 'LSM (local)', 'description': 'Lengua de Señas Mexicana — local-only test dataset', 'is_public': False},
)
ds.translation_languages.set([nl, en])
ds.save()
print('LSM dataset id:', ds.id, 'created:', created)
PY
```

Note the printed `id`.

- [ ] **Step 2: Fill the id into the registry**

Edit `/web/menu_beta/php_api/datasets.php`:

```php
'lsm' => [
    'code'                => 'lsm',
    'label'               => 'LSM',
    'table'               => 'lsm_data',
    'matched_zog_clause'  => "{mt}.zOg = 'lsm'",
    'signbank_dataset_id' => 3,            // ← from previous step
    'signbank_acronym'    => 'LSM',
],
```

- [ ] **Step 3: Smoke test as inocencio**

Edit a Signbank-connected LSM row (you'll need to first broadcast one). Confirm `signbank_sync.ok=true` in the response.

- [ ] **Step 4: Commit**

```bash
cd /web/menu_beta
git add php_api/datasets.php
git commit -m "datasets: wire local-Signbank LSM dataset id into registry"
```

---

## Done

At this point: NGT users see no UI change. inocencio defaults to LSM. The framework supports adding more datasets by appending one entry to `datasets.php` plus running `CREATE TABLE <name> LIKE form_data` and assigning users via SQL.

**Follow-ups (out of scope for this plan):**

- The external studio/recording pipeline that INSERTs `matched_transcriptions` rows must be taught to set `zOg='lsm'` for LSM glosses. Until that happens, LSM matched_transcriptions stay empty.
- Admin UI for managing user→dataset assignments. For now, set via SQL.
- LSM-specific UI niceties (theme color, badge). Defer until inocencio is actively using it.
