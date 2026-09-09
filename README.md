# signCollect-v2 — menu, gloss management and admin interface

The main menu of the SignCollect web interface, and the gloss editor behind it.

## What it does

`index.html` is the page most users land on: a paginated, filterable table of
glosses (sign entries) read from the `form_data` table, with inline editing,
senses and phonology fields, label and thema assignment, self-capture video
recording in the browser, and the linked studio videos found through
`matched_transcriptions`. The hamburger menu on that page is the estate's
navigation — it hardcodes links to `/zin/`, `/videoFix/`, `/studioIndex/`,
`/hh/`, `mocap.signcollect.nl` and the rest, which is why the deployed path
matters (see below).

Around the table sit the admin pages: `labels_add.html` (label catalogue),
`batch_add.html` (bulk gloss creation), `users.html` (user accounts and
roles), `activity.html` (per-user activity from the logbook), and
`signbank.php` (the Signbank connector's admin page — the only page gated
server-side, because it shows the state of a credential).

The interface is dataset-aware. `php_api/datasets.php` is a hardcoded registry
of datasets — currently `ngt` (`form_data`) and `lsm` — and NGT additionally
has a Signio/Signbank context toggle driven by the `extern` column.

`signbank_sync/` is the Signbank connector. `ecv_refresh.php` rebuilds
`glosses_transformed.json` (the ~11 MB ECV dump of every gloss in the
connected Signbank) by enumerating the dataset and fetching each gloss through
Signbank's `get_gloss_data` endpoint; `push_gloss.php`, `fetch_gloss.php` and
`delete_gloss.php` keep individual glosses in step. The dump is shared data —
`zin`, `hh`, `nmm` and this repo all read it by absolute path.

## Where it runs

The **signcollect core server** (production VPS), served at
`https://signcollect.nl/menu_beta/` from `/web/menu_beta`. The demo hosts
deploy the same tree: dev2 under `/web`, dev-1 under `/srv/signcollect/web`.

The directory name `menu_beta` is load-bearing — the estate's other interfaces
link back to `/menu_beta/...` absolutely.

## Status

**Production.** This is the live gloss-management interface.

## How to deploy it

There is no build step. It is PHP, static HTML, CSS and hand-written ES
modules, served directly by Apache.

Deployment is by `interface_deploy/scripts/repos.tsv` in
`signlab_signcollect-stack`, which maps `menu_beta` → this repo on branch
`main`. `scripts/install.sh` runs `host-bootstrap.sh` on the host, which
clones (or fetches and hard-resets) the repo straight into
`<root>/menu_beta`. Pushing to `main` and re-running the install is the whole
deploy.

On a demo host there is one extra pass: `rewrite-urls.sh` repoints the
hardcoded `signcollect.nl` URLs at the demo's own hostname, so the menu links
stay inside the isolated instance.

The `migrations/` directory is applied by hand — the deploy does not run it.

## Configuration

None of these are in git; each host supplies its own.

| file | what it is |
|---|---|
| `<root>/mysql_config.php` | database credentials, as `$servername`/`$username`/`$password`/`$database`. Every endpoint reaches it through `php_api/db.php` → `sc_path('mysql_config.php')`. On a migrated host it is the shim over `signcollect-lib`. |
| `<root>/.session_secret` | HMAC key for the `sessionObject` cookie. Absent means signatures are not required (an unmigrated host); present means they are. Dotfile on purpose — Apache denies dotfiles. |
| `signbank_sync/config.php` | Signbank base URL, dataset id and acronym. Copy `config.example.php`; gitignored. `config.production.php` is the non-secret production shape. |
| `<root>/signbank_data/.signbank_key` | the Signbank API token, set through `signbank.php` rather than edited into a PHP file. |
| `<root>/signbank_data/glosses_transformed.json` | the ECV dump. Produced by `ecv_refresh.php`, not by git. `php_api/signbank_ecv.php` also accepts the legacy location `<root>/glosses_transformed.json`. |

`<root>` is the install root — `/web` on production and dev2,
`/srv/signcollect/web` on dev-1. Nothing in this repo spells it: `sc_paths.php`
(a vendored copy of `signcollect-lib`'s resolver) answers `sc_path()`, falling
back to `/web` on a host with no library.

## Dependencies

- **MySQL** `admin_gebarenoverleg` — `form_data`, `matched_transcriptions`,
  `users`, `labels`, and the LSM tables.
- **`signlab_signcollect-lib`** at `<root>/lib` — `sc_path()`, and the
  credentials behind `mysql_config.php`. Optional: the vendored
  `sc_paths.php` degrades to the compiled `/web` default without it.
- **Signbank** (`https://signbank.cls.ru.nl`) — a third-party service. The
  connector is the only thing that talks to it; every page degrades to local
  data when the dump is missing or the key is unset.
- **`/web/login.html` and `/logout.html`** — the shared auth pages, which live
  in `interface_deploy/web_extra/`, not in this repo.
- **Media under `<root>/gebarenoverleg_media/studioFilesMini/`** and
  `<root>/uploads/lsm/` — studio and self-capture video, served over HTTP.

## Documentation

- `docs/spec.md` — the data model, endpoint by endpoint. Written before the
  rebuild and still accurate about `form_data`'s column conventions (the JSON
  arrays in `wie`, `labels`, `senses`, `control_nodig`).
- `docs/architecture/` — a 12-page architecture document, source HTML plus a
  generated PDF; see its README for how to regenerate.
- `docs/signbank-*.md` / `.patch` — findings and proposed fixes for the
  upstream Signbank API, for whoever picks that thread up.
