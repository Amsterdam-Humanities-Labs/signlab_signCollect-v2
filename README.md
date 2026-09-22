# signlab_signCollect-v2
The SignCollect main menu and gloss editor, plus the Signbank connector.

## What it does
- `index.html` is the gloss table (`form_data`). You can filter, page and edit inline: senses, phonology, labels and themes (`thema`).
- It also records self-capture video in the browser and shows linked studio videos (`matched_transcriptions`). Its menu is the navigation for all SignCollect pages.
- Admin pages: `labels_add.html`, `batch_add.html`, `users.html`, `activity.html` and `signbank.php`. Of the pages, only `signbank.php` checks the login on the server. Most PHP endpoints call `require_session()`.
- Datasets: `php_api/datasets.php` registers `ngt` (`form_data`) and `lsm`. NGT also has a Signio/Signbank switch (`extern` column).
- `signbank_sync/`: `ecv_refresh.php` rebuilds `glosses_transformed.json` (about 11 MB; zin, hh, nmm and this repo read it). `broadcast_gloss`, `fetch_gloss`, `force_push`, `force_pull` and `delete_gloss` sync one gloss at a time.

## Where it runs
- Production: core server, `/web/menu_beta`, <https://signcollect.nl/menu_beta/>. Demo hosts: `<root>/menu_beta`.
- The name `menu_beta` is fixed, because other pages link to `/menu_beta/...`.

## Status
Production.

## How to run / deploy
There is no build step (PHP, HTML, ES modules). The stack deploys `main` as `menu_beta` (`repos.tsv`); see
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
The deploy does not run `migrations/`. Apply them by hand.

## Configuration
| File | What |
|---|---|
| `<root>/mysql_config.php` | DB credentials (a shim over signcollect-lib on migrated hosts). Most endpoints use `php_api/db.php`. `batch_add.php`, `labels_add.php`, `uniqueThema.php` and `users_api.php` include it directly |
| `<root>/.session_secret` | HMAC key for the `sessionObject` cookie. Without it, signatures are not required |
| `signbank_sync/config.php` | Signbank URL, dataset id, acronym. Gitignored; copy `config.example.php`. `config.production.php` is committed, so keep secrets out of it |
| `<root>/signbank_data/.signbank_key` | Signbank API token, set through `signbank.php` |
| `<root>/signbank_data/glosses_transformed.json` | ECV dump from `ecv_refresh.php`. The old location `<root>/glosses_transformed.json` is also read |

`<root>` is `/web` or `/srv/signcollect/web`. The vendored `sc_paths.php` finds it.

## Dependencies
- MySQL `admin_gebarenoverleg` (`form_data`, `matched_transcriptions`, `users`, `labels`, LSM tables).
- [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib) at `<root>/lib`. Optional: `sc_paths.php` falls back to `/web`.
- Signbank (`https://signbank.cls.ru.nl`, third party). Only `signbank_sync/` uses it.
- Shared `/login.html` and `/logout.html` from `web_extra/` in [signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
- Media: `<root>/gebarenoverleg_media/studioFilesMini/` and `<root>/uploads/lsm/`.
- More docs: `docs/spec.md` (data model and endpoints) and `docs/architecture/` (architecture pages in HTML).
