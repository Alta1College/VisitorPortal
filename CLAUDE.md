# CLAUDE.md

Guidance for Claude Code in this repository.

## What this project is

**Edu Visitor Portal**: an open-source, self-hosted visitor management platform for schools and
other multi-site organisations. It is a **fork and extension** of
[P0etInc0de/VisitorPortal](https://github.com/P0etInc0de/VisitorPortal) (GPL-3.0-or-later),
**not a rewrite**. Alta1 is the first deployment, not the organisation the product is built around.

Read before starting work:

- `docs/fork/implementation-brief.md` — goals, phases and hard constraints
- `docs/fork/phase-0-1-assessment.md` — architecture map, change map for Phases 2–5, decisions so far
- `docs/fork/upstream-sync.md` — how upstream releases are merged

## Hard rules

1. **Keep the stack.** Laravel 12, Livewire, Filament 5, PHP 8.4, MariaDB. Do not replace any of
   them, and do not move to PostgreSQL. If MariaDB cannot meet a concrete requirement, stop and
   document it instead.
2. **No organisation-specific code in core.** Never hard-code Alta1, its sites, domains, emails,
   Entra tenant, branding, retention periods, printer models, WWCC or Western Australian law.
   Express such needs as settings, configurable forms, policies or optional integrations.
   Alta1's own configuration lives in a separate private deployment repository, never here.
3. **Keep upstream mergeable.** Prefer new tables, services, providers and Filament resources over
   editing upstream files. Every edit to an upstream file must be listed, with the reason, in
   `docs/fork/upstream-patches.md`. Never alter an upstream column; add columns or tables instead.
4. **The public visitor surface is a separate security boundary.** Kiosk, QR/mobile and sign-out
   endpoints live in their own route file and middleware stack. Never expose authenticated
   controllers, Livewire components or Filament actions to anonymous users. Public endpoints never
   accept or reveal database IDs; use opaque, non-sequential tokens. They need server-side
   validation, rate limiting, CSRF, minimal disclosure and audit logging.
5. **One implementation of visitor registration.** Kiosk and QR use the same command/service layer.
6. **Graceful failure.** Notification or badge failures must never undo a sign-in.
7. **No premature abstraction.** Add an extension boundary only where two real implementations
   exist or replaceability is required (host directory: local/Entra; notifications: email/later
   Teams; printing: browser/later print agent).
8. **Licensing.** Keep upstream SPDX headers and attribution. New files get
   `SPDX-FileCopyrightText: 2026 Simon Grant` and `SPDX-License-Identifier: GPL-3.0-or-later`.
   When editing an upstream file, keep its copyright line and add
   `SPDX-FileCopyrightText: 2026 Simon Grant` beneath it; do the same for a file adapted from
   upstream code. Do not copy code from other projects unless their licence is confirmed
   compatible.
9. **Never commit secrets, `.env` files, real visitor data or internal hostnames/IPs.**
10. **Naming.** The product's display name is "Edu Visitor Portal". Upstream's internal names
   (`App\Support\VisitorPortalPermissions`, `visitorportal:` commands, `besucherportal` containers,
   config keys) stay as they are while the project tracks upstream.

## Where new code goes

| Area | Location |
|---|---|
| Settings overlay, brand CSS | `app/Settings/` |
| Audit trail | `app/Audit/` |
| Visitor types, form engine, policies | `app/VisitorWorkflow/` |
| Public commands and controllers | `app/PublicVisit/`, `routes/public.php` |
| Kiosks | `app/Kiosk/` |
| Host directory providers | `app/HostDirectory/` |
| Public views | `resources/views/public/` |

Fork migrations are additive and named `YYYY_MM_DD_HHMMSS_fork_<description>.php`.

## Conventions inherited from upstream

- Translation keys in source are **German strings** (`__('Besucherausweis_')`), translated via
  `lang/en.json` etc. Follow the same pattern for new strings; add `lang/en_AU.json` only where
  wording differs.
- Check-in/out logic is in `App\Services\VisitActionService`; attendance lives on the
  `visit_visitor` pivot. Reuse these rather than duplicating.
- Authorisation is policy-based (spatie/laravel-permission + Filament Shield), scoped by site,
  department and ownership.
- Code style: Laravel Pint (`backend/pint.json`).

## Commands

All application commands run in the Docker dev stack from the repository root (`backend/` is the
Laravel app):

```sh
# first run on a fresh clone: cp backend/.env.example backend/.env
docker compose up -d
docker compose exec web composer install                 # first run, and after lock changes
docker compose exec web php artisan key:generate         # first run only
docker compose exec web php artisan migrate:fresh --seed
docker compose exec web php artisan test                 # full suite (baseline: 413 passing)
docker compose exec web php artisan test --filter=Name   # single test
docker compose exec web ./vendor/bin/pint --test         # code style
docker compose run --rm node npm run build               # frontend assets
docker compose exec web composer audit                   # dependency advisories
```

Playwright e2e specs are in `backend/tests/e2e` (see `backend/package.json` scripts).

## Workflow

- Default branch is `develop`. Work on `feature/<phase>-<name>` branches and open PRs into `develop`.
- Every change comes with tests. Run the full suite and Pint before committing.
- Stop for review at the end of each phase in the brief; do not start the next phase unprompted.
