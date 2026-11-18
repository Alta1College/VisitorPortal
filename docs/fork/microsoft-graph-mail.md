# Microsoft 365 mail via Graph (OAuth)

`MAIL_MAILER=microsoft-graph` sends all application mail (sign-out links, host notifications,
password resets) through Microsoft Graph with OAuth 2.0 client credentials. No mailbox password
and no SMTP AUTH, so Microsoft's retirement of basic authentication does not affect it.

Code: `app/Messaging/MicrosoftGraphTransport.php` (a Symfony mailer transport registered by
`MessagingServiceProvider`). The message is sent as MIME to `POST /v1.0/users/{sender}/sendMail`,
so HTML, inline images and attachments arrive as Laravel built them; Bcc recipients are added
as a header (Graph reads recipients from the MIME). The access token is cached until five
minutes before it expires and fetched again once if Graph rejects it. Errors name Entra's or
Graph's error code only, never the secret.

## Entra setup (tenant administrator)

1. **App registrations → New registration**, single tenant, no redirect URI.
2. **API permissions → Add → Microsoft Graph → Application permissions → Mail.Send**, then
   **Grant admin consent**.
3. **Restrict it to the sending mailbox.** Without this, Mail.Send allows sending as any
   mailbox in the tenant. Use Exchange Online RBAC for Applications (recommended) or an
   application access policy, for example:

   ```powershell
   New-ApplicationAccessPolicy -AppId <application-id> -PolicyScopeGroupId visitors@example.org `
     -AccessRight RestrictAccess -Description "VisitorPortal may only send as visitors@"
   Test-ApplicationAccessPolicy -Identity someone.else@example.org -AppId <application-id>   # AccessCheckResult: Denied
   ```

4. **Certificates & secrets → New client secret.** Note its expiry and diarise the renewal;
   mail stops when it expires (the test command below shows `invalid_client`).
5. Optionally disable SMTP AUTH on the sending mailbox.

Use a separate app registration for staff sign-in (OIDC, `docs/sso.md`): sign-in needs no
Mail.Send, and a leaked secret then affects one function only.

## Configuration

```dotenv
MAIL_MAILER=microsoft-graph
MAIL_FROM_ADDRESS=visitors@example.org
MAIL_FROM_NAME="Visitor sign-in"

MICROSOFT_GRAPH_TENANT_ID=<directory (tenant) id>
MICROSOFT_GRAPH_CLIENT_ID=<application (client) id>
MICROSOFT_GRAPH_CLIENT_SECRET=<client secret value, not the secret id>
MICROSOFT_GRAPH_SENDER=visitors@example.org   # defaults to MAIL_FROM_ADDRESS
```

Graph only sends as the sending mailbox (`403 ErrorSendAsDenied` otherwise), so a message with
any other From address is sent from `MICROSOFT_GRAPH_SENDER`, keeping the display name, with
the original address as Reply-To. Set `MAIL_FROM_ADDRESS` to the sending mailbox anyway.
National clouds: set `MICROSOFT_GRAPH_AUTHORITY` and `MICROSOFT_GRAPH_ENDPOINT`.
After changing `.env` in production, run `php artisan config:cache` and restart the queue worker.

## Test

```bash
php artisan visitorportal:mail:test you@example.org --mailer=microsoft-graph
```

`--mailer` tries Graph without switching the application's mail over. Common errors:

| Message | Cause |
|---|---|
| `401 invalid_client` | Wrong or expired secret, or the secret ID was used instead of the value |
| `400 unauthorized_client` / `invalid_request` | Wrong tenant or application ID |
| `403 ErrorAccessDenied` | Mail.Send not consented, or the access policy excludes the sender |
| `404 ErrorInvalidUser` / `ResourceNotFound` | Sender mailbox does not exist or has no Exchange licence |

## Tests

`MicrosoftGraphMailTest` (6): MIME body with To, Bcc and subject, other From addresses sent as the mailbox with Reply-To, bearer token from client
credentials cached across messages, one refresh after a 401, errors name the code but never the
secret, test command with and without `--mailer`.
