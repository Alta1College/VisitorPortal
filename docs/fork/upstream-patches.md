# Upstream patches

Every modification to a file that exists upstream (P0etInc0de/VisitorPortal) is listed here, so
upstream merges can be reviewed quickly. New files added by the fork are not listed.

| File | Change | Reason | Phase | Offer upstream? |
|---|---|---|---|---|
| `backend/composer.lock` | Symfony components updated to patch releases | Security advisories (see assessment §2) | 1 | Superseded by upstream when it updates |
| `backend/bootstrap/providers.php` | Register `AuditServiceProvider`, `SettingsServiceProvider` (Phase 2) and `VisitorWorkflowServiceProvider` (Phase 3) ahead of the upstream providers | Settings overlay must apply before the admin panel reads `config('branding.*')` | 2, 3 | No |
| `visit_visitor` table (migration, no file edit) | Additive nullable column `visitor_type_id` | Visitor type of each attendance | 3 | No |
| `backend/app/Support/VisitorPortalPermissions.php` | Merge `ForkPermissions::ADMIN` into the admin role (one statement) | Admin role needs the settings and audit permissions | 2 | No |
| `backend/app/Support/UserPreferences.php` | `browserLocale()` falls back to `config('app.locale')` instead of always `en` | Organisation default language | 2 | Yes |
| `backend/app/Models/Site.php` | `Site::default()` timezone from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` | 2 | Yes |
| `backend/app/Filament/Resources/Sites/Schemas/SiteForm.php` | Timezone field default from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` | 2 | Yes |
| `backend/database/factories/SiteFactory.php` | Timezone from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` | 2 | Yes |
| `backend/lang/{en,de,fr,cs}.json` | 121 missing upstream translations appended (two-factor/security screens, validation messages, guest emails, mail subjects) | Untranslated German showed in English UI | fix | Yes |
| `backend/lang/{en,de,fr,cs}.json` | Strings for fork UI appended at the end of each file | Upstream's German-key convention; `LocalizationSourceTest` requires every key in every supported locale | 2 | No (append-only; resolve conflicts by keeping both sides) |
| `backend/app/Mail/{Guest,Host}/*Mail.php` (6 files) | Envelope subjects wrapped in `__()` | Subjects were hard-coded German in every language | fix | Yes |
| `backend/tests/Feature/{Auth/MfaTest,Notification/NotificationTest,Portal/CheckInOutBoardTest,Receptionist/ReceptionAdministerVisitTest}.php` | Assertions use `__()`/`trans_choice()` instead of literal German | Tests had asserted the untranslated text | fix | Yes |
| `backend/resources/views/layouts/{app,guest}.blade.php`, `backend/resources/views/monitor/show.blade.php` | One line after the app stylesheet: `<x-brand-stylesheet />` | Organisation colours in the portal, login and welcome screens | 2 (follow-up) | No |
| `backend/app/Services/VisitActionService.php` | `checkInParticipant` / `checkOutParticipant` accept `?User $actionBy` (null-safe id) | Visitors check themselves in and out on public channels; no fake staff user | 4 | Yes |
| `backend/app/Livewire/Portal/CheckInOutBoard.php` | Walk-in visit creation moved to `App\Services\WalkInVisitService` (behaviour unchanged) | One walk-in implementation for reception and public channels | 4 | Yes |
| `visit_visitor` table (migration, no file edit) | Additive nullable columns `checked_in_via`, `checked_out_via` | Record the channel of each check-in/out | 4 | No |
| `backend/bootstrap/providers.php` | Register `PublicVisitServiceProvider` | Public route group, rate limiters, host directory | 4 | No |
| `visit_visitor` table (migration, no file edit) | Additive nullable column `kiosk_id` | Which kiosk recorded a sign-in | 5 | No |
| `backend/bootstrap/providers.php` | Register `MessagingServiceProvider` and `BadgesServiceProvider` | Microsoft Graph mail; badge printing and reprint route | 7, 8 | No |
| `backend/config/mail.php` | Additional `microsoft-graph` mailer entry | Microsoft 365 mail with OAuth | 7 | Could offer |
| `backend/app/Livewire/Reception/{DashboardPage,AllVisitsPage}.php` | One extra `label_url` key in each participant row | Reception "Label" reprint button when the site has a label printer | 8 | No |
| `backend/resources/views/livewire/reception/partials/dashboard-participant-row.blade.php`, `backend/resources/views/reception/partials/all-visits/participant-row.blade.php` | "Label" form button before upstream's PDF badge button (shown only with `label_url`) | Reprint on the label printer | 8 | No |
| `backend/resources/views/livewire/reception/{dashboard-page,all-visits-page}.blade.php` | `<x-bp.alert />` (upstream component) at the top | Show "Printing on …" after a reprint | 8 | No |
| `kiosks`, `visitor_types` tables (migration, no file edit) | Additive `kiosks.badge_printer_id`, `visitor_types.print_badge` | Which printer a kiosk uses; which types get a badge | 8 | No |
| `backend/bootstrap/providers.php` | Register `EmergencyServiceProvider` | Register, roll calls, automatic sign-out | 9 | No |
| `backend/app/Support/VisitorPortalPermissions.php` | Merge `ForkPermissions::RECEPTIONIST` into the receptionist role (one statement) | Receptionists run roll calls | 9 | No |
| `backend/resources/views/layouts/navigation.blade.php` | "On site now" link (desktop and mobile), shown with `Run:RollCall` | Reach the register and roll calls | 9 | No |
| `backend/lang/{en,de,fr,cs}.json` | Two missing upstream plurals (`:count Stunde…`, `:count Treffer…`) | German showed on the check-in/out board | 9 | Yes |
| `backend/tests/Feature/Portal/CheckInOutBoardTest.php` | Two assertions use `trans_choice()` instead of literal German "Treffer" | The plural is now translated | 9 | Yes |
| `backend/database/migrations/0000_01_01_000000_create_sites_table.php` | Default site timezone from `config('app.timezone')` | Remove hard-coded `Europe/Berlin` (new installs) | 9 | Yes |
