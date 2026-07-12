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

## Source Locations

- Local source: `G:\lunara-backups\work\lunara-core`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-core`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Verification

- Run `php tests/core-lifecycle-regression.php`.
- Run `php tests/debrief-contract-regression.php`.
- Run `php tests/debrief-studio-regression.php`.
- Run PHP lint on `lunara-core.php`.
- Confirm the WordPress plugins screen shows `Lunara Core` active.
- Confirm public Review routes and admin Review edit screens still load.
- Flush rewrite rules after activation/deactivation tests only in a controlled environment.
