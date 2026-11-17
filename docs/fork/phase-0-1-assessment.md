# Phase 0–1 assessment

Fork: `Alta1College/VisitorPortal` · Upstream: `P0etInc0de/VisitorPortal` v0.9.1 · Assessed 2026-11-17

Status: **for review**. No application code has been changed.

---

## 1. Baseline build and tests

| Item | Result |
|---|---|
| Fork tree vs upstream `v0.9.1` | Identical (`git diff 116ebd2 21a67d1` is empty) |
| Upstream remote | Added locally; procedure in [`upstream-sync.md`](upstream-sync.md) |
| PHP | 8.4.27 (the version `composer.json` pins: `~8.4.27`) |
| Database | MariaDB 10.11 (production compose uses 11.4; see note) |
| Composer install | OK |
| `npm ci` + `npm run build` | OK |
| `php artisan test` | **413 passed, 0 failed** (2,669 assertions, 36 s) |
| Playwright e2e (`tests/e2e`, 4 specs) | Not run — needs the full Docker stack |
| Gotenberg badge rendering | Not run — needs the Gotenberg container |

**No pre-existing test failures.**

Notes:

- The assessment workspace could not pull Docker Hub images, so the stack was run natively. Before
  Phase 2 starts, re-run the suite on the real Docker stack (MariaDB 11.4, Gotenberg) on Alta1
  infrastructure and run the e2e specs. Expected to pass; recorded here as unverified.
- Composer warns `tests/Feature/AdminPanel/AdminAdministerUserTest.php` does not comply with PSR-4
  and is skipped by the autoloader. PHPUnit still discovers it by path, so it runs. Cosmetic; worth
  reporting upstream.

## 2. Dependency vulnerabilities

### PHP (checked against FriendsOfPHP/security-advisories, 13 Nov 2026)

`composer audit` could not reach Packagist from the assessment workspace, so `composer.lock` was
checked directly against the advisory database. **16 advisories, all in Symfony components, all
fixed by Symfony patch releases** within the current constraints:

| Package | Locked | Fixed in | Advisories | Relevance to us |
|---|---|---|---|---|
| symfony/http-foundation | 7.4.21 | 7.4.23 | 4 (incl. trusted-proxy bypass, client-chosen host in generated URLs) | **High** — we will issue signed sign-out links and run behind a reverse proxy |
| symfony/http-kernel | 7.4.12 | 7.4.23 | 1 (forwarded headers set URL host) | **High** — same reason |
| symfony/routing | 7.4.21 | 7.4.23 | 1 | Medium |
| symfony/html-sanitizer | 8.1.8 | 8.1.11 | 3 | Medium — matters once admins edit rich-text policy/welcome text |
| symfony/http-client | 7.4.9 | 7.4.23 | 2 | Low–medium — Entra/Graph calls later |
| symfony/serializer | 7.4.10 | 7.4.23 | 3 | Low — not used for request input today |
| symfony/mime | 7.4.12 | 7.4.22 | 1 (email header injection) | Medium — visitor-supplied names go into emails |
| symfony/yaml | 7.4.21 | 7.4.23 | 1 (parse DoS) | Low |

**Action (Phase 1):** `composer update "symfony/*" --with-dependencies`, re-run the suite, commit
the lock change. Check whether upstream has already released this before doing it ourselves.

### JavaScript

- Production dependencies (`npm audit --omit=dev`): **0 vulnerabilities**.
- Dev dependencies: 1 high — `follow-redirects 1.16.1` via `axios` (credential headers on
  redirect). Build-time only; fix with `npm audit fix`.

## 3. Production baseline review

| Area | Finding |
|---|---|
| Secrets | Prod compose requires `DB_PASSWORD`, `DB_ROOT_PASSWORD` etc. via `${VAR:?}` guards. No secrets, keys or `.env` files are tracked in Git. |
| Images | Prod runs a prebuilt `VISITORPORTAL_IMAGE`; dev builds from `docker/apache/Dockerfile` (php:8.4.27-apache). |
| DB healthcheck | Passes the root password on the command line (`-p$MARIADB_ROOT_PASSWORD`), visible in the container's process list. Minor; switch to `healthcheck.sh --connect` from the MariaDB image. |
| Reverse proxy | `TRUSTED_PROXIES` read from env. Must be set to the exact proxy IP at Alta1 — not `*` — given the Symfony advisories above. |
| Sessions | `SESSION_SECURE_COOKIE` defaults to unset; `SESSION_ENCRYPT` false; SameSite `lax`. Set secure cookies in production. |
| Security headers | `SecurityHeaders` middleware appended to every web request. |
| Rate limits | Login, password reset and 2FA only. No limiter for anything public, because nothing is public yet. |
| Logging | File-based channels (`web`, `mail`, `db`) plus `SecurityEventLogger`. **No database audit trail** — new work. |
| Backup/restore | `docs/operations.md` exists; a restore test on Alta1 infrastructure is still to do (Phase 1, on-site). |
| Updater | `update.sh` pulls new demo images; production updates are image-tag based (`docs/release-artifacts.md`). |

## 4. Authentication and authorisation boundaries

- **Every route except `/`, `/lang`, `/up` and the login/OIDC routes is behind `auth` + `verified`.**
  Even welcome monitors require a logged-in account (a "welcome monitor" role).
- Authorisation is policy-based (`app/Policies`, 8 policies) on top of spatie/laravel-permission
  with Filament Shield. Permissions are site-, department- and ownership-scoped
  (`Visitor::scopeVisibleTo`, `User::assignedSiteIds`).
- MFA is enforced by middleware (`EnsureRequiredMfaIsConfigured`, `EnsureAdminPanelMfa`), with
  step-up confirmation for sensitive actions.
- OIDC is generic, with JIT provisioning modes, domain allow-list and role mapping
  (`config/sso.php`). Entra ID works through this without code changes.

**Implication:** there is no existing anonymous surface to extend, which is good. The public
visitor surface will be a new, separate route group with its own middleware stack. It never
reuses `auth` routes or controllers.

## 5. Domain model map

```
sites ──< users (home site_id) >──< site_user (extra sites)
  │         │  └─ department_id → departments
  │         │  └─ roles/permissions (spatie)
  │         │  └─ user_identities (OIDC)
  │
  ├──< visits ── host_user_id (NOT NULL) → users
  │      │      substitute_user_id, created_by_user_id → users
  │      │      status: planned | completed | canceled
  │      │      is_walk_in, is_confidential, retention_hold_*
  │      │      recurring_visit_series_id → recurring_visit_series
  │      │
  │      └──< visit_visitor (pivot = one person's attendance)
  │              checked_in_at / checked_out_at (+ _by_user_id)
  │              badge_printed_at, notes
  │              UNIQUE(visit_id, visitor_id)
  │
  ├──< monitors ──< monitor_slides     (welcome screens)
  └──< recurring_visit_series ──< recurring_visit_series_visitor

visitors   name, first_name, salutation, title, email(idx), phone(idx), company, notes
notifications (Laravel database notifications)
data_retention_runs (purge command run log)
```

Key facts for the extension:

1. **Attendance lives on the pivot.** "On site now" = `visit_visitor.checked_in_at IS NOT NULL AND
   checked_out_at IS NULL`. The scheduled `CompleteFinishedVisits` task never completes a visit
   while anyone on it is still checked in, so this is a sound basis for the active register and
   the emergency snapshot.
2. **Every visit needs a host who is a local `users` row** (`host_user_id` NOT NULL, FK, restrict).
3. **Check-in/out logic is already in a service** — `App\Services\VisitActionService`
   (`checkInParticipant`, `checkOutParticipant`, `printBadge`, `cancelCheckIn`). It requires a
   non-null `User $actionBy`.
4. **Walk-in creation is not in a service.** It lives in private methods of the Livewire
   component `App\Livewire\Portal\CheckInOutBoard` (`resolveWalkInVisitor`, `createWalkInVisit`).
   It silently reuses an existing visitor matched by email, which is fine for staff but not for
   the public.
5. **Host notification** on check-in: a database notification plus a mail notification
   (`GuestCheckedInMailNotification`, queued via `ShouldQueue`). A mail failure therefore lands in
   `failed_jobs` and does not roll back the check-in — this already meets brief §25.
6. Visitor contact rules: `App\Support\VisitorContactRequirement`.
7. Source strings are **German keys** translated through `lang/en.json` (e.g.
   `__('Besucherausweis_')`). New fork code should follow the same convention so translations
   stay in one system; we add `lang/en_AU.json` only where wording genuinely differs.

## 6. Where the organisation/configuration layer goes

Upstream already reads branding, retention and reception settings through `config()`:
`config/branding.php` (31 call sites), `config/privacy.php` (9), `config/reception.php`, all
env-backed.

**Proposal: a database settings overlay.**

- New table `app_settings` (key, JSON value, updated_by, timestamps) and a `SettingsRepository`
  with a cache.
- A new service provider runs at boot and `config()->set(...)`s stored values over the env
  defaults for an allow-listed set of keys (`branding.*`, `privacy.*_retention_days`,
  `reception.*`, `app.timezone`, `app.locale`).
- **Every existing upstream call site keeps working unchanged.** Env values become the
  install-time defaults; the web GUI overrides them.
- A new Filament page "Organisation settings" (identity, branding, visitor-experience text,
  retention) edits the table, with audit entries.
- Site inheritance: a new `site_settings` table (site_id, key, value). Lookup order is
  site → organisation → env default, via `Settings::forSite($site)->get('welcome_heading')`. No
  copying of organisation values into each site.
- Design tokens: an organisation stylesheet rendered from settings (`/brand.css`, cached and
  versioned) exposing `--brand-primary` etc. Fork layouts consume the variables; upstream Tailwind
  classes are untouched.
- Upstream edits needed: register one provider; replace the hard-coded `Europe/Berlin` site
  default with `config('app.timezone')` (3 places — worth offering upstream).

## 7. How public visitor commands use the visit domain safely

```
routes/public.php  (new; middleware: web-session, CSRF, throttle:public-*, kiosk/QR resolver)
   └── App\PublicVisit\Http\*        thin controllers / Livewire steps, no Eloquent IDs exposed
         └── App\PublicVisit\Commands\RegisterArrival   (single implementation for kiosk + QR)
         └── App\PublicVisit\Commands\RecordDeparture
               └── App\Services\WalkInVisitService      (extracted from CheckInOutBoard)
               └── App\Services\VisitActionService      (upstream, reused)
               └── App\Audit\AuditLogger
```

Specific changes:

1. **Extract** `resolveWalkInVisitor` / `createWalkInVisit` from `CheckInOutBoard` into
   `WalkInVisitService`, with the Livewire component calling it. A small, behaviour-preserving
   upstream patch — and a good one to offer upstream.
2. **Actor for public check-ins.** `VisitActionService` needs a `User`. Options:
   (a) a seeded, login-disabled system user "Kiosk" per installation; or (b) make `$actionBy`
   nullable and add `checked_in_via` (`staff|kiosk|qr|email_link`) plus `kiosk_id` to the pivot
   through a new fork migration. **Recommend (b)** — it gives honest audit data. It is a
   two-line signature change upstream would plausibly accept.
3. **Public operations never accept database IDs.** Kiosks are resolved from a device cookie; QR
   from an opaque site token; sign-out from a hashed one-time token. Hosts are chosen through a
   short-lived opaque option token, not `users.id`.
4. **Visitor matching** for returning visitors goes through a dedicated method that matches on an
   exact normalised email or phone and returns only a confirmation prompt. It never reuses
   `scopeVisibleTo` or the staff walk-in matching.
5. **Idempotency:** a client-generated submission key stored with a unique index on a new
   `public_submissions` table, plus a guard against a second active attendance for the same
   visitor at the same site.

**Hosts from Entra:** because `host_user_id` must be a local user, the Entra host provider should
**sync directory staff into `users`** as host-only accounts (no roles, cannot log in until OIDC
links them, flagged `directory_source = entra`). This keeps the upstream foreign key,
notifications and policies intact, and the local cache means reception keeps working if Graph is
down. A separate `hosts` table would mean rewriting every upstream query that joins `host`.

## 8. Badges and the 62 × 100 mm label

- Single Blade template `resources/views/pdf/visitor_badge.blade.php` (424 lines, designs
  `standard` and `photo_qr`), rendered to PDF by **Gotenberg** (a Chromium-based container).
- Size is fixed to an ID-1 card (85.6 × 53.98 mm) by constants in `App\Support\BadgePdfDimensions`,
  carefully tuned to Chromium's point rounding.
- **Smallest test for 62 × 100 mm:** add a `BadgeFormat` value (card-id1, label-62x100) with the
  page and request dimensions, pass it into the controller's `paperSize()` and the template's CSS
  size, then render one sample through Gotenberg and measure the MediaBox. That's about half a day.
  No print service is involved.
- Per your direction, printing is a nice-to-have. At small QR-only sites the deliverable is an
  **on-phone sign-in confirmation screen** instead of a badge. That screen is a Phase 6 item, not
  a printing item.

## 9. MariaDB

- Raw SQL in the codebase is limited to portable `CASE WHEN` ordering and `whereRaw('1 = 0')`.
  No JSON functions, full-text search, `GROUP_CONCAT` or MariaDB-only syntax.
- **No requirement in the brief needs PostgreSQL.**
- One MariaDB-specific design point: there are no partial unique indexes, so "one active
  attendance per visitor per site" uses a generated column (e.g. `active_key` = visitor+site when
  checked out IS NULL, else NULL) with a unique index, plus the idempotency table above.
  The generated-column approach also works on MySQL 8.

## 10. Proposed change map, Phases 2–5

New tables (all additive; no upstream column is altered except where noted):

| Phase | Table | Purpose |
|---|---|---|
| 2 | `app_settings` | Organisation settings overlay |
| 2 | `site_settings` | Site overrides |
| 2 | `audit_events` | Append-only audit trail (moved forward from Phase 9) |
| 3 | `visitor_types` | Configurable types, active flag, ordering |
| 3 | `visitor_type_site` | Availability per site |
| 3 | `form_fields` | Field definitions: key, type, flags (on-badge, on-roll-call, sensitive, needs-verification, has-expiry), conditions JSON |
| 3 | `form_field_assignments` | Field ↔ visitor type / site, required, order |
| 3 | `visit_responses` | Answers per attendance (visit_id, visitor_id, field_id, value, file path) |
| 3 | `policies`, `policy_versions` | Versioned policy text (immutable versions) |
| 3 | `policy_assignments` | Policy ↔ org / site / visitor type |
| 3 | `policy_acceptances` | visit, visitor, policy_version_id, accepted_at, channel |
| 4 | `public_submissions` | Idempotency keys |
| 4 | `visit_visitor` + columns | `visitor_type_id`, `checked_in_via`, `kiosk_id`, `active_key` (generated) — **upstream table, additive columns** |
| 5 | `kiosks` | Site-bound devices: name, enrolment code hash, device secret hash, last_seen, revoked_at |
| 5 | `users` + columns | `directory_source`, `directory_id`, `is_host_only` — **upstream table, additive columns** |

New code locations:

| Path | Contents |
|---|---|
| `app/Settings/` | Repository, config overlay provider, brand CSS controller |
| `app/Audit/` | `AuditLogger`, `AuditEvent` model, Filament viewer |
| `app/VisitorWorkflow/` | Visitor types, form engine, policy versioning |
| `app/PublicVisit/` | Commands, public controllers/Livewire, rate limiters, token resolvers |
| `app/Kiosk/` | Kiosk model, enrolment, device middleware |
| `app/HostDirectory/` | `HostDirectory` contract, `LocalHostDirectory`, `EntraHostDirectory` (Graph sync job) |
| `app/Filament/Resources/` | VisitorTypes, FormFields, Policies, Kiosks, AuditEvents; Organisation settings page |
| `routes/public.php` | Public route group (registered from a fork provider) |
| `resources/views/public/` | Kiosk and mobile layouts (touch-first) |

Upstream files touched (to be tracked in `docs/fork/upstream-patches.md`):

1. `bootstrap/app.php` or `bootstrap/providers.php` — register fork providers and the public route file.
2. `app/Livewire/Portal/CheckInOutBoard.php` — delegate walk-in creation to `WalkInVisitService`.
3. `app/Services/VisitActionService.php` — nullable actor and a channel parameter.
4. `Site` model, `SiteForm`, sites migration default — timezone default from config.
5. `resources/views/pdf/visitor_badge.blade.php` + `BadgePdfDimensions` — badge format (Phase 8, optional).

## 11. Revised estimate

Assumes one experienced Laravel developer working with AI assistance. Days are developer-days,
including tests and documentation.

| Phase | Scope | Days |
|---|---|---|
| 1 (remainder) | Symfony patch update; re-run on Docker stack + e2e; backup/restore test; prod hardening notes | 3–4 |
| 2 | Settings overlay, organisation + site settings GUI, brand CSS tokens, timezone/locale (en-AU), retention in GUI, audit trail foundation | 9–12 |
| 3 | Visitor types, form engine (incl. photo/file with 1-year retention and compression), conditional fields, versioned policies and acceptances | 13–18 |
| 4 | Extract walk-in service, nullable actor, public command layer, idempotency, rate limiting, threat model | 6–8 |
| 5 | Kiosk enrolment + device cookie, kiosk sign-in flow, local host directory, inactivity reset, confirmation | 10–14 |
| 5b | Entra host directory (Graph sync to host-only users, cache, failure handling) | 4–6 |
| 6 | QR/mobile flow on the same commands; on-phone sign-in confirmation; QR abuse controls | 4–6 |
| 7 | Returning visitors (exact match + confirm), kiosk sign-out with live lookup, emailed sign-out link | 5–7 |
| 8 | 62 × 100 label format + configurable templates (optional) | 3–5 |
| 9 | Active register, emergency snapshot, roll-call statuses, mobile view + PDF export | 6–9 |
| 10 | Alta1 configuration (in the private deployment repo) | 3–4 |
| 11 | Internal security review, scanning, threat-model review (external pen test separate) | 4–6 |
| 12 | Public-release docs, screenshots, sample config, history/secret check | 4–5 |
| **Total** | | **74–104 days** |

The critical path to a usable pilot (kiosk and QR sign-in/out at one site, without printing or
emergency features) is Phases 1–7: **about 54–75 days**.

What made the estimate smaller than a greenfield build: authentication, MFA, OIDC, roles, sites,
the visit and attendance model, the reception board, host notifications, retention purge and
Docker deployment all exist and are tested.

## 12. Decisions recorded from review

| Decision | Outcome |
|---|---|
| Installation model | One organisation per install, multiple sites |
| Alta1 deployment config | Separate private deployment repository |
| Audit trail | Foundation built in Phase 2 |
| Printing | Nice-to-have; Phase 8 stays where it is. Small sites use an on-phone confirmation. |
| Kiosk sign-out lookup | Live name/number suggestions allowed on staffed kiosks (receptionist present). Make it a per-kiosk setting so unattended kiosks can use exact match only. |
| Evidence files | Compressed and kept 1 year (deployment setting), on Alta1's own storage |
| Pre-registration matching | After core functions are working |
| Baseline | Upstream v0.9.1 |

## 13. Open items before Phase 2

1. Link the GitHub account to this workspace so branches can be pushed.
2. Re-run the baseline on the Docker stack (MariaDB 11.4 + Gotenberg) and the e2e specs.
3. Apply the Symfony patch update (or adopt upstream's if released first).
4. Create the private deployment repository.
5. Optional: open an upstream discussion about contributing the settings overlay and walk-in service extraction.
