# Lunara Core

Private WordPress plugin for Lunara Film core content models and editorial tools.

## Role

This plugin is load-bearing for the live site. It registers and maintains core review/editorial structures used by the active Lunara theme and related custom code.

Do not deactivate it on the live site without first auditing theme/plugin dependencies and confirming graceful fallback behavior.

## Review Editorial Fields

- `Review Spoiler Mode` marks a Review as either spoiler-free or a full-spoiler companion.
- `Full Spoiler Review URL` and `Spoiler Link Label` bridge spoiler-free reviews to companion pieces when manual linking is preferred.
- `IMDb Title ID` lets the active theme auto-pair published full-spoiler companions with spoiler-free reviews that share the same film identity.

## Source Locations

- Local source: `G:\lunara-backups\work\lunara-core`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-core`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Verification

- Run PHP lint on `lunara-core.php`.
- Confirm the WordPress plugins screen shows `Lunara Core` active.
- Confirm public Review routes and admin Review edit screens still load.
- Flush rewrite rules after activation/deactivation tests only in a controlled environment.
