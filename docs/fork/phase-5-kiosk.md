# Phase 5: Kiosk MVP

Status: **for review**. Branch `feature/phase-5-kiosk`.

The brief's Phase 5: a site-bound kiosk taking a visitor through visitor type, details, host,
configured questions and policies to a created, checked-in visit and a confirmation, proving the
end-to-end workflow before extras (brief §13–14). It is the first public surface, built on the
Phase 4 command layer.

## Setting up a kiosk

1. Admin → **Visitor workflow → Kiosks → New kiosk**: name, site, inactivity reset (30–600 s,
   default 90), "staffed by reception" (used by kiosk sign-out in Phase 7).
2. On the kiosk's page, **Create setup code**: an 8-character one-time code (shown once, valid
   24 h).
3. On the iPad, open `https://<host>/kiosk/enrol` and enter the code. The device is now that
   kiosk; open `/kiosk` (add it to the home screen / Guided Access or Intune kiosk mode).
4. **Revoke** stops the device immediately. A new setup code also disconnects the old device.

The kiosk page warns when its site offers a visitor type without a required host but has no
**Reception contact** (Phase 4 threat-model item 14).

## Visitor flow

Welcome screen, laid out for tablets (iPad portrait and landscape): logo, a time-of-day greeting
in the site's timezone (automatic), the welcome heading (default "Guests, start here."), the
visitor type question (default "What brings you here?") and one pill per visitor type in the
primary colour. Heading, message and question are in Settings → Visitor experience, per site if
needed; visitor types, their host requirement, questions and policies in Visitor workflow. Type
is SF Pro Rounded on iPad (`ui-rounded`), the system font elsewhere. Tapping a type starts the sign-in;
there is no separate "Sign in" step. Pill text uses the organisation's background colour and all
text the foreground colour (`.brand-on-primary` in `/brand.css`), so the kiosk shows brand colours
only. The bottom-right corner is kept for the mobile check-in QR code (Phase 6).

Then details (contact requirement from upstream's setting) → host search
(skipped for "no host" types; optional types, the default for new types, offer "I don't
know" and record the reception contact) → questions (conditional
fields appear live; photo/file uploads; signature pad) → policies (rendered text + acceptance)
→ confirm → done: "Thank you, *first name*. *Host* has been told you have arrived." The screen
clears itself 10 s after success and after the configured inactivity time on any other step;
**Cancel** clears it at once.

The final submit is `RegisterArrival` (Phase 4): one transaction, idempotent submission key, host
notified after commit. Attendances record `checked_in_via = kiosk` and the new `kiosk_id`.

## Security model

| Concern | Control |
|---|---|
| Device identity | 64-character random secret in an encrypted, HttpOnly, SameSite=Lax (Secure on HTTPS) cookie, ~400 days; only its SHA-256 is stored. The kiosk never authenticates as a user |
| Enrolment | One-time 8-character code (unambiguous alphabet, ~10¹² combinations), hashed, 24 h, single use, row-locked on redemption; 5 attempts/min per IP; every attempt audited |
| Every request re-checked | `RequireKioskDevice` on the route **and** as Livewire persistent middleware, so `/livewire/update` calls are re-checked (revocation is immediate). The component also re-resolves the kiosk itself |
| No ids in the browser | Component state holds the visitor type slug, the encrypted host option token, policy content hashes and a UUID submission key; tested |
| Abuse | Host search 30/min and sign-in 10/min per kiosk (inside the component, since Livewire updates bypass route throttles) |
| Leftover personal data | All entered data is cleared on success, cancel and inactivity; a new submission key per visitor |
| Fixed appearance | Kiosk pages always use the light theme, `noindex`, no text selection or callouts |

## Not in this phase

- **Kiosk sign-out.** The welcome screen says to sign out at reception. Self sign-out (staffed:
  name suggestions; unattended: exact match) and the emailed sign-out link are Phase 7. The
  departure token is already issued per sign-in.
- **Returning visitors** (Phase 7), **QR/mobile** (Phase 6), **badge printing** (Phase 8).
- **Compressed photos** — still no image library in the container (see Phase 3).
- **Kiosk-level text overrides** — the third inheritance level is just the device settings for now.

## Known limitations

- The setup page shows Laravel's "page expired" if left open longer than the session lifetime;
  reload it.
- On this Windows dev stack each step takes 2–3 s (bind-mount I/O); expect sub-second responses
  on Linux.

## Tests

`tests/Feature/Fork/KioskTest.php` (9) and `KioskAdminTest.php` (3): full contractor sign-in
(conditional field error then success, policy required, recorded with kiosk id, sensitive answer
encrypted, double tap → one visit), skipped steps with reception fallback, restart clears state,
no database ids in component state, unknown/revoked devices refused on page and on Livewire
updates, persistent middleware registered, codes single-use and expiring, a new code disconnects
the old device, enrolment rate limit, admin create / code / revoke with audit, reception-contact
warning, and access denied without permission. Also verified by hand in the browser end to end.
