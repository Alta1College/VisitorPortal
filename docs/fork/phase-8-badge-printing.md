# Phase 8: Badge printing

Status: **for review**. Branch `feature/phase-8-badge-printing`. Physical test passed: sample badge
printed correctly on a Brother QL-820NWB with 62 × 100 mm die-cut labels, sent from the server
over the network (no dialog).

The brief (§20) asks for a printing spike before any print service. Safari on iPad always shows
the AirPrint dialog, which the organisation did not want at a kiosk; a native iPad app (the
Verkada approach) would need an Apple developer account, a Mac to build and sign, and Intune
app distribution, and would not cover QR/phone sign-ins. Instead **the server prints directly**
to network label printers (over the site-to-site VPN), so no device ever shows a dialog and
phone sign-ins can print at reception too.

## How it works

```
sign-in (kiosk or phone)
  → RegisterArrival queues PrintBadge (visit id, visitor id, printer id)
  → queue worker: Blade badge (resources/views/badges/label.blade.php)
  → Gotenberg screenshot → PNG at the label's printable size, one CSS pixel per dot (300 dpi)
  → Bitmap: contrast curve + Floyd–Steinberg dithering (crisp text, shaded photos)
  → driver encodes printer commands (Brother QL raster)
  → raw TCP to the printer, port 9100
```

- **No new containers or PHP extensions.** Gotenberg already renders upstream's PDF badges; the
  PNG decoding, dithering and raster encoding are plain PHP (`app/Badges`).
- **Printer choice:** the kiosk's own printer (Kiosks → Label printer), otherwise the site's
  default printer (Badge printers → Site default printer). QR/phone sign-ins and reception
  reprints use the site default. A printer of another site is never used.
- **Which visitors:** Visitor types → "Print a badge" (default on).
- **Failure never affects sign-in** (brief §25): the job retries twice (5 s, 20 s), then the
  failure is audited (`badge.print_failed`) and shown on the printer in admin (status "Error"
  with the message). Successes are audited (`badge.printed`) and set upstream's
  `badge_printed_at`.
- **Screens:** the kiosk says "Your badge is printing. Please collect it from the printer."; a
  phone says "Please collect your badge from reception."
- **Reception reprint:** a "Label" button next to upstream's PDF "Badge" button on the
  reception dashboard and All visits (same permission, `Print:Visitor`), audited
  `badge.reprint_requested`.
- **Admin → Badge printers:** name, site, IP/host name, port, printer type, label stock,
  enabled, site default; "Test print" (sample badge, sent at once, error shown), "Preview"
  (exactly what prints, black and white at 300 dpi). Permission `Manage:BadgePrinters`.

## The badge (62 × 100 mm)

Black on white for thermal labels: badge logo (Settings → Branding → "Logo on visitor badges",
a pure black version recommended; otherwise the normal logo), site name, visitor type banner
(the type's "Label on badge", otherwise its name), **photo**, first name large, last name,
company, who they are visiting, date and time of sign-in, any question marked "Show on badge"
that is not sensitive, and a footer note (Settings → Visitor experience → "Note at the bottom
of the badge"). Never email, phone or sensitive answers.

**Photo on every badge:** the photo taken at this visit, otherwise the visitor's latest photo
from an earlier visit (until evidence retention removes it); with none, a dashed "No photo" box
prints so reception notices. To always capture one, add a required Photo question (with
"Remember for returning visitors") to every visitor type.

Label stocks are a list in `App\Badges\LabelFormat` and printer languages are drivers
(`App\Badges\Drivers`); nothing in the code assumes one size or one printer model.

## Brother QL-820NWB setup (physical test)

1. Load 62 × 100 mm die-cut labels (DK-11202).
2. Give the printer a fixed IP address (printer menu or DHCP reservation) reachable from the
   server over the VPN; raw printing on **port 9100** must be allowed through any firewall.
3. Make sure **Editor Lite is off** (the Editor Lite LED/button), otherwise the printer behaves
   as a USB drive and ignores network jobs.
4. Admin → Badge printers → New: site, IP address, type "Brother QL-700/800 series", labels
   62 × 100 mm, tick "Site default printer" for QR sign-ins and reprints.
5. Press **Test print**. A sample badge should print and cut. If it fails, the message says
   why (not reachable, connection refused…).
6. Admin → Kiosks → choose the printer for each kiosk at that site.

To check from the server: `nc -vz <printer-ip> 9100`.

Things the physical test should confirm: alignment on the die-cut label (the raster follows
Brother's command reference: 696 printable dots with a 12-dot right margin, mirrored); text
and photo quality; cutting; speed from sign-in to label (expected a few seconds: queue worker
poll ≤ 3 s + render ≈ 1 s + print).

## Tests

`BadgePrintingTest` (9): raster byte layout (header, media block, mirrored dot position, row
count), PNG decoding and dithering, kiosk sign-in queues on the kiosk printer and shows the
message, QR sign-ins use the site default and types can opt out, other sites' printers are
never used, the job prints without contact details and records `badge_printed_at`, printer
failure keeps the visitor signed in and records the error, admin test print (success and
failure) and permissions, one default printer per site, reception reprint (auth, permission,
MFA, audit). `EnglishTranslationCoverageTest`: every text in the app has an English entry.
