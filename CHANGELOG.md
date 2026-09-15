# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Every finding now carries a **priority** next to its verdict: `critical`, `high`, `medium`, `low`,
  or `none` for a package the report does not flag. The verdict sets a base level — `abandoned` and
  `silent` start at `critical`, `pinned` and `old-promise` at `high`, `stale` at `medium` — which
  drops one step for a transitive package and one more for a development-only one, never below
  `low`. A package nothing in the project reaches counts as transitive.
- `--format=json`: each finding gains `priority`, `direct` and `dev`, and the document gains a
  `priorities` object with all five counts next to `counts`. These are additions, so the `schema`
  number stays `1`.
- The exit code, `--fail-on` and the baseline are unchanged and still read the verdict alone.
- Every remaining format now names the priority next to the verdict, always as
  `<verdict> (<priority>)`. `--format=github` puts it in each annotation's title
  (`lockrot: abandoned (critical)`); `--format=gitlab` opens each issue's description with it;
  `--format=markdown` gains a `Priority` first column; and `--format=sarif` carries each result's
  priority as `rank` — the 0.0–100.0 number SARIF 2.1.0 defines for it, `critical` being `100.0` —
  alongside `properties.priority`, `properties.direct` and `properties.dev`.
- Nothing that decides an outcome reads the priority: the annotation level, the GitLab severity and
  fingerprint, and the SARIF `ruleId` and `level` all still follow the verdict alone, and the SARIF
  document still validates against the official 2.1.0 schema.

### Changed

- The report is ordered by priority first, then verdict severity, then direct dependencies ahead of
  transitive ones, then package name. The install-time summary shows the same order, so its 10-line
  budget now goes to the packages that apply most directly to the project.
- The default `table` format is now a width-aware list grouped by priority instead of a five-column
  box table that only read on a very wide terminal. Groups come highest priority first, headed by
  their level and count (`not flagged (N)` for the rows `--all` adds); each finding takes one line
  for its verdict, package, version and requirement chain and as many as it needs for its evidence,
  wrapped to the terminal with continuation lines indented past the verdict column. Critical and
  high rows are marked in red, medium in yellow. The summary block keeps its wording and order and
  gains a `priority: …` line right after the counts, printed whenever the run flagged anything; its
  lines wrap to the terminal too, at the full width and with no indent. The format id and the
  machine formats are unchanged.
- The terminal width is taken from `COLUMNS` when it is set, otherwise from the console itself,
  falling back to 120 columns and never going below 40.
- The evidence for the `abandoned` verdict now reads `marked abandoned by its repository` or
  `marked abandoned in composer.lock`, and the SARIF rule description says the same, so *flagged*
  carries one meaning across lockrot's output: a finding lockrot itself raised.
- Package metadata and community files for the first Packagist release: a `description` and
  `keywords` written for search, a `homepage`, `support.docs` and `support.security`, plus
  `SECURITY.md`, `CONTRIBUTING.md`, issue and pull-request templates, and a Dependabot
  configuration.

### Fixed

- `--format=json`, `sarif`, `gitlab`, `github` and `markdown` are now written raw, so a `<` in a
  constraint or a package name reaches the parser on the other end exactly as lockrot produced it.

## [0.1.0] - 2026-09-15

- `composer lockrot` (alias `composer rot`) command reporting dependency rot in `composer.lock`.
- Signals S1–S6: abandoned flag, no stable release, repository archived, no repository push,
  old release with an open-ended PHP constraint, and pinned branch/hash snapshots.
- Built-in finished-package allowlist (`resources/finished-packages.json`) plus a project-level
  `extra.lockrot.ignore` list with mandatory reason and optional expiry.
- Install-time summary on Composer's `PRE_OPERATIONS_EXEC`: `composer require`/`update`/`install`
  print a block of at most 10 lines for the packages the transaction is about to install or update,
  above Composer's own operations list. Bounded by a 5-second budget, and it never fails an install
  — an error lockrot cannot interpret becomes one `lockrot: install-time check skipped: …` line.
  The block is silent only when the transaction was both checked and clean: if nothing is flagged
  but a lookup failed (exhausted budget, unreachable repository), a shorter
  `lockrot: N of M changed packages could not be checked` block carries the reason instead.
- Two new `extra.lockrot` keys: `install-time` (`on`/`off`, default `on`) and `install-time-strict`
  (default `false`), which applies `fail-on` at install time and stops the transaction before any
  operation runs. `LOCKROT_DISABLE=1` still silences everything.
- The install-time pass's time budget is configurable via `extra.lockrot.install-time-budget`
  (integer seconds, 1–120, default 5); like the other install-time keys it has no CLI option or
  environment override.
- The install-time summary's `via` chain now resolves even when the lock on disk predates the
  transaction's own packages — a `--dry-run` update, or a project's very first lock — instead of
  showing no chain for a package new to the dependency graph.
- The "Repository metadata unavailable" note now breaks out each distinct reason with its own
  count when a run hits more than one, e.g. `Repository metadata unavailable for 4 packages: not
  checked: install-time budget exhausted (3); connection refused (1)`, instead of only naming the
  first reason it reached.
- `table` (default) and `--format=json` output.
- `--format=github` prints GitHub Actions workflow commands, one per finding, so flagged packages
  appear as annotations on their own line of `composer.lock` in a pull request; findings at or
  above `fail-on` are annotated as errors, the rest of the flagged ones as warnings, and the rows
  only `--all` shows as notices.
- `--format=sarif` prints a SARIF 2.1.0 document for `github/codeql-action/upload-sarif`, with one
  rule per verdict, `composer.lock` line numbers, and stable per-package fingerprints so code
  scanning can track a finding across runs. Exit codes are unchanged by the chosen format.
- Baseline file: `composer lockrot --generate-baseline` writes `lockrot-baseline.json` next to
  `composer.json` (path configurable via `--baseline` or `extra.lockrot.baseline`), and every later
  run compares against it, so `--fail-on` only trips on findings that are new or worse than the
  ones the project accepted. Matching is by package name, so a version bump that keeps the same
  verdict stays accepted; `first_seen` survives regeneration; baseline entries whose package has
  left the lock are reported as stale, never failed on. The table gains a
  `baseline: N known · M new · K worsened · S stale` line and marks rows `(baseline)`/`(was stale)`,
  the JSON report a `baseline` object, and SARIF a `properties.baseline` per result — with known
  findings reported at notice/note level so annotations keep matching the exit code.
  `install-time-strict` honours the same comparison. The baseline is the only file lockrot writes,
  only on that explicit flag, and it is written atomically; an unreadable, schema-invalid or
  unwritable baseline is exit 2, never a silently ungated run.
- `--format=gitlab` prints a GitLab Code Quality JSON report (one issue per flagged finding, every
  finding with `--all`), so `artifacts.reports.codequality` puts findings inline in a merge
  request's diff. Severity mirrors the GitHub/SARIF level (`major`/`minor`/`info`); each issue's
  fingerprint is a stable hash of the package name and verdict so it survives a version bump or a
  reformatted lock. Report notes are not representable in this format and are dropped; use
  `--format=json` for them.
- `--format=markdown` prints a PR-comment-shaped report — heading, a findings table, notes as
  bullets, a `<sub>` summary footer — for `composer lockrot --format=markdown > comment.md` piped
  into `gh pr comment --body-file`. A clean run prints a one-line heading with no table; with a
  baseline, a second line carries the same known/new/worsened/stale counts as the table format, and
  a verdict is bold only when it is not already accepted by the baseline.
- Exit codes 0/1/2 driven by `--fail-on`, with network failures defaulting to exit 0 unless
  `--strict-network` is set.
- Package metadata is loaded through the repositories configured for the project
  (`ComposerRepository::loadPackages()`), in their configured order — Packagist by default, but
  Private Packagist, Satis instances and mirrors are honoured the same way, with Composer's own
  authentication and proxy settings. The first repository to answer for a package name wins, and a
  repository that could not answer hands the name on to the next one. Composer's own metadata cache
  is reused and revalidated on every run; GitHub repository-activity responses keep a fixed 24-hour
  cache in Composer's cache directory.
- Repository metadata is loaded in two passes: tagged releases first, the `~dev` branch file only
  for packages with no tagged release — roughly half the requests on a cold cache (wallabag:
  403 → 206).
- `--offline` serves both from cache and never opens a connection, including when lockrot runs as a
  Composer plugin. A package with no cached metadata is reported as unavailable rather than as
  absent from the repository, so an empty cache cannot read as a clean result.
- Optional GitHub token (`GITHUB_TOKEN`/`LOCKROT_GITHUB_TOKEN`/Composer `github-oauth`) for
  repository activity signals; runs without one at a reduced candidate budget.
- Standalone PHAR build (`build/lockrot.phar`) for use without adding a Composer dependency.
- `lockrot.phar self-update` replaces the running PHAR with the latest GitHub release: it reads
  `releases/latest`, compares the tag against the running version with Composer's own semver, then
  downloads the archive and the `lockrot.phar.sha256` published beside it, refuses any download
  whose hash does not match, checks that the PHP runtime can open it, and swaps it in with the old
  file's permissions. `--check` reports without downloading and exits 1 when an update is available,
  so CI can notice a release; `--force` reinstalls the current version. Every failure is exit 2 with
  the running PHAR untouched and the temporary file removed; there is no rollback in 0.1, since
  earlier releases stay downloadable from GitHub. The command exists only in the PHAR — a plugin
  install is updated with `composer update somework/lockrot` — and it needs write access to the
  PHAR's own directory and nothing else.
- The PHAR now answers to command names (`lockrot`, still the default, and `self-update`) instead of
  being a single-command application. Composer's own commands and the inspected project's
  `composer.json` scripts are deliberately not registered, so `lockrot.phar list` shows lockrot's
  two commands and nothing in that project can be installed, updated or executed through the PHAR.
- Configuration in `extra.lockrot` is validated against `resources/lockrot-config.schema.json`;
  threshold values must be integers.
- Releases publish `lockrot.phar` with a `lockrot.phar.sha256` checksum; GPG signing and a Docker
  image are deferred to a later release.
- The standalone PHAR (and any lock-only run without an existing Composer instance) sets
  `COMPOSER_ROOT_VERSION=1.0.0` when the variable is unset: lockrot never reads the root package's
  version, so Composer's VCS probing (`git`/`hg`/`fossil`/`svn`, about 5 of the 6 seconds a PHAR run
  took on a lock-only directory) and its "could not detect the root package version" warning on
  stderr are skipped.
- The "GitHub token not set" note counts packages whose repository activity was checked, not
  distinct repositories, so packages sharing one repository are no longer under-reported.

[Unreleased]: https://github.com/somework/lockrot/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/somework/lockrot/releases/tag/v0.1.0
