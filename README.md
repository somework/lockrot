# lockrot

Composer warns only about packages whose maintainers set the `abandoned` flag. Packages that
simply stopped — no release, no repository activity — get no warning, and old releases with
open-ended constraints like `>=5.3.0` are accepted on any modern PHP. `lockrot` reports this rot
in `composer.lock`: packages that are abandoned, silent for years, pinned to a branch snapshot, or
released long before the PHP version the constraint claims to support — with the evidence, the
data date, and the dependency chain that pulled each one in.

## Install

`lockrot` is not on Packagist yet. Until it is published there, use the standalone PHAR or
`require` it straight from the GitHub repository.

### As a Composer plugin

```bash
composer require --dev somework/lockrot
composer config allow-plugins.somework/lockrot true
```

Composer >= 2.2 asks for plugin permission on first `require`/`install`; running the second
command (or answering "yes" to the prompt) allows the plugin to run. This installs `composer
lockrot` (alias `composer rot`) in the project.

### As a standalone PHAR

No install, no dependency added to your project. Requires PHP >= 7.4 to run the PHAR itself — the
same floor as the plugin.

```bash
curl -fsSL -o lockrot.phar https://github.com/somework/lockrot/releases/latest/download/lockrot.phar
php lockrot.phar -d /path/to/project --target-php=8.4
php lockrot.phar --version   # prints: lockrot 0.1.0
```

Every release ships `lockrot.phar.sha256` next to the PHAR; verify the download with
`sha256sum -c lockrot.phar.sha256`. GPG signatures and a Docker image are planned for a later
release.

The PHAR always runs the inspected project with `--no-plugins`: it reads `composer.lock` and
`composer.json` and never needs that project's Composer plugins. It also never writes to
`composer.json` or `composer.lock`.

(Releases do not exist yet — this URL will resolve once the first tag is published.)

## One-command demo

```bash
composer lockrot --target-php=8.4
```

Run against `tests/fixtures/apps/wallabag_wallabag` (a real, public `composer.lock`), this is the
actual output:

```
GITHUB_TOKEN=$(gh auth token) php bin/lockrot -d tests/fixtures/apps/wallabag_wallabag --target-php=8.4
```

```
+--------------------------------------+------------+-------------+--------------------------------------------------------------+-------------------------------------------------------------+
| Package                              | Version    | Verdict     | Evidence                                                     | Via                                                         |
+--------------------------------------+------------+-------------+--------------------------------------------------------------+-------------------------------------------------------------+
| behat/transliterator                 | v1.5.0     | abandoned   | flagged abandoned by its repository; last release 2022-03-30 | stof/doctrine-extensions-bundle > gedmo/doctrine-extensions |
|                                      |            |             | (4.5 years ago); repository archived on GitHub; released     |                                                             |
|                                      |            |             | 2022-03-30, before PHP 8.4 GA (2024-11-21); php constraint   |                                                             |
|                                      |            |             | ">=7.2" has no upper bound                                   |                                                             |
| doctrine/annotations                 | 2.0.2      | abandoned   | flagged abandoned by its repository                          | sensio/framework-extra-bundle                               |
| doctrine/cache                       | 2.2.0      | abandoned   | flagged abandoned by its repository; last release 2022-05-20 | doctrine/doctrine-bundle                                    |
|                                      |            |             | (4.3 years ago)                                              |                                                             |
| hoa/compiler                         | 3.17.08.08 | abandoned   | flagged abandoned by its repository; last release 2017-08-08 | wallabag/rulerz > hoa/ruler                                 |
|                                      |            |             | (9.1 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2021-04-29 (5.4 years ago)                                   |                                                             |
| hoa/consistency                      | 1.17.05.02 | abandoned   | flagged abandoned by its repository; last release 2017-08-29 | wallabag/rulerz                                             |
|                                      |            |             | (9.0 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2021-04-28 (5.4 years ago); released 2017-05-02, before PHP  |                                                             |
|                                      |            |             | 8.4 GA (2024-11-21); php constraint ">=5.5.0" has no upper   |                                                             |
|                                      |            |             | bound                                                        |                                                             |
| hoa/event                            | 1.17.01.13 | abandoned   | flagged abandoned by its repository; last release 2017-08-30 | wallabag/rulerz > hoa/consistency > hoa/exception           |
|                                      |            |             | (9.0 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2021-04-29 (5.4 years ago)                                   |                                                             |
| hoa/exception                        | 1.17.01.16 | abandoned   | flagged abandoned by its repository; last release 2017-08-30 | wallabag/rulerz > hoa/consistency                           |
|                                      |            |             | (9.0 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2021-04-29 (5.4 years ago)                                   |                                                             |
| hoa/file                             | 1.17.07.11 | abandoned   | flagged abandoned by its repository; last release 2017-07-11 | wallabag/rulerz > hoa/ruler                                 |
|                                      |            |             | (9.2 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2018-01-23 (8.6 years ago)                                   |                                                             |
| hoa/iterator                         | 2.17.01.10 | abandoned   | flagged abandoned by its repository; last release 2017-01-10 | wallabag/rulerz > hoa/ruler > hoa/compiler                  |
|                                      |            |             | (9.7 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2021-04-29 (5.4 years ago)                                   |                                                             |
| hoa/math                             | 1.17.05.16 | abandoned   | flagged abandoned by its repository; last release 2017-05-16 | wallabag/rulerz > hoa/ruler > hoa/compiler                  |
|                                      |            |             | (9.3 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2021-04-29 (5.4 years ago)                                   |                                                             |
| hoa/protocol                         | 1.17.01.14 | abandoned   | flagged abandoned by its repository; last release 2017-01-14 | wallabag/rulerz > hoa/ruler                                 |
|                                      |            |             | (9.7 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2021-04-29 (5.4 years ago)                                   |                                                             |
| hoa/regex                            | 1.17.01.13 | abandoned   | flagged abandoned by its repository; last release 2017-01-13 | wallabag/rulerz > hoa/ruler > hoa/compiler                  |
|                                      |            |             | (9.7 years ago); repository archived on GitHub; last push    |                                                             |
|                                      |            |             | 2018-07-20 (8.2 years ago)                                   |                                                             |
+--------------------------------------+------------+-------------+--------------------------------------------------------------+-------------------------------------------------------------+
... (188 more rows omitted)
+--------------------------------------+------------+-------------+--------------------------------------------------------------+-------------------------------------------------------------+
200 packages checked · abandoned 19 · silent 8 · pinned 4 · old-promise 41 · stale 3 · unknown 0 · finished 18 · ok 107
Data as of 2026-09-13 (package repositories, GitHub). Run composer lockrot --format=json for details.
```

"Data as of" is the date the report was generated (UTC). wallabag is used because its lock file is
public and large, not to single it out.

Package metadata comes from the repositories configured in the project's `composer.json`, read
through Composer's own repository layer (`ComposerRepository::loadPackages()`) — Packagist by
default, but Private Packagist, Satis instances and mirrors are honoured the same way, along with
Composer's own authentication and proxy settings. Composer's metadata cache is reused and
revalidated (`If-Modified-Since`) on every run, which is why the repository side of "Data as of"
tracks the run itself; GitHub repository-activity data keeps the timestamp of its own 24-hour cache
and can lag behind by up to a day. Metadata is fetched in two passes — tagged releases first, then
the `~dev` branch file only for packages with no tagged release at all — roughly halving requests
on a cold cache.

Only repositories that publish a `metadata-url` (the Composer v2 "p2" protocol) are read one
package file at a time, which is what keeps memory flat on a large lock file. A repository without
one — a Composer v1-style or static repository, including `packages.json`-only Satis output — is
loaded whole by Composer before any name can be looked up, so its full package list is held in
memory for the run.

The same run with `--format=json` (first ~25 lines, up to the first flagged package):

```json
{
    "lockrot": {
        "version": "0.1.0",
        "schema": 1
    },
    "generated_at": "2026-09-13T22:52:46+00:00",
    "packages_checked": 200,
    "not_from_composer_repository": 0,
    "network_failures": false,
    "counts": {
        "abandoned": 19,
        "silent": 8,
        "pinned": 4,
        "old-promise": 41,
        "stale": 3,
        "unknown": 0,
        "finished": 18,
        "ok": 107
    },
    "notes": [],
    "findings": [
        {
            "package": "behat/transliterator",
            "version": "v1.5.0",
            "verdict": "abandoned",
            ...
        }
        // ... 199 more findings, each with its own "signals", "chain", "evidence" and "data_date"
    ]
}
```

## Install-time summary

With the plugin installed, `composer require`, `composer update` and `composer install` print a
compact block for the packages that transaction is about to install or update — the new package and
everything it drags in, not the whole lock file. Composer fires the event lockrot listens on before
it prints its own operations list, so the block appears above it. This is the real stderr of
`composer require phpzip/phpzip:2.0.8` in a fresh project:

```
Writing lock file
Installing dependencies from lock file (including require-dev)
lockrot: dependency rot in 4 of 4 changed packages
  silent      grandt/binstring 1.0.0: last release 2015-08-13 (11.1 years ago); last push 2015-08-13 (11.1 years ago); released 2015-08-13, before PHP 8.4 GA (2024-11-21); php constraint ">=5.0" has no upper bound (via phpzip/phpzip)
  silent      grandt/phpzipmerge 1.0.4: last release 2015-08-18 (11.1 years ago); last push 2015-08-18 (11.1 years ago); released 2015-08-18, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound (via phpzip/phpzip)
  silent      grandt/relativepath 1.0.2: last release 2015-05-14 (11.3 years ago); last push 2020-04-01 (6.5 years ago); released 2015-05-14, before PHP 8.4 GA (2024-11-21); php constraint ">=5.0" has no upper bound (via phpzip/phpzip)
  silent      phpzip/phpzip 2.0.8: last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago); released 2015-11-16, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound
Run composer lockrot for details.
Package operations: 4 installs, 0 updates, 0 removals
```

- **At most 10 lines**, always: header, one line per flagged package (most severe first), at most
  two notes, footer. Beyond that the list is cut with `… and N more`.
- **Silent only when the transaction was both checked and clean.** A package whose metadata never
  arrived is reported as `unknown`, which is not a finding — so if nothing is flagged *but* a lookup
  failed, a shorter block is printed instead of nothing, and silence never has to be second-guessed:

  ```
  lockrot: 4 of 4 changed packages could not be checked
    note: Repository metadata unavailable for 4 packages: not checked: install-time budget exhausted
  Run composer lockrot for details.
  ```

- **5-second budget.** The install-time pass has a hard time budget so it cannot hold up a
  `composer install`. A package whose metadata was never requested is reported as
  `not checked: install-time budget exhausted`, and a skipped GitHub round adds the note
  `repository activity not checked: install-time budget exhausted`; both reach you through the block
  above. In practice `composer require`/`update` is served from the metadata Composer has just
  fetched for the same packages, in the same process; only a cold `composer install` from an
  existing lock starts from nothing.
- **Never fails the install.** A failed lookup — an unreachable repository, an exhausted budget — is
  reported, not raised: it is data lockrot did not get, not a reason to stop. Only an error lockrot
  cannot interpret at all (a malformed `extra.lockrot`, an unreadable `composer.lock`, a bug in
  lockrot) becomes a single `lockrot: install-time check skipped: …` line — and even then the
  install continues. The one exception is `install-time-strict`, below.
- **Turning it off:** `extra.lockrot.install-time: "off"` in `composer.json` disables it for the
  project; `LOCKROT_DISABLE=1` disables all of lockrot for a single command.

`install-time-strict: true` turns the summary into a gate: when a finding reaches the `fail-on`
threshold, lockrot stops the transaction before any operation runs — see [Exit
codes](#exit-codes) for exactly what that leaves behind.

## What the verdicts mean

| Verdict | Meaning | Signals |
|---|---|---|
| `abandoned` | The package's Composer repository flags it abandoned (Packagist by default), or its GitHub repository is archived | S1 or S3 |
| `silent` | No stable release for at least `release-high-years` (default 5y) **and** no repository push for at least `push-high-years` (default 5y); an archived repository is reported as `abandoned` instead | S2 high AND S4 high, NOT S1, NOT S3 |
| `pinned` | Installed version is a branch snapshot (`dev-*` or `#hash`), or the package has no stable release at all | S6 |
| `old-promise` | The installed version was released before the target PHP's GA date, and its `require.php` constraint is open-ended (`>=N`, `*`) for that target | S5 |
| `stale` | Old release or old push, but not old enough (or not on both fronts) for `silent` | one of S2/S4 |
| `unknown` | No data could be obtained (not found in any configured Composer repository, or all lookups failed) | — |
| `finished` | Matched the built-in or project allowlist — the package is complete by design, not neglected | allowlist match |
| `ok` | None of the above | — |

Verdict precedence when several signals apply to the same package:
`abandoned > silent > pinned > old-promise > stale > unknown > finished > ok`.

Every finding's evidence line states the concrete fact (release date, push date, constraint
string) and the report footer states the data date — no severity words beyond the verdict names
above.

## Configuration

All keys live under `extra.lockrot` in `composer.json`. CLI options win over environment
variables, which win over `composer.json`. The shape of `extra.lockrot` is validated against
[`resources/lockrot-config.schema.json`](resources/lockrot-config.schema.json); unknown keys are
allowed, but the keys below must have the listed type — in particular, the four threshold keys
must be JSON integers (`3`, not `"3"`).

| `extra.lockrot` key | Default | Meaning |
|---|---|---|
| `fail-on` | `none` | Exit 1 threshold: `none`, `stale`, `old-promise`, `pinned`, `silent`, `abandoned` |
| `target-php` | running PHP / `config.platform.php` | PHP version used for the S5 "old promise" check, e.g. `"8.4"` |
| `format` | `table` | `table` or `json` |
| `include-dev` | `false` | Also check `packages-dev` |
| `install-time` | `on` | `on` or `off`: print the [install-time summary](#install-time-summary) during `composer require`/`update`/`install` |
| `install-time-strict` | `false` | Apply `fail-on` at install time too, stopping the transaction instead of only reporting |
| `release-warn-years` / `release-high-years` | `3` / `5` | Integer thresholds for "no stable release" (S2) |
| `push-warn-years` / `push-high-years` | `3` / `5` | Integer thresholds for "no repository push" (S4) |
| `ignore` | `[]` | Project allowlist, see [Allowlist](#allowlist) below |

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

### Environment overrides

| Variable | Overrides |
|---|---|
| `LOCKROT_DISABLE=1` | Skips lockrot entirely, exits 0 |
| `LOCKROT_FAIL_ON` | `fail-on` |
| `LOCKROT_TARGET_PHP` | `target-php` |
| `LOCKROT_GITHUB_TOKEN` / `GITHUB_TOKEN` | GitHub token for repository activity signals (S3/S4); Composer's `github-oauth.github.com` auth is used as a fallback if neither is set |

### CLI options (`composer lockrot`)

| Option | Meaning |
|---|---|
| `--format=table\|json` | Output format |
| `--fail-on=none\|abandoned\|silent\|pinned\|old-promise\|stale` | Exit-1 threshold for this run |
| `--target-php=8.4` | PHP version for the S5 check |
| `--dev` | Include `packages-dev` |
| `--all` | Show every checked package, not only flagged ones |
| `--offline` | Never reach the network: lockrot sets `COMPOSER_DISABLE_NETWORK=1` and rebuilds the configured repositories behind it (in plugin mode Composer has already built its own, network-enabled ones before any command runs), so repository metadata is served from Composer's own cache and GitHub activity from lockrot's cache. A package missing from the cache is reported as unavailable, not as absent from the repository |
| `--strict-network` | Exit 1 (see [Exit codes](#exit-codes)) when a configured repository or GitHub could not be reached |

Repository metadata is cached and revalidated by Composer itself, under Composer's own cache
directory — lockrot adds no cache of its own for it, and there is no `--refresh` or `cache-ttl` knob
to bypass or resize it. GitHub repository-activity responses are cached separately under Composer's
cache directory, in a `lockrot/` subfolder, with a fixed 24-hour TTL. When Composer's cache is
disabled (`composer --no-cache`), GitHub responses are kept in memory for the run only and nothing
is written to disk.

### Testing hooks

`LOCKROT_TODAY` (e.g. `2026-09-14`) fixes the reference date used for every "years ago"
calculation; it is used by the test suite and is not part of the configuration contract.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | No finding reached the `fail-on` threshold (or `fail-on=none`) |
| `1` | A finding reached or exceeded the `fail-on` threshold |
| `2` | Tool or configuration error (bad `composer.json`/`composer.lock`, invalid config value) |

A network failure (a configured Composer repository or GitHub unreachable) never turns into a
non-zero exit code on its own — it is reported as a note and the checks that could not run are
treated as absent evidence — unless `--strict-network` is passed, in which case it exits `1`. This
also covers `--offline` runs where a locked package has no cached metadata: it is reported as a
failure, not silently skipped. A `composer.lock` entry that Composer's own loader cannot load
(missing `name`/`version`, an unnormalizable version, or a malformed entry) stops the report with
exit `2` rather than being skipped.

As a Composer plugin, a `composer.json` that Composer itself cannot parse never reaches lockrot at
all: Composer parses the project's manifest while collecting plugin commands, before any plugin
class is loaded, so it stops with its own exit `1` first — `composer lockrot` on a broken
`composer.json` exits `1`, not `2`. The standalone PHAR reads and validates `composer.json` itself,
so the same failure there is exit `2`.

These codes are `composer lockrot`'s own. The [install-time summary](#install-time-summary) never
sets an exit code — it only prints — unless `install-time-strict` is on, in which case lockrot
stops the transaction and `composer require`/`update`/`install` exits `1` through Composer's own
error handling. What that leaves behind is Composer's behaviour, not lockrot's, and is worth
knowing: by the time the check runs, `composer require` has already added the package to
`composer.json` and written the new `composer.lock`, and Composer's automatic revert of those two
files is already disarmed at that point. So a blocked `composer require` leaves `composer.json` and
`composer.lock` updated with nothing installed in `vendor/`; `composer install` (or
`git checkout composer.json composer.lock`) is the way back.

## CI snippet

Any CI, with the plugin installed:

```bash
composer lockrot --fail-on=silent --target-php=8.4 --format=json
```

GitHub Actions, using the PHAR (no plugin installed, no dependency added to the project):

```yaml
- name: lockrot
  run: |
    curl -fsSL -o lockrot.phar https://github.com/somework/lockrot/releases/latest/download/lockrot.phar
    php lockrot.phar --fail-on=silent --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

Setting `GITHUB_TOKEN` lets lockrot check repository activity (S3/S4) for every candidate package
instead of the no-token budget described below.

## Allowlist

Packages that are "finished by design" (an interface package that will not release again, a
polyfill that is deliberately frozen, a metapackage) are not dependency rot. `lockrot` ships a
built-in allowlist at [`resources/finished-packages.json`](resources/finished-packages.json):
`psr/*`, `fig/*`, `symfony/polyfill-*`, `symfony/*-pack`, `ralouphie/getallheaders`, and
`paragonie/random_compat` (the intentionally empty `9.99.x` releases). Packages of Composer type
`metapackage` or `symfony-pack` count as finished automatically. Allowlisted packages report as
`finished`, taking precedence over every other verdict.

To silence a specific project dependency, add it to `extra.lockrot.ignore` in `composer.json`.
`package` and `reason` are mandatory; `expires` (`YYYY-MM-DD`) and `version` (pin the entry to one
exact version) are optional:

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

To propose an addition to the built-in list (a genuinely finished package used widely enough to
belong there), open a pull request against `resources/finished-packages.json` with the package
pattern and a one-line reason — the same shape as the existing entries.

## Limitations (v0.1)

- Repository activity (S3/S4) is checked on GitHub only. GitLab and Bitbucket are not queried
  (planned for v0.3).
- Without a GitHub token, only packages that already look like release candidates for `silent`
  (no stable release within `release-high-years`, not already `abandoned`) are checked against
  GitHub, capped at 50 packages per run; set `GITHUB_TOKEN` (or `LOCKROT_GITHUB_TOKEN`) to lift the
  cap. `lockrot` reports how many packages this affected.
- No transitive-exposure signal (S7: a package flagged because a package *it* depends on is
  abandoned/archived/silent) — only the flagged package itself is shown; the `Via`/`chain` column
  shows how it was pulled in, not the other direction.
- No baseline file (`--generate-baseline`) yet, so CI cannot yet accept existing findings while
  failing only on new ones — every run re-evaluates the full lock file against `fail-on`.

## Roadmap

- **v0.2**: a baseline file so CI can fail only on new or worsened findings,
  `--format=sarif`/`github`/`gitlab`, and a GitHub Action.
- **v0.3**: transitive exposure on parent packages (S7), `--format=markdown` for PR comments,
  GitLab/Bitbucket repository activity, and inspecting the `vendor/*/composer.lock` of bundled
  PHAR tools.

## Documentation

- [`tests/fixtures/README.md`](tests/fixtures/README.md) — real-world lock files for integration tests

## License

MIT — see [`LICENSE`](LICENSE).
