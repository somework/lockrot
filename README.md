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
`composer.json` or `composer.lock`. The only commands it offers are `lockrot` (the default, so the
name can be left out) and `self-update`; `php lockrot.phar list` shows them. Unlike `composer`, the
PHAR never walks up to a parent directory's project (Composer's `use-parent-dir` setting is not
honoured): run it from the project root or point it there with `-d`.

#### Keeping it updated

The PHAR updates itself from the latest GitHub release:

```bash
php lockrot.phar self-update          # download, verify the sha256, replace this file
php lockrot.phar self-update --check  # report only; exits 1 when an update is available
```

`self-update` downloads the release's `lockrot.phar.sha256` alongside the archive, refuses to
install anything whose hash does not match, and checks that the PHP runtime can open the download
before it replaces the running file. It needs write access to the directory the PHAR sits in — a
PHAR in `/usr/local/bin` wants `sudo`, or a manual download — and it writes nothing else. If the
update fails at any step, the running `lockrot.phar` is left exactly as it was; there is no
rollback in 0.1, because every earlier release stays downloadable from GitHub. Set `GITHUB_TOKEN`
or `LOCKROT_GITHUB_TOKEN` to lift GitHub's 60-requests-per-hour anonymous limit if you check often.

An update killed part-way through (a `Ctrl-C` between the download and the replace) can leave a
`lockrot.phar.<pid>-<id>.tmp.phar` file next to the PHAR. It is inert, and the next `self-update`
deletes any such file older than an hour, so there is nothing to clean up by hand.

`--check` is the CI-friendly half: it never downloads the archive, and exits `1` when a newer
release exists so a scheduled job notices.

The alternative to a downloaded PHAR is a global plugin install, which `composer global update`
keeps current:

```bash
composer global require somework/lockrot
composer global config allow-plugins.somework/lockrot true
```

That is a plugin, not a PHAR, so it also enables lockrot's install-time summary in **every** project
you run Composer in. `extra.lockrot` is read from the project being installed, not from the global
`composer.json`, so a global `install-time: off` has no effect: turn the summary off per project
with `"extra": {"lockrot": {"install-time": "off"}}`, or everywhere with `LOCKROT_DISABLE=1` in your
environment.

## One-command demo

```bash
composer lockrot --target-php=8.4
```

Run against `tests/fixtures/apps/wallabag_wallabag` (a real, public `composer.lock`), this is the
actual output:

```
COLUMNS=120 GITHUB_TOKEN=$(gh auth token) php bin/lockrot -d tests/fixtures/apps/wallabag_wallabag --target-php=8.4
```

```
critical (3)
  abandoned    sensio/framework-extra-bundle v6.2.10  direct
               flagged abandoned by its repository, replacement: Symfony; last release 2023-02-24 (3.6 years ago);
               repository archived on GitHub; last push 2023-02-24 (3.6 years ago); released 2023-02-24, before PHP 8.4
               GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  silent       javibravo/simpleue 2.1.0  direct
               last release 2017-11-15 (8.8 years ago); last push 2017-11-18 (8.8 years ago); released 2017-11-15,
               before PHP 8.4 GA (2024-11-21); php constraint ">=5.5" has no upper bound
  silent       mnapoli/piwik-twig-extension 3.0.0  direct
               last release 2020-04-24 (6.4 years ago); last push 2020-04-28 (6.4 years ago); released 2020-04-24,
               before PHP 8.4 GA (2024-11-21); php constraint ">=7.0" has no upper bound

high (58)
  abandoned    behat/transliterator v1.5.0  via stof/doctrine-extensions-bundle › gedmo/doctrine-extensions
               flagged abandoned by its repository; last release 2022-03-30 (4.5 years ago); repository archived on
               GitHub; released 2022-03-30, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2" has no upper bound
  abandoned    doctrine/annotations 2.0.2  via sensio/framework-extra-bundle
               flagged abandoned by its repository
  abandoned    doctrine/cache 2.2.0  via doctrine/doctrine-bundle
               flagged abandoned by its repository; last release 2022-05-20 (4.3 years ago)
  abandoned    hoa/compiler 3.17.08.08  via wallabag/rulerz › hoa/ruler
               flagged abandoned by its repository; last release 2017-08-08 (9.1 years ago); repository archived on
               GitHub; last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/consistency 1.17.05.02  via wallabag/rulerz
               flagged abandoned by its repository; last release 2017-08-29 (9.0 years ago); repository archived on
               GitHub; last push 2021-04-28 (5.4 years ago); released 2017-05-02, before PHP 8.4 GA (2024-11-21); php
               constraint ">=5.5.0" has no upper bound
... (66 more rows omitted)

200 packages checked · abandoned 19 · silent 8 · pinned 4 · old-promise 41 · stale 2 · unknown 0 · finished 18 · ok 108
priority: critical 3 · high 58 · medium 11 · low 2
Data as of 2026-09-15 (package repositories, GitHub). Run composer lockrot --format=json for details.
```

Findings are grouped by [priority](#priority), highest first, and each row wraps to the width of
the terminal, so nothing has to be read sideways. The width comes from `COLUMNS` when it is set,
otherwise from the console itself, falling back to 120 columns. `--all` adds a final `not flagged`
group with everything else in the lock.

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

The same run with `--format=json` (first ~35 lines, up to the first flagged package):

```json
{
    "lockrot": {
        "version": "0.1.0",
        "schema": 1
    },
    "generated_at": "2026-09-15T15:33:04+00:00",
    "packages_checked": 200,
    "not_from_composer_repository": 0,
    "network_failures": false,
    "counts": {
        "abandoned": 19,
        "silent": 8,
        "pinned": 4,
        "old-promise": 41,
        "stale": 2,
        "unknown": 0,
        "finished": 18,
        "ok": 108
    },
    "priorities": {
        "critical": 3,
        "high": 58,
        "medium": 11,
        "low": 2,
        "none": 126
    },
    "baseline": null,
    "notes": [],
    "findings": [
        {
            "package": "sensio/framework-extra-bundle",
            "version": "v6.2.10",
            "verdict": "abandoned",
            "priority": "critical",
            "direct": true,
            "dev": false,
            ...
        }
        // ... 199 more findings, each with its own "priority", "direct", "dev", "signals",
        // "chain", "evidence" and "data_date"
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
  two notes, footer. Beyond that the list is cut with `… and N more`. The budget counts lines as
  written, not rendered terminal rows — a long evidence line may still wrap past one row in a
  narrow terminal.
- **Silent only when the transaction was both checked and clean.** A package whose metadata never
  arrived is reported as `unknown`, which is not a finding — so if nothing is flagged *but* a lookup
  failed, a shorter block is printed instead of nothing, and silence never has to be second-guessed:

  ```
  lockrot: 4 of 4 changed packages could not be checked
    note: Repository metadata unavailable for 4 packages: not checked: install-time budget exhausted
  Run composer lockrot for details.
  ```

- **Time budget** (default 5 seconds). The install-time pass has a hard time budget so it cannot
  hold up a `composer install`. A package whose metadata was never requested is reported as
  `not checked: install-time budget exhausted`, and a skipped GitHub round adds the note
  `repository activity not checked: install-time budget exhausted`; both reach you through the block
  above. `composer require`/`update` of a few packages is served from the metadata Composer has
  just fetched for the same packages, in the same process, and fits comfortably. A `composer install`
  into an empty `vendor/` on a large lock — a fresh clone, a CI job — is the case that does not: from
  about 150 packages the budget runs out before every package is checked (a 200-package lock checks
  roughly 140–170 of them in 5 seconds, cold or warm, because Composer revalidates its metadata
  cache in sequential batches), and the block then says how many were not checked rather than
  reading as clean. The budget is configurable via `extra.lockrot.install-time-budget` (integer
  seconds, 1–120; see [Configuration](#configuration)) — raise it for a large lock that consistently
  runs out of time, or lower it for a stricter cap on install latency.
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

## Priority

The verdict says what was observed about a package. The priority says how much that applies to
*your* project — a package flagged the same way matters less when nothing in the project requires
it directly, and less again when it is only ever installed for development.

Three rules, in order:

1. A package the report does not flag (`unknown`, `finished`, `ok`) has priority `none`.
2. Otherwise the verdict sets the base level: `abandoned` and `silent` start at **critical**,
   `pinned` and `old-promise` at **high**, `stale` at **medium**.
3. The base drops one step when the package is transitive (nothing you require names it) and one
   more step when it is a development dependency. It never drops below **low**.

| Verdict | direct, prod | transitive, prod | direct, dev | transitive, dev |
|---|---|---|---|---|
| `abandoned`, `silent` | `critical` | `high` | `high` | `medium` |
| `pinned`, `old-promise` | `high` | `medium` | `medium` | `low` |
| `stale` | `medium` | `low` | `low` | `low` |
| `unknown`, `finished`, `ok` | `none` | `none` | `none` | `none` |

A package nothing in your `require`/`require-dev` can reach counts as transitive.

The priority orders the report — highest first, then by verdict severity, then direct dependencies
ahead of transitive ones, then by package name — and is carried in every format. **The exit code
and `--fail-on` stay on the verdict**: priority is there to tell you what to read first, not to
decide whether the build fails. `--dev` is what brings dev packages into the run at all; once they
are in, each of them sits one step below the same finding on a prod package.

In `--format=json` each finding carries `priority`, `direct` and `dev`, and the report carries a
`priorities` object with all five counts next to `counts`. The JSON `schema` number stays `1` —
these are additions, so anything already reading the document keeps working.

Every other format names it too, always as `<verdict> (<priority>)`: `table` groups the findings
under it, `github` puts it in each annotation's title, `gitlab` in each issue's description,
`markdown` in a first `Priority` column, and `sarif` carries it both as the result's `rank`
(0.0–100.0) and as `properties.priority`, `properties.direct` and `properties.dev`. Nothing that
decides an outcome moved: the annotation level, the GitLab severity, the SARIF `ruleId` and `level`
and the GitLab fingerprint all still read the verdict alone.

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
| `format` | `table` | `table`, `json`, `github` (workflow annotations) or `sarif` (SARIF 2.1.0), see [GitHub Actions](#github-actions) |
| `include-dev` | `false` | Also check `packages-dev` |
| `install-time` | `on` | `on` or `off`: print the [install-time summary](#install-time-summary) during `composer require`/`update`/`install` |
| `install-time-strict` | `false` | Apply `fail-on` at install time too, stopping the transaction instead of only reporting |
| `install-time-budget` | `5` | Integer seconds (1–120): the install-time pass's hard time budget, see [Install-time summary](#install-time-summary) |
| `baseline` | `lockrot-baseline.json` | Path to the baseline file, relative to `composer.json` or absolute, see [Baseline](#baseline) |
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
| `--format=table\|json\|github\|sarif\|gitlab\|markdown` | Output format. `table` (the default) is a width-aware list grouped by priority, not a box table — see [One-command demo](#one-command-demo). `github` prints GitHub Actions workflow commands so findings become annotations on `composer.lock`; `sarif` prints a SARIF 2.1.0 document for `upload-sarif` — see [GitHub Actions](#github-actions). `gitlab` prints a GitLab Code Quality JSON report — see [GitLab CI](#gitlab-ci). `markdown` prints a PR-comment-shaped report with `Priority` as its first column — see [Posting a PR comment](#posting-a-pr-comment). All six name the [priority](#priority) next to the verdict. The format changes the output only; the exit code is the same for all six |
| `--fail-on=none\|abandoned\|silent\|pinned\|old-promise\|stale` | Exit-1 threshold for this run |
| `--target-php=8.4` | PHP version for the S5 check |
| `--dev` | Include `packages-dev`. A dev package is flagged the same way a prod one is, but it gets one priority step lower — see [Priority](#priority) |
| `--all` | Show every checked package, not only flagged ones |
| `--offline` | Never reach the network: lockrot sets `COMPOSER_DISABLE_NETWORK=1` and rebuilds the configured repositories behind it (in plugin mode Composer has already built its own, network-enabled ones before any command runs), so repository metadata is served from Composer's own cache and GitHub activity from lockrot's cache. A package missing from the cache is reported as unavailable, not as absent from the repository |
| `--strict-network` | Exit 1 (see [Exit codes](#exit-codes)) when a configured repository or GitHub could not be reached |
| `--generate-baseline` | Write this run's findings to the baseline file and exit 0, whatever `--fail-on` says — `--strict-network` is the one exception. See [Baseline](#baseline) |
| `--baseline=<path>` | Baseline file to read (or, with `--generate-baseline`, to write); relative to `composer.json` or absolute. Wins over `extra.lockrot.baseline`. An empty `--baseline=` is a configuration error (exit `2`), never a silent fall-back to the default file |

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
| `2` | Tool or configuration error (bad `composer.json`/`composer.lock`, invalid config value, unreadable or unwritable [baseline](#baseline)) |

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

`lockrot.phar self-update` uses the same three codes with its own meanings: `0` for an installed
update or a build that is already current, `1` only for `--check` when a newer release exists, and
`2` for every failure (no published release, GitHub unreachable, checksum mismatch, an archive the
runtime cannot open, an unwritable directory, or running outside the PHAR). On `2` the running
`lockrot.phar` is untouched.

These codes are `composer lockrot`'s own. The [install-time summary](#install-time-summary) never
sets an exit code — it only prints — unless `install-time-strict` is on, in which case lockrot
stops the transaction and `composer require`/`update`/`install` exits `1` through Composer's own
error handling. What that leaves behind is Composer's behaviour, not lockrot's, and is worth
knowing: by the time the check runs, `composer require` has already added the package to
`composer.json` and written the new `composer.lock`, and Composer's automatic revert of those two
files is already disarmed at that point. So a blocked `composer require` leaves `composer.json` and
`composer.lock` updated with nothing installed in `vendor/`; `composer install` (or
`git checkout composer.json composer.lock`) is the way back.

## Baseline

A large project rarely starts clean. The baseline file records the findings you have already seen
and decided to live with, so CI fails only on what is **new** or has got **worse** since — without
turning `--fail-on` off and losing the check entirely.

```bash
composer lockrot --target-php=8.4 --generate-baseline
git add lockrot-baseline.json && git commit -m "chore: accept current dependency rot"
```

That writes `lockrot-baseline.json` next to `composer.json`, prints one line on stderr
(`lockrot: baseline written to lockrot-baseline.json (75 findings)`), nothing on stdout, and exits
`0` whatever `--fail-on` says — the run records findings, it does not judge them. **Commit the
file**: it is a statement about the project, and it is worth reviewing in a pull request like any
other change. It is also the only file lockrot ever writes, and only on this explicit flag.

`--strict-network` is the one exception to that exit `0`: if a configured repository or GitHub could
not be reached, the run still exits `1` after writing the file. A baseline generated from metadata
that never arrived would accept findings lockrot was not actually able to check.

```json
{
    "lockrot": {
        "version": "0.1.0",
        "schema": 1
    },
    "generated_at": "2026-09-14T00:00:00+00:00",
    "findings": {
        "behat/transliterator": {
            "version": "v1.5.0",
            "verdict": "abandoned",
            "first_seen": "2026-09-14"
        }
    }
}
```

Only flagged verdicts are recorded (`ok`, `finished` and `unknown` are not findings), entries are
sorted by package name so the diff stays reviewable, and `first_seen` is carried over when you
regenerate — the file keeps saying how long each finding has been tolerated.

Once the file exists, every normal run compares against it and says so:

```
200 packages checked · abandoned 19 · silent 8 · pinned 4 · old-promise 41 · stale 3 · unknown 0 · finished 18 · ok 107
baseline: 73 known · 1 new · 1 worsened · 0 stale (lockrot-baseline.json)
```

| Bucket | Meaning | Effect on the exit code |
|---|---|---|
| `known` | The baseline holds this package at this verdict or a worse one | Never fails the build. The table shows `abandoned (baseline)`, annotations drop to notice/note |
| `new` | Flagged now, absent from the baseline | Compared against `--fail-on` as usual |
| `worsened` | In the baseline, but at a lower verdict than today's | Compared against `--fail-on`. The table shows `abandoned (was stale)` |
| `stale` | In the baseline, no longer in `composer.lock` | Never fails the build. Reported as a note so you know the entry can go |

Matching is **by package name only**. The recorded version is informational, so bumping
`vendor/pkg` from `1.2.3` to `1.3.0` while it stays abandoned keeps it accepted; a package that
gets *worse* (`stale` → `abandoned`) is reported as worsened and fails the build again. Stale
entries are never cleaned up behind your back — regenerate the baseline when you want them gone.

**Generate the baseline with the same `--dev` setting your CI run uses.** `--dev` widens what is
*analysed*, not what counts as present: staleness is measured against the whole `composer.lock`,
`packages-dev` included, so a baseline generated with `--dev` never reports its dev entries as stale
on a run without it. The other direction does matter — a baseline generated *without* `--dev`
contains no dev findings, so a `--dev` run reports every one of them as new and fails.

A baseline lockrot cannot read is a configuration error, not an absent baseline: a malformed or
schema-invalid file, or a `--baseline`/`extra.lockrot.baseline` path that does not exist, exits `2`
rather than silently running ungated. (The default path simply not existing is not an error — that
is every project before its first `--generate-baseline`.) To start over from a file that has been
corrupted, delete it and generate a new one.

`install-time-strict` uses the same comparison: a finding the baseline already carries does not stop
a `composer require`. The compact block still lists it.

Install time never fails on a configuration problem, so it handles a bad baseline differently from
`composer lockrot`: an `extra.lockrot.baseline` pointing at a missing or unreadable file becomes the
usual single `lockrot: install-time check skipped: …` line, and because the check was skipped the
`install-time-strict` gate does not run for that install either. Fix the path or remove the key —
`composer lockrot` reports the same problem as exit `2` and is the quicker way to see it.

## CI snippet

Any CI, with the plugin installed:

```bash
composer lockrot --fail-on=silent --target-php=8.4 --format=json
```

With a committed baseline, the same command fails only on new or worsened findings — no extra flag
is needed, the file is picked up automatically:

```bash
composer lockrot --fail-on=silent --target-php=8.4        # exit 1 only on new or worsened findings
composer lockrot --fail-on=silent --target-php=8.4 --generate-baseline   # accept today's findings
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

### GitHub Actions

`--format=github` prints [workflow
commands](https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions),
one per finding, so every flagged package shows up as an annotation on its own line of
`composer.lock` in the pull request's Files changed view:

```yaml
- name: lockrot
  run: composer lockrot --format=github --fail-on=silent --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

Each annotation's title is `lockrot: <verdict> (<priority>)`, e.g. `lockrot: abandoned (critical)`,
so the [priority](#priority) is visible on the line itself:

```text
::error file=composer.lock,line=8010,title=lockrot%3A abandoned (critical)::sensio/framework-extra-bundle v6.2.10: flagged abandoned by its repository, replacement: Symfony
```

Findings at or above `--fail-on` are annotated as errors, everything else flagged as warnings, and
the rows that only `--all` shows as notices — so the annotation colour matches the exit code. The
priority never changes that: a `critical` finding below `--fail-on` is still a warning. With a
[baseline](#baseline) in place, findings it already carries drop to notices for the same reason.

GitHub renders only a limited number of annotations per step, so on a large lock file the
annotations are the headline and the step's own log holds every finding. The summary line at the
end of the output always states the full counts, and `--format=sarif` below uploads the complete
set.

`--format=sarif` prints a [SARIF 2.1.0](https://json.schemastore.org/sarif-2.1.0.json) document for
GitHub code scanning, which keeps the findings in the repository's Security tab and tracks them
across runs. The upload step needs the `security-events: write` permission:

```yaml
permissions:
  contents: read
  security-events: write

steps:
  - uses: actions/checkout@v4
  - name: lockrot
    run: composer lockrot --format=sarif --fail-on=silent --target-php=8.4 > lockrot.sarif
    env:
      GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  - name: Upload SARIF
    if: always()
    uses: github/codeql-action/upload-sarif@v3
    with:
      sarif_file: lockrot.sarif
```

`if: always()` keeps the upload running when `--fail-on` already failed the step. The exit codes
are the same for every format (see [Exit codes](#exit-codes)); only the output changes. Both
formats point at `composer.lock` in the checkout root, so run them from the directory that holds
the lock file.

Each SARIF result carries the [priority](#priority) as `rank`, the field SARIF 2.1.0 defines for
it: `critical` is `100.0`, `high` `75.0`, `medium` `50.0`, `low` `25.0` and `none` `0.0`, so a
consumer that sorts by rank reads the findings in the order lockrot prints them. The same result's
`properties` carry `priority`, `direct` and `dev` by name. The rule a result points at and its
`level` are unchanged and still follow the verdict.

### GitLab CI

`--format=gitlab` prints a [GitLab Code
Quality](https://docs.gitlab.com/ci/testing/code_quality/#implement-a-custom-tool) report: a JSON
array with one issue per flagged finding (every finding with `--all`), so a merge request shows
them inline in the diff of `composer.lock`. Publish it as a `codequality` artifact:

```yaml
lockrot:
  script:
    - composer lockrot --format=gitlab --fail-on=silent --target-php=8.4 > lockrot-codequality.json
  artifacts:
    reports:
      codequality: lockrot-codequality.json
```

Code Quality has no title field of its own, so each issue's description opens with the package, the
version and the same `<verdict> (<priority>)` phrase the GitHub annotation title uses:

```text
sensio/framework-extra-bundle v6.2.10 — abandoned (critical): flagged abandoned by its repository, replacement: Symfony
```

Severity follows the same rule as the GitHub/SARIF level: a finding at or above `--fail-on` is
`major`, any other flagged verdict `minor`, and a row only `--all` shows (or one a
[baseline](#baseline) already knows) `info`. Each issue's fingerprint is a stable hash of the
package name and verdict, so a version bump that keeps the same verdict — or a reformatted lock
that moves the entry to a different line — keeps the same GitLab issue identity. The priority is
deliberately not part of it, so moving a package from `require` to `require-dev` does not open a
second issue for a finding GitLab already tracks. `Report::notes()`
has no field to carry a document-level note in this format, so notes are dropped here; use
`--format=json` when you need them. The exit code is unchanged.

### Posting a PR comment

`--format=markdown` prints a report shaped for a pull-request comment: a heading with the
flagged/checked counts, a table of the findings led by their [priority](#priority), the report's
notes as a bullet list, and a `<sub>` footer with the full summary:

```markdown
| Priority | Package | Version | Verdict | Evidence | Via |
|---|---|---|---|---|---|
| critical | `sensio/framework-extra-bundle` | v6.2.10 | **abandoned** | flagged abandoned by its repository, replacement: Symfony; last release 2023-02-24 (3.6 years ago); … | direct |
| high | `doctrine/annotations` | 2.0.2 | **abandoned** | flagged abandoned by its repository | sensio/framework-extra-bundle |
| high | `friendsofsymfony/oauth-server-bundle` | dev-master | **pinned** | last release 2019-01-23 (7.6 years ago); pinned to branch snapshot dev-master | direct |
```

(The first row's evidence is abridged here; the real cell carries every signal.) The rows keep the
report's order, so a reviewer reads down the first column and stops where the rows stop applying.
Post it with the GitHub CLI:

```bash
composer lockrot --format=markdown --fail-on=silent --target-php=8.4 > comment.md
gh pr comment --body-file comment.md
```

A clean run prints `### lockrot: no dependency rot found in N packages` and no table. With a
[baseline](#baseline) in place, a second line under the heading carries the same
`known`/`new`/`worsened`/`stale` counts as the table format, and a verdict is bold only when it is
not already accepted by the baseline. The exit code is unchanged.

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
- The [baseline](#baseline) matches by package name only, and never rewrites itself: entries for
  packages that have left the lock are reported as stale, not removed.

## Roadmap

- **v0.2**: a GitHub Action.
- **v0.3**: transitive exposure on parent packages (S7), GitLab/Bitbucket repository activity, and
  inspecting the `vendor/*/composer.lock` of bundled PHAR tools.

## Documentation

- [`tests/fixtures/README.md`](tests/fixtures/README.md) — real-world lock files for integration tests

## License

MIT — see [`LICENSE`](LICENSE).
