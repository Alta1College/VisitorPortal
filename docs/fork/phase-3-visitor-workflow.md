# Phase 3: Configurable visitor workflow

Status: **for review**. Branch `feature/phase-3-visitor-workflow`.

Covers the brief's Phase 3: visitor types, form/question definitions, responses, conditional
requirements, acknowledgements and acknowledgement versioning (brief §9–11). Nothing here is
visitor-facing yet. The kiosk (Phase 5) and QR/mobile (Phase 6) screens consume this engine
through the public command layer (Phase 4).

## What an administrator gets

New admin menu group **Visitor workflow** (permission `Manage:VisitorWorkflow`, granted to `admin`):

| Page | Contents |
|---|---|
| Visitor types | Name, key, description, host requirement (required / optional / none), badge label and colour, optional site restriction, active flag, drag-to-reorder |
| Questions and fields | 13 field types (short/long text, email, phone, number, date, yes/no, checkbox, dropdown, acknowledgement, photo, signature, file), help text, dropdown options, length/range/size limits, *sensitive* / *show on badge* / *show on roll call* flags, show-when conditions, and assignments to visitor types and sites with required flag and order |
| Policies | Internal name, active flag, assignments (organisation / site / visitor type), and versioned wording: the first version is published on create; later changes are published as a new version from the Versions list. Published versions cannot be edited or deleted |

Example: a working-with-children check is two configured fields (a yes/no question, then a
sensitive card-number field that is shown only when the answer is yes, plus an optional expiry
date with a minimum of `today`). No such check exists in code.

## How it works

- **Resolution** (`WorkflowResolver`): for a site and visitor type, the active fields whose
  assignments match (null = any). If several assignments match one field, the most specific wins
  (site + type > site > type > organisation-wide), so a site can make an organisation field
  required or reorder it without duplicating it. Policies apply if any assignment matches; the
  current (highest) version is used.
- **Conditions** (`ConditionEvaluator`): `{"match": "all"|"any", "rules": [{field, operator, value}]}`
  with operators equals / not equals / is one of / filled / empty. Rules refer to earlier
  fields; a hidden field is never required and its submitted value is discarded.
- **Validation** (`ResponseValidator`): server-side Laravel rules built from the definition,
  plus a check that every applicable policy's *current* version was accepted. Images go through
  upstream's `SafeRasterImage` (raster only, metadata stripped). One implementation for every
  channel.
- **Recording** (`WorkflowRecorder`): in one transaction, sets `visit_visitor.visitor_type_id`,
  stores answers in `visit_responses`, and stores `policy_acceptances` (visit, visitor, exact
  policy version, time, channel). Answers snapshot the field key, label and type, so editing or
  deleting a field never rewrites history. Values of sensitive fields are encrypted with the
  app key. Files go to the private `local` disk under `evidence/<visit>/`, never the public disk.
  The audit entry records counts and policy version ids, never answer values.
- **Policy versions** are immutable (the model refuses update/delete; the DB restricts deletes).
  Each has a SHA-256 `content_hash` of title, text and acceptance wording, so an auditor can
  confirm exactly what was accepted. Re-publishing identical wording is refused.
- **Evidence retention**: new organisation setting *Keep evidence files for (days)*, default 365.
  `visitorportal:evidence:purge` (daily, 03:50) deletes evidence files past that period and any
  evidence file no longer referenced by an answer. The second sweep is needed because upstream's
  visit purge deletes rows in bulk, so answer rows vanish by cascade without touching files.
- **Audit**: visitor type, field and policy create/update/delete, policy version publishing,
  workflow recording and evidence purges.

## Decisions made in this phase

| Decision | Reason |
|---|---|
| `visit_visitor.visitor_type_id` added now (planned for Phase 4) | Answers and acceptances belong to an attendance; its visitor type is part of that record. Additive column, `nullOnDelete`. |
| One permission for types, fields and policies | They are configured together by the same people; split later if needed. |
| Used visitor types and published policies cannot be deleted, only deactivated | History references them. |
| Host requirement "none" is stored but not yet acted on | Upstream visits require a host user. Phase 4 decides how a host-less visit is created (e.g. a site reception account). |
| Evidence files are not compressed | Image re-encoding needs GD/Imagick, which the image does not ship. Revisit with the kiosk photo capture (Phase 5). |
| No "needs verification" / "has expiry" flags | Expiry is a date field with a minimum; verification has no consumer yet. Add when a phase needs them. |
| Answers are not yet shown in the visit screens | Comes with the reception/roll-call views that use them (Phases 5 and 9). |

## Tests

`tests/Feature/Fork/VisitorWorkflowEngineTest.php` (13) and `VisitorWorkflowAdminTest.php` (6):
site availability and deactivation, most-specific assignment and ordering, policy scope and
current version, version immutability and identical-republish refusal, conditional
visibility/requirement, server-side type validation, policy acceptance of the current version
only, recording with encryption, private evidence storage and value-free audit, history surviving
field edits/deletes and new versions, attendance checks, evidence purge (expired, orphaned,
in-progress kept), configuration auditing, and the admin screens (create type, conditional field
with assignments, key validation, policy create and publish, delete blocked when used, access
denied without permission).
