# Upstream patches

Every modification to a file that exists upstream (P0etInc0de/VisitorPortal) is listed here, so
upstream merges can be reviewed quickly. New files added by the fork are not listed.

| File | Change | Reason | Phase | Offer upstream? |
|---|---|---|---|---|
| `backend/composer.lock` | Symfony components updated to patch releases | Security advisories (see assessment §2) | 1 | Superseded by upstream when it updates |
| `backend/lang/{en,de,fr,cs}.json` | 121 missing translations appended (two-factor/security screens, validation messages, guest emails, mail subjects) | Untranslated German showed in English UI | fix | Yes |
| `backend/app/Mail/{Guest,Host}/*Mail.php` (6 files) | Envelope subjects wrapped in `__()` | Subjects were hard-coded German in every language | fix | Yes |
| `backend/tests/Feature/{Auth/MfaTest,Notification/NotificationTest,Portal/CheckInOutBoardTest,Receptionist/ReceptionAdministerVisitTest}.php` | Assertions use `__()`/`trans_choice()` instead of literal German | Tests had asserted the untranslated text | fix | Yes |
