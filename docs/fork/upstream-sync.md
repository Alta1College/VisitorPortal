# Upstream synchronisation procedure

This fork tracks [P0etInc0de/VisitorPortal](https://github.com/P0etInc0de/VisitorPortal)
(GPL-3.0-or-later). Upstream security fixes must be reviewed and assimilated promptly.

## Remotes

```sh
git remote add upstream https://github.com/P0etInc0de/VisitorPortal.git
git fetch upstream --tags
```

`origin` is the fork. `upstream` is never pushed to.

## Baseline

| Item | Value |
|---|---|
| Upstream release | `v0.9.1` (`21a67d1`) |
| Fork initial commit | `116ebd2` — file tree identical to `v0.9.1` (verified with `git diff 116ebd2 21a67d1`, empty) |
| Merge base | `edabe4f` (Initial public release) |

Because the fork's first commit is a re-commit of the v0.9.1 tree rather than the upstream
commit itself, record a merge once so Git knows the histories are related:

```sh
git checkout develop
git merge -s ours --allow-unrelated-histories v0.9.1 -m "chore: record upstream v0.9.1 as merged baseline"
```

(`-s ours` changes no files; it only records ancestry so later `git merge v0.9.2` is a normal three-way merge.)

## Branches

- `develop` — the fork's default branch; fork releases are tagged from it.
- `upstream-sync/<tag>` — short-lived branch per upstream release being assimilated.
- Feature branches — `feature/<phase>-<name>`.

## Per upstream release

1. `git fetch upstream --tags`, read the upstream release notes and `docs/releases/`.
2. Classify each upstream commit: **security**, **bug fix**, **feature**, **refactor**.
3. `git checkout -b upstream-sync/vX.Y.Z develop && git merge vX.Y.Z`.
4. Resolve conflicts. Where fork code has diverged, port the upstream intent manually and note it
   in the merge commit message.
5. Run the full suite: `php artisan test`, `npm run build`, and the Playwright e2e specs where relevant.
6. Review migrations from upstream for clashes with fork migrations (fork migrations use the
   `2026_11_*_fork_*` naming prefix and only add tables/columns; they never alter upstream columns
   unless recorded in `docs/fork/upstream-patches.md`).
7. PR into `develop`; note the upstream tag in `CHANGELOG.md`.

### Security releases

Security-only releases are merged within 5 working days. If a full merge is blocked by
divergence, cherry-pick the security commits onto `develop` first and complete the merge later.

## Keeping divergence low

- New behaviour lives in feature-named folders in ordinary Laravel locations (`app/Kiosk`,
  `app/PublicVisit`, `app/Settings`, `app/Audit`) rather than a `Fork` namespace, so a feature
  can later be offered upstream without renaming.
- Every edit to an upstream file is listed in `docs/fork/upstream-patches.md` with the reason.
- Prefer config overlays, service-container bindings, events and new Filament resources over
  editing upstream classes.
