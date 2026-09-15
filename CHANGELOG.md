# Changelog

## 0.1.0 (unreleased)

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
