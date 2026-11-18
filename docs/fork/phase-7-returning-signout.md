# Phase 7: Returning visitors and self sign-out

Status: **for review**. Branch `feature/phase-7-returning-signout`.

The brief's Phase 7 (§15, §17): privacy-safe recognition of returning visitors, and secure
self sign-out at the kiosk and by emailed link. Settings → Visitor experience gains
**Recognise returning visitors** and **Email a sign-out link** (both default Yes, per site).

## Self sign-out

| Where | How | Recorded as |
|---|---|---|
| Kiosk | **End visit** (top right; label from the "Sign-out label" setting) → name → confirm → "Goodbye, Pat!" (clears after 10 s) | `kiosk` |
| Phone | Scanning the QR code again on the phone that signed in shows "You're signed in, Pat — End visit" | `qr` |
| Email | Visitors who gave an email get a one-time link; opening it shows a button, pressing it signs out | `email_link` |

- **Unattended kiosk** (default): exact first **and** last name, case-insensitive. No partial
  matches, no list of who is on site. Several matches (same name) are told apart by sign-in time.
- **Staffed kiosk** (Kiosk → "Staffed by reception"): names are suggested from two letters, as
  reception can see who is on site anyway.
- Matches are shown as "Pat P." with the sign-in time, carried as encrypted, site-bound option
  tokens valid for 10 minutes; the attendance is re-checked when the visitor confirms.
- Phones never offer name lookup (internet-reachable); they rely on their own HttpOnly cookie
  holding the departure token (36 h, cleared on sign-out).
- Emailed link: `/leave/{token}`, the Phase 4 departure token (~238 bits, hashed at rest, single
  use, 36 h, invalid once the visitor is signed out by any route). `GET` only shows the button
  (mail scanners fetch links); `POST` signs out. The mail is sent after the response, not queued,
  so the plain token is never written to the jobs table; a mail failure is audited and never
  undoes the sign-in.

## Returning visitors

- **Kiosk:** "Been here before?" on the details step → email address **or** mobile number, plus
  last name. Both must match exactly (mobile numbers compare by their last nine digits, so
  `0412 345 678` and `+61 412 345 678` match). Any failure gives the same message.
- The visitor then sees a masked summary ("Welcome back, Casey! Casey Contractor ·
  c•••@s•••.example · •••• 678 · Company saved") and must confirm.
- Saved values never reach the screen: the details step shows names only, other fields say
  "Saved", and the server merges saved values into empty fields on submit (`ReturningVisitors::merge`,
  used by the screen and by `RegisterArrival`). Knowing someone's email and surname therefore
  never reveals their phone number or company.
- **Phone:** no lookup. On the confirm step the visitor may tick "Remember my details on this
  phone": an encrypted token (180 days, HttpOnly cookie on that phone only). Next time the
  masked summary is offered; "Not me – forget" deletes the cookie.
- `RegisterArrival` reuses the confirmed visitor record (refreshing any details typed this time)
  instead of creating a duplicate, and signs them out of an earlier visit at the same site they
  forgot to end, so roll call lists them once (audited `public.arrival.closed_previous`).

## Threat model updates (Phase 4 items 4 and 5)

| # | Threat | Control |
|---|---|---|
| 4 | Visitor data leak via matching | Two exact identifiers, neutral failure message, 10 lookups/min per kiosk, masked summary, values merged server-side, confirmation required, lookup on kiosks only, remember-me opt-in on the visitor's own phone |
| 5 | Sign-out abuse | Unattended kiosk: exact full name + confirm, 10 lookups/min; staffed: suggestions 30/min; option tokens encrypted, site-bound, 10 min; email link POST-only, single use, 36 h; phone cookie HttpOnly |
| new | Signing someone else out at an unattended kiosk | Requires their exact full name; audited with channel; reception still sees the attendance history |

## Not in this phase

- One-time codes by email/SMS to prove ownership of an address (would need SMS delivery).
- A database constraint for "one active attendance per visitor" — the arrival command closes
  forgotten ones; add the constraint with the generated column in a later hardening pass.

## Tests

`SelfSignOutTest` (6) and `ReturningVisitorTest` (6): exact-name kiosk sign-out with tokens
invalidated, staffed suggestions and duplicates without ids, other-site and stale options
refused, phone cookie sign-out (channel `qr`) and no name lookup on phones, emailed link
(GET does not sign out, POST does, second use and unknown tokens refused), setting and
no-address cases; returning lookup with exact identifiers only (wrong surname and partial
numbers rejected, international format accepted), no saved values in component state,
record reused and updated, forgotten sign-in closed, decline and setting off, phone
remember-me opt-in and forget, no lookup on phones, masks.
