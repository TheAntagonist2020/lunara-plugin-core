# Lunara Core

Private WordPress plugin for Lunara Film core content models and editorial tools.

## Role

This plugin is load-bearing for the live site. It registers and maintains core review/editorial structures used by the active Lunara theme and related custom code.

Do not deactivate it on the live site without first auditing theme/plugin dependencies and confirming graceful fallback behavior.

## Review Editorial Fields

- `Review Spoiler Mode` marks a Review as either spoiler-free or a full-spoiler companion.
- `Full Spoiler Review URL` and `Spoiler Link Label` bridge spoiler-free reviews to companion pieces when manual linking is preferred.
- `IMDb Title ID` lets the active theme auto-pair published full-spoiler companions with spoiler-free reviews that share the same film identity.

## Debrief Contract

Lunara Core owns the Review-side Debrief data contract. A Debrief has exactly
three ordered roles: `theme_echo`, `counter_program`, and `career_context`.
Each role uses its existing fixed ACF movie relationship and note field. The
contract normalizes the legacy `_lunara_craft_mirror` value to Career Context,
but does not write migrations or change public rendering.

The public theme may consume these hook-free helpers:

- `lunara_debrief_contract_roles()`
- `lunara_debrief_normalize_record()`
- `lunara_debrief_validate_record()`
- `lunara_debrief_get_review_record()`

## Debrief Studio

When ACF Pro is active, the Review editor presents a tabbed Debrief Studio with
one searchable canonical-film selector and one editorial reason for each fixed
role. Incomplete work can be saved normally. Changing readiness to `ready` or
`published` enforces all three films, all three reasons, unique companions, and
prevents the reviewed film from pairing with itself. The saved preview and
source-film summary use local WordPress data only.

## Private Movie Importer

Core `0.7.0` adds a private, Review-editor Movie importer beneath each of the
three Debrief Movie selectors. It checks every local `movie` post status and
both current and legacy IMDb identity keys before contacting a provider.
Published and otherwise non-draft local matches stop the remote workflow. One
existing draft can be explicitly enriched without creating a second Movie.

Remote lookup requires server-side configuration through exact constants or
environment variables. Credential values must never be stored in WordPress
options, entered into an editor form, or committed to this repository:

- `LUNARA_OMDB_API_KEY`
- `LUNARA_TMDB_API_TOKEN`

The lookup step performs zero content writes. An administrator must explicitly
confirm the candidate before Core creates or fills a draft Film Dossier. The
repository writes only blank factual fields, preserves curated values and all
non-draft Movies, rejects duplicate identities, revalidates a versioned plan
hash under an atomic IMDb identity lock before writing, and reports recoverable
partial writes without creating a second Movie on retry. It never publishes
automatically.

This foundation imports identity, title, synopsis/excerpt, release date and
year, runtime, genres, countries, content rating, original title, and TMDb ID.
Provider image paths and structured people data are normalized for later
stages, but `0.7.0` does not download media or create Person relationships.
Importer code and assets are not loaded during ordinary public requests.

## Private Review Draft Importer

Core `0.7.4` keeps the Review-owned Debrief Studio available whenever ACF Pro
and the Film Dossier post type are present, even if a later entity-graph filter
changes after Core bootstrap. The Studio now owns its ACF field-group
registration, preventing the Classic Editor from falling back to duplicate
legacy pairing inputs while Film Dossiers remain available. Newly auto-grown
draft dossiers now keep the film title without copying the Review headline's
editorial argument.

Core `0.7.3` gives Debrief Studio and the draft importer one shared rich
Pair It With preview. Saved canonical fields and retained legacy pairings can
show the poster, direct IMDb link, Oscar Ledger status, internal destination,
and editorial reason without migrating data during an editor view. The parser
also accepts the bracketed, bare-ID, full-IMDb-URL, and trailing-ID formats
commonly produced by Lunara HTML, Word, and Google Docs drafts.

Core `0.7.2` adds a draft-only Review editor importer for reference HTML files,
Word `.docx` exports, Google Docs HTML `.zip` exports, and pasted rich HTML.
Preview is read-only. Apply requires an editable saved draft, an empty
persisted body, a current REST nonce, and a clean editor with no unsaved
changes. It never publishes, calls a remote provider, or overwrites existing
Review fields.

Word and Google packages are converted locally in memory with bounded ZIP/XML
readers; no file is written to the server and no document macro, script, or
remote conversion service is executed. Legacy binary `.doc` files are not
accepted directly: save them as `.docx` or HTML first. Rich clipboard paste
from Word or Google Docs captures the browser's `text/html` representation and
uses the same parser as uploaded files.

Supported prose becomes native paragraph, heading, quote, list, separator, or
sanitized HTML blocks. The inline `LUNARA DEBRIEF` section is removed from the
article body and mapped to the three canonical Debrief roles. Published local
Movie records are resolved by IMDb identity; missing or conflicting companions
remain incomplete and editable in Debrief Studio. Unsupported reference credits
are retained in a protected import record rather than mixed into public prose.

The source hash and pending/complete import record make retries idempotent and
repairable. Shortcodes and snippets remain available elsewhere as tactical
tools, but this importer stores the lasting Review source of truth in native
WordPress blocks and structured fields.

## Debrief Census

Core `0.6.3` adds an operator-only WP-CLI census and migration dry run. Both
commands are application-data read-only: they do not update Review content,
ACF fields, legacy metadata, post status, options, or remote services. Normal
WordPress reads may warm object or metadata caches; the commands never clear
those caches or use them as migration state.

```bash
wp lunara debrief census --post-status=any --format=json
wp lunara debrief migrate --dry-run --post-status=any --format=json
```

The census reports deterministic safety buckets and every canonical Movie
candidate for each legacy IMDb reference. The dry run produces the same
evidence plus a stable plan hash for later reconciliation.

Core `0.6.4` adds two more WP-CLI-only, read-only operator surfaces:

```bash
wp lunara debrief reconcile --post-status=any --format=json
wp lunara debrief suggest --review-id=30263 --format=json
wp lunara debrief suggest --review-id=30263 --role=career_context --limit=6 --format=json
```

`reconcile` turns the census into deterministic queues for unique missing
Movies, every source or companion reference occurrence, legacy conflicts,
auto-migratable Reviews, and shortcode/block retirement review. It carries the
source plan hash and adds a stable pack hash.

`suggest` requires one explicit Review ID. It considers at most 200 published,
publicly renderable local Movies that share a supported source relationship and
returns at most 12 candidates per role.
Career Context may rank shared directors, principal cast, and studios, with
every score contribution returned as structured evidence. Theme Echo and
Counter-Program deliberately return `insufficient_evidence` until controlled
theme and tone metadata exists. Suggestions never write a film selection or
author the editor-owned pairing reason.

There is no Debrief reconciliation apply command in this release. None of
these Debrief CLI services updates
Review content, ACF fields, legacy metadata, post status, taxonomy terms,
options, transients, or remote services. They are not loaded during public or
editor WordPress requests.

## Source Locations

- Local source: `G:\lunara-backups\work\lunara-core`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-core`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Verification

- Run `php tests/core-lifecycle-regression.php`.
- Run `php tests/debrief-contract-regression.php`.
- Run `php tests/debrief-migration-regression.php`.
- Run `php tests/debrief-reconciliation-regression.php`.
- Run `php tests/debrief-suggestions-regression.php`.
- Run `php tests/debrief-studio-regression.php`.
- Run `php tests/graph-growth-identity-regression.php`.
- Run `php tests/movie-entity-schema-regression.php`.
- Run `php tests/movie-identity-lock-regression.php`.
- Run `php tests/movie-import-contract-regression.php`.
- Run `php tests/movie-importer-provider-regression.php`.
- Run `php tests/movie-importer-security-regression.php`.
- Run `php tests/movie-importer-regression.php`.
- Run `php tests/movie-import-admin-regression.php`.
- Run `php tests/review-draft-parser-regression.php`.
- Run `php tests/review-draft-document-regression.php`.
- Run `php tests/review-draft-import-admin-regression.php`.
- Run PHP lint on `lunara-core.php`.
- Confirm the WordPress plugins screen shows `Lunara Core` active.
- Confirm public Review routes and admin Review edit screens still load.
- Flush rewrite rules after activation/deactivation tests only in a controlled environment.
