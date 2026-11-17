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
