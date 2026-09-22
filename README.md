# signlab_signCollect-v2
The SignCollect main menu and gloss editor, plus the Signbank connector.

## What it does
- `index.html`: filterable, paginated gloss table (`form_data`) with inline editing, senses, phonology, labels/thema, in-browser self-capture video and linked studio videos (`matched_transcriptions`). Its menu is the estate's navigation.
- Admin pages: `labels_add.html`, `batch_add.html`, `users.html`, `activity.html`, `signbank.php` (the only page gated server-side).
- Dataset-aware: `php_api/datasets.php` registers `ngt` (`form_data`) and `lsm`; NGT also has a Signio/Signbank toggle (`extern` column).
- `signbank_sync/`: `ecv_refresh.php` rebuilds `glosses_transformed.json` (~11 MB, read by zin, hh, nmm and this repo); `broadcast_gloss`, `fetch_gloss`, `force_push`, `force_pull`, `delete_gloss` sync single glosses.

## Where it runs
- Production: core server, `/web/menu_beta`, <https://signcollect.nl/menu_beta/>. Demo hosts: `<root>/menu_beta`.
- The name `menu_beta` is fixed: other interfaces link to `/menu_beta/...`.

## Status
Production.

## How to run / deploy
No build step (PHP, HTML, ES modules). Deployed from `main` by the stack's `repos.tsv` (`menu_beta`); see
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
`migrations/` is applied by hand; the deploy does not run it.

## Configuration
| File | What |
|---|---|
| `<root>/mysql_config.php` | DB credentials (shim over signcollect-lib on migrated hosts). Most endpoints use `php_api/db.php`; `batch_add.php`, `labels_add.php`, `uniqueThema.php`, `users_api.php` include it directly |
| `<root>/.session_secret` | HMAC key for the `sessionObject` cookie; absent = signatures not required |
| `signbank_sync/config.php` | Signbank URL, dataset id, acronym; gitignored, copy `config.example.php`. `config.production.php` is committed: keep secrets out of it |
| `<root>/signbank_data/.signbank_key` | Signbank API token, set via `signbank.php` |
| `<root>/signbank_data/glosses_transformed.json` | ECV dump from `ecv_refresh.php` (legacy location `<root>/glosses_transformed.json` also read) |

`<root>` is `/web` or `/srv/signcollect/web`; the vendored `sc_paths.php` resolves it.

## Dependencies
- MySQL `admin_gebarenoverleg` (`form_data`, `matched_transcriptions`, `users`, `labels`, LSM tables).
- `signlab_signcollect-lib` at `<root>/lib` (optional; `sc_paths.php` falls back to `/web`).
- Signbank (`https://signbank.cls.ru.nl`, third party), used only by `signbank_sync/`.
- Shared `/login.html`, `/logout.html` from the stack's `web_extra/`.
- Media: `<root>/gebarenoverleg_media/studioFilesMini/`, `<root>/uploads/lsm/`.
- Docs: `docs/spec.md` (data model and endpoints), `docs/architecture/` (architecture HTML).
