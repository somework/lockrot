# Configuration reference

Put project settings under `extra.lockrot` in `composer.json`:

```json
{
    "extra": {
        "lockrot": {
            "fail-on": "silent",
            "target-php": "8.4",
            "ignore": [
                { "package": "acme/legacy-bridge", "reason": "internal fork, tracked in ACME-123", "expires": "2027-01-01" }
            ]
        }
    }
}
```

CLI options win over environment variables, which win over `composer.json`.

The shape of `extra.lockrot` is validated against
[`resources/lockrot-config.schema.json`](../resources/lockrot-config.schema.json). Unknown keys are
allowed, but the keys below must have the listed type — in particular, the four threshold keys must
be JSON integers (`3`, not `"3"`).

## `extra.lockrot` keys

| Key | Default | Meaning |
|---|---|---|
| `fail-on` | `none` | Exit 1 threshold: `none`, `stale`, `old-promise`, `pinned`, `silent`, `abandoned` |
| `target-php` | `config.platform.php`, else the running PHP | PHP version used for the S5 "old promise" check, e.g. `"8.4"` |
| `format` | `table` | `table`, `json`, `github`, `sarif`, `gitlab` or `markdown`; see [ci.md](ci.md) |
| `include-dev` | `false` | Also check `packages-dev` (CLI: `--dev`) |
| `install-time` | `on` | `on` or `off`: print the [install-time summary](install-time.md) during `composer require`/`update`/`install` |
| `install-time-strict` | `false` | Apply `fail-on` at install time too, stopping the transaction instead of only reporting |
| `install-time-budget` | `5` | Integer seconds (1–120): the install-time pass's hard time budget |
| `baseline` | `lockrot-baseline.json` | Path to the [baseline](baseline.md) file, relative to `composer.json` or absolute |
| `release-warn-years` / `release-high-years` | `3` / `5` | Integer thresholds for "no stable release" (S2) |
| `push-warn-years` / `push-high-years` | `3` / `5` | Integer thresholds for "no repository push" (S4) |
| `ignore` | `[]` | Project allowlist, see below |

## Environment overrides

| Variable | Overrides |
|---|---|
| `LOCKROT_DISABLE=1` (or `true`) | Skips lockrot entirely, exits 0 |
| `LOCKROT_FAIL_ON` | `fail-on` |
| `LOCKROT_TARGET_PHP` | `target-php` |
| `LOCKROT_GITHUB_TOKEN` / `GITHUB_TOKEN` | GitHub token for repository-activity signals (S3/S4); Composer's `github-oauth.github.com` auth is used as a fallback if neither is set |

## CLI options

| Option | Meaning |
|---|---|
| `--format=table\|json\|github\|sarif\|gitlab\|markdown` | Output format. `table` (the default) is a width-aware list grouped by priority, not a box table. The format changes the output only; the exit code is the same for all six. See [ci.md](ci.md) |
| `--fail-on=none\|abandoned\|silent\|pinned\|old-promise\|stale` | Exit-1 threshold for this run |
| `--target-php=8.4` | PHP version for the S5 check |
| `--dev` | Include `packages-dev`. A development package is reported the same way a production one is, but it gets one [priority](verdicts.md) step lower |
| `--all` | Show every checked package, not only flagged ones. Adds a final `not flagged` group |
| `--offline` | Never reach the network: lockrot sets `COMPOSER_DISABLE_NETWORK=1` and rebuilds the configured repositories behind it (in plugin mode Composer has already built its own, network-enabled ones before any command runs), so repository metadata is served from Composer's own cache and GitHub activity from lockrot's cache. A package missing from the cache is reported as unavailable, not as absent from the repository |
| `--strict-network` | Exit 1 (see [ci.md](ci.md)) when a configured repository or GitHub could not be reached |
| `--generate-baseline` | Write this run's findings to the [baseline](baseline.md) file and exit 0, whatever `--fail-on` says — `--strict-network` is the one exception |
| `--baseline=<path>` | Baseline file to read (or, with `--generate-baseline`, to write); relative to `composer.json` or absolute. Wins over `extra.lockrot.baseline`. An empty `--baseline=` is a configuration error (exit `2`), never a silent fall-back to the default file |

`-d <dir>` points the standalone PHAR at a project; see [phar.md](phar.md).

## Caching

Repository metadata is cached and revalidated by Composer itself, under Composer's own cache
directory. lockrot adds no cache of its own for it, and there is no `--refresh` or `cache-ttl` knob
to bypass or resize it.

GitHub repository-activity responses are cached separately under Composer's cache directory, in a
`lockrot/` subfolder, with a fixed 24-hour TTL. When Composer's cache is disabled
(`composer --no-cache`), GitHub responses are kept in memory for the run only and nothing is written
to disk. More in [internals.md](internals.md).

## The allowlist

Packages that are finished by design — an interface package that will not release again, a polyfill
that is deliberately frozen, a metapackage — are not dependency rot. lockrot ships a built-in
allowlist at [`resources/finished-packages.json`](../resources/finished-packages.json): `psr/*`,
`fig/*`, `symfony/polyfill-*`, `symfony/*-pack`, `ralouphie/getallheaders`, and two pinned releases
of `paragonie/random_compat` (`9.99.99` and `9.99.100`, which are intentionally empty). Packages of
Composer type `metapackage` or `symfony-pack` count as finished automatically. An allowlist match is
checked before any signal, so an allowlisted package reports `finished` whatever its signals say.

To silence one project dependency, add it to `extra.lockrot.ignore`. `package` and `reason` are
mandatory; `expires` (`YYYY-MM-DD`) and `version` (pin the entry to one exact version) are optional.
An entry whose `expires` date has passed is skipped, and the package falls back to its normal
verdict:

```json
{
    "extra": {
        "lockrot": {
            "ignore": [
                { "package": "acme/legacy-bridge", "version": "1.2.3", "reason": "internal fork, replacement tracked in ACME-123", "expires": "2027-01-01" }
            ]
        }
    }
}
```

To propose an addition to the built-in list — a genuinely finished package used widely enough to
belong there — open a pull request against `resources/finished-packages.json` with the package
pattern and a one-line reason, the same shape as the existing entries.

## Testing hooks

`LOCKROT_TODAY` (e.g. `2026-09-14`) fixes the reference date used for every "years ago"
calculation. It is used by the test suite and is not part of the configuration contract.
