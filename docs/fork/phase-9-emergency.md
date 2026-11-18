# Phase 9: Emergency operations

Status: **for review**. Branch `feature/phase-9-emergency`.

Brief §21: active visitor register, emergency snapshot, roll-call statuses, audit trail. Plus
per-site automatic sign-out, so forgotten sign-outs do not pollute the register.

## On site now (`/reception/on-site`)

Everyone signed in at a site: name, company, visitor type, host, check-in time and safety notes
(answers to questions marked **Show on roll call**, including sensitive ones: this is a
permission-protected staff view). Anyone checked in and not checked out counts, **whatever the
state of their visit**, so nobody is left off. People signed in on an earlier day are flagged
"may have forgotten to sign out". Refreshes every 15 s. Menu: Reception → On site now.

## Roll calls

- **Start roll call** (emergency) or **Start drill** takes a **snapshot**: every person on site
  is copied onto the list (name, company, type, host, check-in time, safety notes encrypted at
  rest). Later sign-outs, visit changes or purges never remove anyone. Pressing start twice
  (two wardens) opens the same roll call; starts are serialised per site.
- **Board** (`/reception/roll-calls/{id}`, phone-friendly, refreshes every 5 s): each person has
  **Here / Left / Not yet** (accounted / left site / unaccounted); counts per status act as
  filters; name search. People who signed out normally after the start show "Signed out at
  10:42 (kiosk)" but stay until a warden marks them. People who signed in after the start are
  listed separately with "Add to list"; anyone found who never signed in can be added by name.
- **End** makes the list read-only. **Print** gives a paper copy with tick boxes (power or
  network failure, handing to emergency services). Earlier roll calls are listed on On site now.
- **Audit:** `roll_call.started` (kind, people), `roll_call.marked` (entry, from, to, by),
  `roll_call.person_added` (late or manual), `roll_call.ended` (counts), each with the user.
- **Access:** permission `Run:RollCall` (admin and receptionist roles), limited to sites the user
  can see (all sites with `ViewAny:Visit`, otherwise their assigned sites).
- **Retention:** ended roll calls are deleted after the visit retention period
  (`visitorportal:roll-calls:purge`, daily 03:20 when purging is enabled); audit events stay for
  the audit retention period.

## Automatic sign-out

Settings → Visitor experience → **Sign visitors out automatically at** (`HH:MM`, site
timezone), organisation default from `VISITOR_AUTO_SIGN_OUT_TIME` (e.g. `18:00`); each site can
set its own time (e.g. a central office with evening board meetings) or `off`. Every 5 minutes
(`visitorportal:auto-sign-out`) anyone at a site still signed in from before the most recent
cut-off is signed out, recorded as channel `auto`, their emailed sign-out link invalidated, and
audited (`attendance.auto_signed_out`). **Never while a roll call is open at that site.**

Sites' timezones matter: set `APP_TIMEZONE` (e.g. `Australia/Perth`) before creating sites, or
set each site's timezone in admin.

## Tests

`EmergencyRollCallTest` (6): register contents (other sites, signed-out, closed visits, earlier
days, safety notes shown only for roll-call questions), snapshot unaffected by later sign-out,
signed-out hint, late arrivals, concurrent start, marking, manual add, counts, end makes it
read-only, audit events, safety notes encrypted at rest, access by permission and site, print
view, automatic sign-out per site with override and `off`, frozen during a roll call, tokens
invalidated, purge after retention. `EnglishTranslationCoverageTest` now also checks plural
(`trans_choice`) texts; it found and fixed two untranslated upstream plurals.
