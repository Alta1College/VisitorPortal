VisitorPortal Open-Source Fork
Implementation Brief for Claude Code
1. Project objective

Fork P0etInc0de/VisitorPortal into a production-ready, self-hosted, open-source visitor management platform suitable for schools and other multi-site organisations.

Alta1 will be:

the first production deployment;
an initial contributor;
a reference implementation; and
one beneficiary of the project, not the organisation the product is hard-coded around.

The fork should remain useful to other schools without requiring knowledge of Alta1 infrastructure or changing source code.

2. Selected upstream

Use:

P0etInc0de/VisitorPortal

as the upstream foundation.

The repository comparison has already been completed. This project was selected because it provides the strongest combination of:

multi-site architecture;
existing visit domain;
staff/reception workflows;
roles and permissions;
generic OIDC;
MFA;
badge generation;
Docker deployment;
automated tests;
update documentation;
a known GPL-3.0 licensing basis; and
demonstrated upstream security maintenance.

Do not restart selection of the base project unless a material blocker is discovered.

Other projects may be studied for architectural and UX patterns, but their code must not be copied unless licence compatibility has first been confirmed.

3. Fundamental development strategy

This project is a fork and extension, not a rewrite.

Preserve the upstream application wherever practical.

Retain the existing:

Laravel 12 application;
Livewire;
Filament 5;
PHP architecture;
site model;
visit model;
staff workflows;
reception workflows;
roles and permissions;
OIDC authentication;
MFA;
existing badge functionality;
Docker deployment;
updater/release structure; and
automated tests.

New functionality should preferably be built around stable upstream components rather than rewriting those components.

The architectural objective is approximately:

Plain Text
Upstream VisitorPortal
|
Existing Laravel Core
|
+----------+----------+
| |
v v
Existing Staff Public Visitor
Experience Experience
|
+---------+---------+
| | |
v v v
Kiosk QR/Mobile Sign-out
4. Upstream compatibility is a design requirement

Maintaining the ability to assimilate upstream security fixes is important.

Configure the Git repository with the original project as an upstream remote.

Avoid unnecessary modifications to existing upstream files.

Prefer:

new services;
new controllers/components;
new database tables;
extension through relationships;
focused modifications;
small upstream patches;

over widespread rewrites.

Do not distort the architecture simply to guarantee conflict-free upstream merges. Some divergence is expected.

Future upstream releases should be reviewed and then:

merged where practical;
cherry-picked where appropriate; or
manually incorporated where our implementation has diverged.

Security fixes should receive particular attention.

5. Database decision

Retain upstream MariaDB initially.

Do not migrate to PostgreSQL merely because earlier planning documents specified PostgreSQL.

The previous PostgreSQL recommendation was made before the architecture of the selected upstream repository was properly understood.

Changing a functioning database platform would:

increase project scope;
increase divergence;
reduce upstream compatibility;
require additional testing; and
provide no currently demonstrated VisitorPortal capability required by Alta1.

Database code should nevertheless remain reasonably portable.

Alta1's existing Microsoft SQL Server infrastructure must not become a runtime dependency.

Future integrations with SQL Server should occur through defined API, synchronisation or integration boundaries.

If implementation identifies a concrete technical reason MariaDB cannot satisfy a requirement, stop and document that requirement before changing database platforms.

6. Organisation-neutral design

There must be no Alta1-specific business logic in core application code.

Do not hard-code:

Alta1;
Alta1 branding;
Alta1 sites;
Alta1 domains;
Alta1 email addresses;
Alta1 Entra tenant;
Western Australian legislation;
WWCC requirements;
Alta1 retention periods;
Alta1 printer models; or
Alta1 SQL Server infrastructure.

Alta1-specific requirements must be implemented using:

configuration;
configurable forms;
policies;
provider integrations; or
deployment-specific settings.

A fresh installation should be deployable by another school without modifying source code.

7. Web-based organisation configuration

Add administration for organisation-level configuration.

Administrators should be able to configure:

Identity
organisation name;
primary logo;
secondary logo;
favicon/application icon;
contact details.
Branding
primary colour;
secondary colour;
accent colour;
foreground/background colours;
email branding;
badge branding.

Use central design tokens or CSS variables.

Do not scatter organisation-specific Tailwind/CSS values throughout components.

Visitor experience

Configure:

welcome heading;
welcome message;
sign-in wording;
sign-out wording;
visitor instructions;
confirmation messages.

Branding changes must be possible from the web GUI.

8. Site configuration and inheritance

Use:

Organisation defaults → Site overrides → Kiosk configuration

Where a site does not override an option, inherit the organisation value.

Site configuration may include:

display name;
logo override;
welcome text;
visitor-type availability;
contact information;
badge configuration;
printer configuration;
kiosk behaviour.

Avoid copying organisation settings unnecessarily into every site.

9. Visitor types drive workflow

Do not implement specific visitor categories as application logic.

Create configurable Visitor Types.

Examples include:

Visitor
Parent/Carer
Volunteer
Contractor
Service Provider
Delivery

These are examples only.

Administrators should be able to create, rename, configure and disable visitor types.

A visitor type should determine which:

fields;
questions;
acknowledgements;
policies;
evidence;
host requirements;

apply to that visitor.

10. Configurable form engine

Implement a reusable form/question engine rather than adding columns for every future requirement.

Support field types such as:

short text;
long text;
email;
telephone;
number;
date;
yes/no;
checkbox;
dropdown;
acknowledgment;
photo;
signature; and
file/evidence upload where appropriate.

Fields should support:

required/optional;
ordering;
visitor-type assignment;
site assignment;
conditional visibility where practical; and
activation/deactivation.

For example, Alta1 should eventually be able to configure WWCC-related requirements without WWCC-specific Laravel business logic.

11. Policies and acknowledgements

Create configurable policies/acknowledgements.

Support assignment by:

organisation;
site;
visitor type.

Important policy text should be versioned.

Historical records must establish:

what policy was accepted;
which version was accepted;
when it was accepted;
which visit/visitor accepted it.

Changing a policy must not retrospectively alter the historical acknowledgement.

12. Public visitor boundary

This is the largest architectural change.

Upstream primarily assumes authenticated users.

The fork introduces anonymous/public visitor interaction.

Treat this as a separate security boundary.

Conceptually:

Plain Text
Authenticated Application
|
+-- Administration
+-- Staff
+-- Reception
 
Public Visitor Surface
|
+-- Kiosk
+-- QR/mobile registration
+-- Secure visitor sign-out

Do not simply expose existing authenticated CRUD operations anonymously.

Create narrow public visitor operations/services.

All public endpoints require:

strict server-side validation;
rate limiting where appropriate;
minimal information disclosure;
opaque identifiers;
protection against enumeration;
secure session handling;
CSRF protection where applicable;
security/event logging; and
server-side authorisation for anything crossing into protected data.
13. Kiosk registration

Introduce a kiosk concept.

A kiosk should be registered to a site.

Conceptually:

Plain Text
Organisation
|
Site
|
Kiosk

A secure, non-sequential device token or registration mechanism should identify the kiosk.

A kiosk must not authenticate as an administrator.

An initial routing model could resemble:

Plain Text
/kiosk/{opaque-device-token}

Do not treat this example as mandatory if a more secure Laravel-native mechanism is preferable.

14. Kiosk visitor workflow

The public interface should be optimised for an Intune-managed iPad.

Primary landing actions:

Sign In

Sign Out

Normal sign-in should approximately be:

Visitor type
Visitor identification/details
Returning visitor recognition where applicable
Host selection where required
Configured questions
Policy acknowledgements
Confirmation
Visit creation
Host notification
Badge printing where configured
Success screen

Automatically clear visitor data from the screen after completion.

Reset abandoned workflows after inactivity.

Use large touch targets and avoid hover-dependent controls.

15. Returning visitors

Returning visitors should not need to re-enter all details.

Possible matching identifiers include:

email address;
mobile number;
secure visitor token.

Never expose a public searchable visitor directory.

Avoid leaking existing visitor information simply because a partially matching value was entered.

Require confirmation before reusing saved visitor information.

16. QR/mobile registration

Provide a site-linked QR code allowing visitors to complete the same visitor workflow from a personal phone.

A site QR may conceptually resolve to:

Plain Text
/visit/{opaque-site-token}

QR registration and kiosk registration must share the same underlying business/service layer.

Do not create two separate implementations of visitor registration.

17. Visitor self sign-out

Support:

Kiosk sign-out

Allow an active visitor to securely locate the applicable visit and sign out.

Secure email sign-out

When an email address is available, optionally send a secure sign-out link.

Use:

cryptographically secure opaque token;
no sequential visit IDs;
expiry where appropriate;
invalidation after use;
invalidation when visit is otherwise closed.

Opening the link should allow the visitor to sign out that specific active visit without an authenticated staff account.

18. Host directory

Create a host-directory abstraction because at least two implementations are genuinely expected:

Local host provider

Works without Microsoft cloud dependencies.

Microsoft Entra provider

Allows applications such as Alta1's deployment to use its staff directory.

Prefer existing generic OIDC/identity capabilities where they solve the requirement. Do not unnecessarily duplicate identity functionality.

Do not expose unnecessary directory information through public search.

Add caching where appropriate so temporary directory service failure does not render reception unusable.

19. Notifications

Create a lightweight notification abstraction.

Initial implementation:

Email

using existing Laravel/mail capabilities wherever possible.

Potential future provider:

Microsoft Teams

Do not delay the core visitor workflow to implement Teams.

Notification failure must not invalidate a visitor sign-in.

Persist/log delivery failures sufficiently for troubleshooting.

20. Badge printing

Reuse the existing badge-generation capability wherever practical.

Adapt badge layout as required for Alta1's intended:

62 × 100 mm label

but avoid making that label size universal.

Badge templates should ultimately be configurable.

Do not immediately build a complicated print service.

First perform a physical printing spike using:

actual managed iPad;
Safari;
intended label printer;
actual network;
intended label stock.

Test whether browser/AirPrint/system printing provides an acceptable workflow.

If it does not, evaluate a lightweight print-agent design.

The repository comparison identified PBC-Guest-Kiosk's Raspberry Pi/CUPS print-agent and print-queue design as a useful architectural reference, not code to automatically copy.

21. Active visitor and emergency roll call

Use active visit data as the foundation.

Provide an authorised view of everyone currently signed in to a site.

At minimum show:

visitor name;
visitor type;
host;
check-in time;
relevant safety indicator where configured.

Emergency roll calls should be snapshot-based.

Once an emergency/roll-call event begins, capture the active population so a later change to a normal visit does not silently remove somebody from the emergency list.

Track statuses such as:

unaccounted;
accounted for;
left site / confirmed absent where appropriate.

The repository comparison identified the snapshot approach in otherlands/SigninApp as a useful reference pattern.

22. Retention

Move operational retention configuration from environment-only settings into appropriate administration configuration.

Retention must not be hard-coded.

Alta1 currently intends:

approximately 3 years online; and
approximately 7 years in backups.

Those are deployment settings, not global defaults.

The application controls its operational database retention. Backup retention belongs to deployment/infrastructure documentation.

23. Audit trail

Implement a database-backed audit trail for meaningful actions such as:

sign-in;
sign-out;
manual sign-out;
visit changes;
administrative visitor changes;
configuration changes;
policy changes;
badge reprints;
emergency roll-call activity;
retention actions.

Record:

timestamp;
action;
relevant entity;
authenticated actor where applicable;
site;
result.

Do not use ordinary application/debug logs as the sole audit record.

Consider append-only patterns for security-sensitive audit history.

24. Authentication

Retain upstream authentication infrastructure.

Keep:

local/break-glass authentication where already appropriate;
OIDC;
MFA;
role/permission model.

Alta1 can configure Microsoft Entra ID through OIDC.

Do not replace existing authentication merely to create an Entra-specific implementation.

25. Reliability

The visitor system is an operational reception service.

Design graceful failure.

Notification failure

Visitor remains signed in.

Badge failure

Visitor remains signed in.

Host directory temporarily unavailable

Kiosk must fail gracefully rather than crash.

Concurrent sign-in

Protect against accidental duplicate or inconsistent visit records.

Consider idempotency for public submissions where double-taps, network retries or browser resubmission could otherwise create multiple visits.

The repository comparison identified explicit idempotency/state-machine patterns in Sahojit/vms as good design references, but its retrieved repository had no confirmed licence, so do not copy its implementation code unless licensing is clarified.

26. Open-source requirements

The project is intended to be open sourced from the beginning.

The public repository should include or retain appropriate:

README.md;
LICENSE;
CONTRIBUTING.md;
SECURITY.md;
CHANGELOG.md;
.env.example;
Docker deployment documentation;
upgrade documentation;
backup/restore documentation.

Retain GPL-3.0-or-later obligations and existing attribution required by upstream.

Do not represent inherited code as originally authored by Alta1.

27. Public-release security gate

Do not publish Alta1-specific development history blindly.

Before making the fork public, inspect both the current tree and relevant Git history for:

credentials;
tokens;
database passwords;
certificates;
private keys;
internal DNS names;
internal IP addresses;
visitor PII;
production data;
Alta1-only configuration;
connection strings;
.env contents.

Any credential previously committed must be treated as compromised and rotated.

28. Deployment

Retain and improve the existing Docker approach.

A technically competent school IT administrator should be able to deploy the project without requiring:

Azure;
AWS;
Microsoft 365;
Kubernetes;
SQL Server;
proprietary cloud services.

Optional integrations may depend on external products, but the core visitor-management system must remain usable without them.

Provide neutral sample configuration rather than Alta1 sample production data.

29. Learn from other projects without changing base

The comparative assessment of public visitor-management repositories is complete. P0etInc0de/VisitorPortal remains the selected base.

Use the following only as design references where useful:

PBC-Guest-Kiosk: CUPS/Raspberry Pi printing, queue state, backup/recovery patterns.
hankalf/smart-lobby: kiosk UX, induction/policy versioning, returning visitors, branding previews and badge test printing. Do not copy code unless its licence is confirmed.
wangjiongwei8/visitor-management: QR registration, host-review workflow and guard-oriented workflows.
Sahojit/vms: state machines, rate limiting, idempotency and append-only auditing. Do not copy code without confirmed licensing.
FFMFG/visit-signin: Entra host caching, provider boundaries, AirPrint and integration failure handling.
otherlands/SigninApp: snapshot-based emergency roll call.

Do not restart the entire application around one of these projects merely because it already implements an isolated feature better.

30. Avoid premature abstraction

Do not create extension frameworks for hypothetical future requirements.

Abstract something when:

two real implementations already exist; or
the requirement clearly demands replaceability.

Initially create explicit extension boundaries for:

Host directory
Local
Entra
Notification
Email
future Teams
Printing
Browser/system
print agent if testing proves necessary

Do not create unnecessary plugin frameworks for every application service.

31. Implementation sequence

Proceed in this order unless repository dependencies provide a concrete reason to change it.

Phase 0: Upstream baseline
Checkout selected upstream release.
Build unchanged.
Run complete test suite.
Record baseline results.
Configure upstream remote.
Document existing architecture.
Phase 1: Security and production baseline
Dependency/security review.
Resolve applicable vulnerabilities.
Production Docker review.
Secrets handling.
Backup and restore test.
Authentication and authorisation review.
Logging review.
Existing tests repaired/expanded where necessary.
Phase 2: Organisation configuration
Organisation model/settings.
Web branding GUI.
Organisation/site inheritance.
Timezone and locale.
Retention configuration.
Phase 3: Configurable visitor workflow
Visitor types.
Form/question definitions.
Responses.
Conditional requirements.
Acknowledgements.
Acknowledgement versioning.
Phase 4: Public visitor security boundary

Implement the service/command layer needed by anonymous visitor workflows.

Threat-model this layer before exposing it.

Phase 5: Kiosk MVP

Implement:

site-bound kiosk;
visitor type;
visitor details;
host;
configured questions;
policies;
visit creation;
confirmation.

Do not add every desired feature before proving the end-to-end workflow.

Phase 6: QR/mobile

Reuse the Phase 4 and 5 business logic.

Phase 7: Returning visitor and self sign-out

Implement privacy-safe visitor recognition and secure sign-out.

Phase 8: Badge printing

Implement using the results of the physical printing spike.

Phase 9: Emergency operations
Active visitor register.
Emergency snapshot.
Roll-call statuses.
Audit trail.
Phase 10: Alta1 deployment configuration

Configure the generic platform for Alta1:

branding;
sites;
Australia/Perth;
en-AU;
visitor categories;
required policies;
contractor/WWCC workflows;
Entra host source;
retention;
printing.

Do not introduce Alta1-specific core code during this phase.

Phase 11: Security validation

Before production rollout of anonymous endpoints:

code/security review;
vulnerability scanning;
dependency review;
threat-model review;
independent penetration test of public visitor surfaces.
Phase 12: Public release
documentation;
installation procedure;
upgrade procedure;
contribution guidance;
security disclosure mechanism;
screenshots/sample configuration;
licence/attribution verification;
Git-history and secret check.
32. Scope-control principles

Before implementing a feature, ask:

Does upstream already do this?

If yes, extend or reuse it.

Can this requirement be configuration rather than code?

If yes, prefer configuration.

Do kiosk and QR need different business logic?

Usually no.

Are we creating an abstraction with only one implementation?

If yes, reconsider it.

Does this modification substantially increase upstream merge conflicts?

If yes, look for an extension approach.

Does this requirement belong specifically to Alta1?

If yes, attempt to express it as generic configuration or an optional integration.

33. Do not do the following

Do not:

rewrite the project in Next.js;
replace Laravel;
replace Livewire/Filament without a demonstrated blocker;
migrate to PostgreSQL without an approved technical reason;
build Alta1-specific WWCC business logic;
expose authenticated CRUD endpoints publicly;
expose sequential IDs through public links;
duplicate kiosk and QR business logic;
require Entra ID;
require Microsoft 365;
build a print server before physical testing demonstrates the need;
create a complicated plugin platform prematurely;
tightly couple to SQL Server;
publish secrets or Alta1 production data;
assume upstream can always be merged automatically;
copy source from other GitHub projects without verifying licence compatibility.
34. Immediate Claude Code task

Begin with Phases 0 and 1 only.

Do not start the kiosk implementation yet.

Specifically:

Checkout and build the selected upstream baseline.
Run the complete automated test suite.
Record failures existing before fork modifications.
Identify current dependency vulnerabilities.
Review authentication/authorisation boundaries relevant to future public endpoints.
Map the existing models for sites, visitors, visits, users, badges and notifications.
Identify precisely where the new organisation/configuration layer can be added with minimal modification of upstream code.
Identify precisely how public visitor commands can use the existing visit domain without exposing existing authenticated controllers/actions.
Review existing badge generation and identify the smallest test required to produce a 62 × 100 mm test badge.
Review current MariaDB-specific behaviour and identify whether any known requirement in this brief genuinely requires PostgreSQL.
Establish the upstream Git remote and document the intended upstream-update procedure.
Produce a proposed file/database change map for Phases 2–5.
Provide a revised development estimate by phase, based on reuse of the existing application rather than treating missing functionality as a greenfield rewrite.

Stop after this assessment and baseline work.

Do not begin large structural changes until the findings have been reviewed.

