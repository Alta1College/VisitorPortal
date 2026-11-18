# Phase 6: QR / mobile check-in

Status: **for review**. Branch `feature/phase-6-mobile-qr`.

The brief's Phase 6 (§16): a site-linked QR code lets visitors complete the same visitor workflow
on their own phone, through the same business logic as the kiosk.

## Setting up

1. Admin → **Visitor workflow → QR check-in → Set up QR code for a site**.
2. **Show** displays the code and its address; **Print poster** opens an A4 poster (logo, welcome
   heading, QR code, three-step instructions, address) — print it from the browser.
3. Kiosks at that site show the code bottom-right on the welcome screen ("Mobile check-in"), so a
   queue can scan in while the tablet is busy.
4. **Active** off stops the link at once; **Replace** issues a new code (old posters and open pages
   stop working; kiosks show the new code immediately). Both are audited.
5. **Settings → Identity → Public address**: the address phones use to reach the portal (e.g.
   `https://visit.example.org`). Empty: the address the page was opened on — fine on kiosks, but
   set it before printing posters from an admin session opened on another address.

## One implementation

`App\PublicVisit\Livewire\VisitorSignIn` holds every step (type, details, host, questions,
policies, confirm, done) and ends in `RegisterArrival`. Two thin screens extend it:

| | `Kiosk\Livewire\KioskScreen` | `QrCheckIn\Livewire\MobileSignIn` |
|---|---|---|
| Site from | Enrolled device cookie | `/visit/{token}` link |
| Channel recorded | `kiosk` (+ `kiosk_id`) | `qr` |
| Idle reset | Kiosk setting, 10 s after done | None (visitor's own phone) |
| Host search | 2+ characters | 3+ characters (internet-reachable, Phase 4 item 3) |
| Rate limits | Per kiosk | Per link and client address; page loads 30/min per address |
| Zoom | Off (fixed kiosk) | On (accessibility) |

Views are shared: `resources/views/public/signin/{layout,screen}.blade.php`.

## Security

| Concern | Control |
|---|---|
| Guessing links | 32-character random token (~190 bits), format-checked before lookup; one per site |
| Revocation | Link re-resolved on every request inside the component (Livewire updates bypass route middleware), so turning off or replacing it stops open pages |
| No ids in the browser | Component state holds the link token (already public in the URL), visitor type slug, encrypted host option tokens and policy hashes; tested |
| Directory harvesting | 3-character minimum, prefix match, 5 results, names only, rate-limited |
| Abuse | Sign-in 10/min and host search 30/min per link and address; page loads 30/min per address |
| Poster | Staff-only route on the normal web stack (signed in, two-factor enforced, QR check-in permission) |

The QR image is rendered as SVG with `bacon/bacon-qr-code`, already installed for upstream's
two-factor setup (no new dependency).

## Not in this phase

- Location checks (a photographed poster works from anywhere). Mitigated by rate limits, host
  notifications and replaceable codes; revisit if abuse appears.
- Self sign-out and returning visitors (Phase 7), badges (Phase 8).

## Tests

`tests/Feature/Fork/QrCheckInTest.php` (7): phone sign-in end to end through the shared workflow
(channel `qr`, policy acceptance, 3-character host search), unknown / disabled / replaced links
refused including on open pages, no database ids in state, kiosk shows the code only while
enabled, public address setting, admin set up / replace / poster with audit and two-factor,
permission checks. Existing kiosk tests cover the shared steps.
