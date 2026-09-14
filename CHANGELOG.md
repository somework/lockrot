# Changelog

## 0.1.0 (unreleased)

- `composer lockrot` (alias `composer rot`) command reporting dependency rot in `composer.lock`.
- Signals S1–S6: abandoned flag, no stable release, repository archived, no repository push,
  old release with an open-ended PHP constraint, and pinned branch/hash snapshots.
- Built-in finished-package allowlist (`resources/finished-packages.json`) plus a project-level
  `extra.lockrot.ignore` list with mandatory reason and optional expiry.
- `table` (default) and `--format=json` output.
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
