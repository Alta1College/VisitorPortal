# Phase 2: Organisation configuration

Status: **for review**. Branch `feature/phase-2-organisation-config`.

Covers the brief's Phase 2 (organisation model/settings, web branding GUI, organisation → site
inheritance, timezone and locale, retention configuration) plus the audit-trail foundation moved
forward from Phase 9 (assessment §12).

## What an administrator gets

| Admin page | Permission | Contents |
|---|---|---|
| Organisation settings | `Manage:OrganisationSettings` | Identity (name, contact email/phone, address), branding (light/dark/email/badge logos, five colour tokens, badge accent), visitor wording (welcome heading/message, sign-in/out wording, instructions, confirmations, welcome-screen text), default language, retention periods |
| Site settings | `Manage:SiteSettings` | Per-site overrides for contact details, logos, badge logo/accent, welcome heading/message and visitor instructions. Empty = follow the organisation |
| Audit log | `ViewAny:AuditEvent` | Read-only list and detail view, filterable by action, result, site and date |

The `admin` role receives all three permissions, through the permission sync for new installs
and through a migration for existing ones.

## How it works

- **Overlay, not replacement.** `app_settings` holds only values an administrator changed. At boot
  (`booting` callback, before any provider boots) `SettingsRepository::applyOverlay()` writes them
  over the matching `config()` keys, so the 31 upstream `config('branding.*')` /
  `config('privacy.*')` call sites work unchanged. Env values remain the install defaults; clearing
  a field reverts to them, and saving a default unchanged does not pin it.
- **Allow-list.** Only keys in `SettingsRegistry` can be stored. Secrets and infrastructure
  settings remain environment-only.
- **Inheritance.** `site_settings` holds per-site overrides only.
  `app(SettingsRepository::class)->forSite($site)->get($key)` resolves site → organisation → env.
  Nothing is copied into sites. Kiosk configuration (the third level) arrives with kiosks in Phase 5.
- **Caching.** Values are cached forever and invalidated on save. Queue workers re-apply the
  overlay before each job if a save happened since they started (cache version key).
- **Design tokens.** `/brand.css` serves `--brand-primary`, `--brand-secondary`, `--brand-accent`,
  `--brand-foreground`, `--brand-background` from validated `#rrggbb` values only (values are
  re-validated on output, so a tampered row cannot inject CSS). The admin panel loads it and uses
  the primary colour as its Filament primary. Fork layouts (kiosk, mobile) consume the variables;
  upstream Tailwind classes are untouched.
- **Portal theme (follow-up).** The same stylesheet maps the colours onto upstream's daisyUI theme
  variables, and the portal, login/guest and welcome-screen layouts load it (one line each):
  primary, secondary and accent apply in every theme. In dark themes, each is mixed with white
  until it reaches 4.5:1 contrast on the dark surface. Button text (`--color-*-content`) is
  black or white, whichever contrasts more. Foreground and background apply to the light theme
  only, and only when they reach 4.5:1 together; otherwise both are ignored. In the light theme,
  the brand colours are used exactly as entered, so a very light primary makes light text links.
- **Logos** are raster only (PNG/JPEG/WebP; SVG is refused to avoid script in images), validated
  and metadata-stripped by upstream's `SafeRasterImage`, stored on the `public` disk under
  `branding/`. Replaced files are deleted; nothing outside `branding/` is ever removed.
- **Audit trail.** `audit_events` is append-only (the model refuses update/delete). Recorded so
  far: organisation and site settings changes (with old/new values), site create/update/delete,
  visit-retention purge outcomes, and audit purges. IP addresses are stored as a keyed hash.
  Recording never throws, so an audit failure cannot undo the action. Expired events are removed by
  `visitorportal:audit:purge` (daily, 03:40) after `organisation.audit_retention_days`
  (default 1095, minimum 30).

## Decisions made in this phase

| Decision | Reason |
|---|---|
| **Timezone stays install-time** (`APP_TIMEZONE`), shown read-only in the GUI | Every timestamp is stored in the app timezone. Changing it after data exists would shift all visit times. New sites now default to it instead of a hard-coded `Europe/Berlin`. Per-site display in each site's timezone is not done; `sites.timezone` is still stored but unused upstream. |
| Default language is a setting; no `en_AU` yet | `browserLocale()` now falls back to the organisation default. `en_AU.json` is added only when wording actually differs (CLAUDE.md). |
| Favicon not configurable yet | Upstream's favicon partial hard-codes file paths; changing it means another upstream edit. Deferred until the public layouts exist (Phase 5). |
| Audit table has no foreign keys to users/sites | History must survive deletion of the user or site it mentions. |
| Audit append-only enforced in the application, not by DB triggers | The retention purge must still delete expired rows. Revisit DB-level protection in the Phase 11 security review. |
| Visitor-wording settings are stored now, consumed later | The kiosk and mobile screens that show them are Phases 5–6. |

## Not in this phase

Visitor types, forms and policies (Phase 3); kiosk-level configuration (Phase 5); auditing of
check-in/out and visitor changes (with the public command layer, Phases 4–5); printer settings
(Phase 8).

## Tests

`tests/Feature/Fork/` — 30 tests: overlay and revert, default not pinned, audit of changes,
site inheritance and isolation, allow-list enforcement, worker refresh, image storage and safe
cleanup, locale fallback, site timezone default, brand CSS output and injection resistance,
admin page save/validation/upload (PNG accepted, SVG refused), permission checks, audit
append-only, failure isolation, site and retention auditing, audit purge and dry run, audit log
access.
