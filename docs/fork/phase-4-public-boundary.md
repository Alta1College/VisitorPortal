# Phase 4: Public visitor security boundary

Status: **for review**. Branch `feature/phase-4-public-boundary`.

The brief's Phase 4: "Implement the service/command layer needed by anonymous visitor
workflows. Threat-model this layer before exposing it." This phase builds that layer and its
threat model. **No public endpoint is exposed yet.** `routes/public.php` is registered but empty.
The kiosk (Phase 5) adds the first routes, QR/mobile (Phase 6) and email sign-out (Phase 7)
follow, and the independent penetration test is Phase 11.

## What exists

| Piece | Purpose |
|---|---|
| `PublicVisit\Commands\RegisterArrival` | The one sign-in implementation for kiosk and QR: validates details, host, workflow answers and policies; creates visitor, walk-in visit, attendance, answers, acceptances, idempotency record and departure token in one transaction; then checks in |
| `PublicVisit\Commands\RecordDeparture` | Self sign-out with a departure token |
| `PublicVisit\AttendanceTokens` | Issues/redeems opaque single-use tokens (40 random chars, SHA-256 stored, 36 h expiry) |
| `HostDirectory` + `LocalHostDirectory` | Narrow host search for visitors; resolves opaque host option tokens. Entra is the second implementation (brief §18) |
| `public-visitor` middleware group | The web stack (session, cookies, CSRF, security headers, locale) **without** staff MFA enforcement, so public routes never act as a signed-in staff user and never pick up auth or Filament middleware |
| Rate limiters | `public-host-search` 30/min, `public-arrival` 10/min, `public-departure` 10/min plus 60/h per IP; keyed by IP plus the device/site context the channel sets |
| `visitorportal:public:prune` | Daily: drops submission keys after 2 days, spent/expired tokens after 7 |
| Reception contact setting | Organisation-wide with per-site override: the host recorded for "no host" visitor types, or when an optional host is not chosen |

Upstream changes (see `upstream-patches.md`): `VisitActionService` check-in/out accept a null
actor; walk-in visit creation is extracted from `CheckInOutBoard` into `WalkInVisitService`
(reception behaviour unchanged, its tests pass).

## Threat model

Assets: visitor personal data and answers (some sensitive), staff names, the integrity of who is
on site (roll call depends on it), hosts' inboxes, and the availability of reception.
Attackers considered: anyone on the guest Wi-Fi or the internet (QR pages are reachable from
phones), a visitor at a kiosk, someone who later picks up a kiosk, and a leaked database copy.

| # | Threat | Control in this layer | Remaining work |
|---|---|---|---|
| 1 | **Calling staff functionality anonymously** (CRUD, Livewire, Filament) | Separate route file and middleware group; routes may call only `PublicVisit\Commands` and `HostDirectory` (rule in `routes/public.php`, CLAUDE.md rule 4). Commands take no `User` from the request | Phase 11 review: route list must contain nothing else under the public group |
| 2 | **IDOR / enumeration via ids** | Commands accept no database ids from visitors. Site comes from the channel (device or QR token), host is an encrypted option token bound to site and 30-min expiry, sign-out is an opaque token. Results return names only, never ids | Kiosk/QR tokens themselves (Phases 5–6) must be random and revocable |
| 3 | **Staff directory harvesting** through host search | Min 2 characters, prefix match only, max 5 results, names only (no email, department or photo), active hosts at that site only, wildcard characters escaped, rate-limited | Consider minimum 3 characters or first-name + initial for QR (internet-reachable) in Phase 6 |
| 4 | **Visitor data leak via returning-visitor matching** | Public arrival never matches or reuses existing visitor records (unlike staff walk-in, which reuses by email). Each arrival creates a new visitor | Phase 7 adds matching with confirmation, per brief §15 |
| 5 | **Sign-out token guessing or reuse** | ~238-bit random tokens; hashed at rest; single use; 36 h expiry; invalid once the visitor is signed out by any route or the visit closes; one generic message for every failure; 60 failures/h per IP | Email delivery of the link (Phase 7) |
| 6 | **Duplicate visits from double taps, retries, resubmits** | Client submission key (16–64 chars), hashed with site and channel, unique index; replay returns the first result with a fresh token; a concurrent duplicate is caught on the unique index and replayed | — |
| 7 | **Sign-in lost because a side effect failed** | Check-in runs after the transaction; a notification failure after check-in is logged and audited, and the visitor stays signed in. A check-in that never happened is retried on replay | Badge printing (Phase 8) follows the same rule |
| 8 | **Forged answers or policy acceptance** | All validation is server-side from the configured workflow; hidden fields are discarded; acceptance must name the *current* version of every applicable policy | — |
| 9 | **Malicious uploads** (photos, signatures, evidence) | Raster images re-checked and metadata-stripped (`SafeRasterImage`), files to the private disk with random names, size limits, never served publicly | Serving evidence to staff (later phase) must authorise per request |
| 10 | **CSRF / session riding** | Public group keeps the web CSRF middleware and session cookies; SameSite=lax from upstream | Kiosk device session hardening (Phase 5) |
| 11 | **Abuse / denial of service** (flooding sign-ins, host search, notification spam to hosts) | Per-route rate limits; idempotency; hosts only notified for completed sign-ins | Captcha-free by design (kiosk); revisit for QR if abuse appears |
| 12 | **Leaked database copy** | Tokens and submission keys hashed; sensitive answers encrypted with APP_KEY; host option tokens are encrypted, short-lived and never stored | Key management is deployment (Phase 10) |
| 13 | **Repudiation** ("I never accepted that") | Acceptance stores visit, visitor, exact policy version (with content hash), time and channel; audit entries for arrival, departure, rejections and notification failures. Audit stores counts, not answers | — |
| 14 | **Misconfiguration** (no reception contact for a "no host" type) | Sign-in is refused with a neutral message and a warning is logged; nothing half-created | Phase 5 admin check: warn when a site offers a no-host type without a reception contact |
| 15 | **Information disclosure in errors** | Neutral validation messages; departure failures identical; configuration gaps logged, not shown | Production error pages are upstream's (no stack traces with `APP_DEBUG=false`) |

## Decisions

| Decision | Reason |
|---|---|
| Null actor + `checked_in_via`/`checked_out_via` instead of a fake "kiosk" user | Honest audit data (assessment §7, option b) |
| Public arrivals always create a new visitor record | Avoids leaking that someone visited before. Duplicate visitor rows are acceptable until Phase 7 matching |
| No "one active attendance per visitor" constraint yet | Visitors are not matched across arrivals, so it would never trigger. Add it with Phase 7 matching (generated column + unique index, assessment §9) |
| Reception contact as the fallback host | Upstream requires a host user on every visit; this keeps the foreign key and notifications intact |
| Rate limiters defined now, applied by Phase 5 routes | Limits belong on HTTP routes; the commands stay channel-agnostic |
| Entra host directory not built yet | Its sync into local users is Phase 10 (Alta1 configuration). The interface is in place |

## Tests

`tests/Feature/Fork/PublicVisitCommandsTest.php` (15): arrival with no staff actor (channel, type,
host, notification, audit), workflow and policy enforcement with nothing created on failure,
idempotent replay (and per-site scoping), malformed keys, host rules by visitor type and reception
fallback, tampered/expired/foreign/deactivated host tokens, host search disclosure limits, failed
notification keeps the sign-in, single-use departure, identical rejection for unknown, short,
expired and staff-closed tokens, hashed storage, contact requirement, unavailable types, the
public middleware group, and pruning. Existing reception tests cover the walk-in extraction.
