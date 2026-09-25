---
title: lockrot configuration — extra.lockrot keys and CLI options
description: Every extra.lockrot key in composer.json with its default, the environment overrides, the command-line options, caching, and the allowlist of finished packages.
---

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

The shape of `extra.lockrot` is validated against its published JSON schema,
[`https://lockrot.dev/schema/config-1.json`](https://lockrot.dev/schema/config-1.json)
(`resources/lockrot-config.schema.json` in the repository; see [schema.md](schema.md)). The keys below
must have the listed type — in particular, the four threshold keys must be JSON integers (`3`, not
`"3"`). A key lockrot does not know is still accepted, but no longer silently: see
[Unknown keys](#unknown-keys).

## `extra.lockrot` keys

| Key | Default | Meaning |
|---|---|---|
| `project` | composer.json's own `name` | What a report calls this project. Worth setting where the manifest has no name, or where its name is not the one to publish — a package inside a monorepo names itself after the package, a private project after the client |
| `fail-on` | `none` | Exit 1 threshold: a verdict (`stale`, `old-promise`, `left-behind`, `pinned`, `silent`, `abandoned`), a [priority](verdicts.md#priority) (`low`, `medium`, `high`, `critical`), or `unchecked` — a finding whose check did not run ([What was not checked](verdicts.md#what-was-not-checked)); `none` fails on nothing |
| `target-php` | `config.platform.php`, else the running PHP | PHP version the project runs on, e.g. `"8.4"`: S5 measures the installed release against its GA date, and S8 names no branch it cannot install ([within reach](verdicts.md#within-reach)) |
| `format` | `table` | `table`, `json`, `github`, `sarif`, `gitlab`, `markdown` or `html`; see [ci.md](ci.md) |
| `include-dev` | `false` | Also check `packages-dev` (CLI: `--dev`) |
| `install-time` | `on` | `on` or `off`: print the [install-time summary](install-time.md) during `composer require`/`update`/`install` |
| `install-time-strict` | `false` | Apply `fail-on` at install time too, stopping the transaction instead of only reporting |
| `install-time-budget` | `5` | Integer seconds (1–120): the install-time pass's hard time budget |
| `baseline` | `lockrot-baseline.json` | Path to the [baseline](baseline.md) file, relative to `composer.json` or absolute |
| `release-warn-years` / `release-high-years` | `3` / `5` | Integer thresholds for "no stable release" (S2) and "no stable release on the installed branch" (S8) |
| `push-warn-years` / `push-high-years` | `3` / `5` | Integer thresholds for "no repository push or commit" (S4) |
| `ignore` | `[]` | Project allowlist, see below |

### Unknown keys

A key lockrot does not read changes nothing, so a typo used to go unnoticed: `install-tme: off` left
the install-time summary printing, `failOn` left the build ungated, and an ignore entry's `expire`
made a temporary ignore permanent. lockrot now names each such key with one line on stderr, and the
known key it was probably meant to be when one is close:

```text
lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)
lockrot: unknown key extra.lockrot.slack-webhook ignored
lockrot: unknown key extra.lockrot.ignore[1].expire ignored (did you mean expires?)
```

- A key is close when it is at most a third of its own length in edits away from a known key, or
  when a known key contains it (three characters or more: `dev` suggests `include-dev`), compared in
  ASCII lower case. The fewest edits wins, and a tie goes to the alphabetically first key. Nothing
  close means no suggestion rather than a far-fetched one. The rule is adapted from the one Symfony
  Console, and so Composer, uses for a mistyped command.
- The keys of each `ignore` entry are checked against `package`, `reason`, `version` and `expires`.
- It is a warning and nothing more: the run goes on, the report and the exit code are exactly what
  they would be without the key, and stdout carries nothing of it, whatever the format. The key is
  still valid as far as the [schema](schema.md) is concerned, so a `composer.json` that worked keeps
  working.
- Two namespaces are reserved and never warned about. `extensions` (top level) is for the
  configuration of extensions; lockrot gives what it holds no meaning and does not warn about
  anything inside it. Any key starting with `x-` (`x-ci`, `x-owner`, or `x-ticket` inside an `ignore`
  entry), in lower case, is for your own tooling and notes; lockrot will never give such a key a
  meaning. The `LOCKROT_X_*` environment variables are reserved the same way.
- The key is printed as written, `<` and all — a key is text, never console markup. Anything a
  terminal would act on instead of print is escaped: `\n`, `\r`, `\t`, `\xNN` for any other
  control byte and for a byte that is not valid UTF-8, `\u{NNNN}` for a C1 control, the line and
  paragraph separators, a bidirectional control or a byte order mark, and a backslash as `\\`, so
  two different keys never print alike. A key longer than 255 bytes is cut there and ends in `…`;
  nothing that long is a near-miss.
- `composer lockrot` and the PHAR print the lines on every run, `--explain` and
  `--generate-baseline` included, once the configuration has loaded and `LOCKROT_DISABLE` is
  checked, before the lock is read. The [install-time summary](install-time.md) prints them above
  its block, when all of these hold: `LOCKROT_DISABLE` is not set, the configuration loads,
  `install-time` is not `off`, and the transaction installs or updates at least one package. Unlike the block, they do not wait for a finding: a transaction with nothing
  to report still gets them. `-q` silences them, and with them everything else lockrot prints,
  the report on stdout included. Each run prints the lines for the `composer.json` it read; a second
  run in the same process, such as another manifest's install under a plugin that runs one per
  manifest, prints its own.
- When the [schema](schema.md) rejects the config, the run is a configuration error (exit `2`) and
  these lines are added to that error, after the schema's own, so an `ignore` entry that misspells a
  required key says why the key is missing:

  ```text
  lockrot: extra.lockrot is invalid:
    - ignore[0].reason: The property reason is required
    - unknown key extra.lockrot.ignore[0].reasn ignored (did you mean reason?)
  ```

  At install time the same error is the one `install-time check skipped` line. A top-level key PHP
  reads as an integer (`"5"`) is dropped before the config is read and is not named.
- The environment is not checked: lockrot reads only the variables documented below.

## Environment overrides

| Variable | Overrides |
|---|---|
| `LOCKROT_DISABLE=1` (or `true`) | Skips lockrot entirely, exits 0 |
| `LOCKROT_FAIL_ON` | `fail-on` |
| `LOCKROT_TARGET_PHP` | `target-php` |
| `LOCKROT_GITHUB_TOKEN` / `GITHUB_TOKEN` | GitHub token for the repository-activity signals (S3/S4) on github.com; Composer's `github-oauth.github.com` auth is the fallback. When Composer has that auth, its token is the one sent — see [internals.md](internals.md) |
| `LOCKROT_GITLAB_TOKEN` / `GITLAB_TOKEN` | GitLab personal access token for gitlab.com only. GitLab is never capped, but its API hides the archived flag (S3) from anonymous callers. Self-hosted instances take Composer's `gitlab-token`/`gitlab-oauth` — see [internals.md](internals.md) |

## CLI options

| Option | Meaning |
|---|---|
| `--format=table\|json\|github\|sarif\|gitlab\|markdown\|html` | Output format. `table` (the default) is a width-aware list grouped by priority, not a box table; `html` is the whole run as one self-contained page. The format changes the output only; the exit code is the same for all seven. See [ci.md](ci.md) |
| `--fail-on=<threshold>` | Exit-1 threshold for this run: `none`, a verdict (`abandoned`, `silent`, `pinned`, `left-behind`, `old-promise`, `stale`), a [priority](verdicts.md#priority) (`critical`, `high`, `medium`, `low`) or `unchecked` ([What was not checked](verdicts.md#what-was-not-checked)) |
| `--target-php=8.4` | PHP version the project runs on, for S5 and for the branch S8 suggests |
| `--dev` | Include `packages-dev`. A development package is reported the same way a production one is, but it gets one [priority](verdicts.md) step lower |
| `--all` | Show every checked package, not only flagged ones. Adds a final `not flagged` group |
| `--offline` | Never reach the network: lockrot sets `COMPOSER_DISABLE_NETWORK=1` and rebuilds the configured repositories behind it (in plugin mode Composer has already built its own, network-enabled ones before any command runs), so repository metadata is served from Composer's own cache and repository activity from lockrot's cache. A package missing from the cache is reported as unavailable, not as absent from the repository |
| `--strict-network` | Exit 1 (see [ci.md](ci.md)) when a configured repository or a repository host (GitHub, GitLab, Bitbucket) could not be reached |
| `--generate-baseline` | Write this run's findings to the [baseline](baseline.md) file and exit 0, whatever `--fail-on` says — `--strict-network` is the one exception |
| `--baseline=<path>` | Baseline file to read (or, with `--generate-baseline`, to write); relative to `composer.json` or absolute. Wins over `extra.lockrot.baseline`. An empty `--baseline=` is a configuration error (exit `2`), never a silent fall-back to the default file — as is an empty `--fail-on=` |
| `--explain=vendor/package` | Explain one package and exit 0 — see [Explaining one package](#explaining-one-package) |
| `--output=<format>:<path>` | Also write the report to a file, in any format `--format` takes; repeatable. `--format` still decides stdout. See [Writing reports to files](#writing-reports-to-files) |

`-d <dir>` points the standalone PHAR at a project; see [phar.md](phar.md).

`--output` exists on the command line only: which files a run writes is a property of the run, not
of the project, so there is no `extra.lockrot` key and no environment variable for it.

## Writing reports to files

One run, several reports: the analysis runs once, and every file is rendered from the same report
stdout gets — the same `generated_at`, the same findings, the same notes.

```bash
composer lockrot --format=github --fail-on=silent --target-php=8.4 \
  --output=sarif:lockrot.sarif --output=html:lockrot-report.html --output=json:lockrot.json
```

- **Syntax.** `<format>:<path>`, split at the first colon, so `json:C:\reports\lockrot.json` works.
  The format is one `--format` takes, spelled the same way. One format may go to two files.
- **Contents.** Each file is byte for byte what `--format=<that format>` prints on stdout for the
  same run, with one exception: `table` in a file has no colours and no console markup, and is
  wrapped at 120 columns whatever the terminal is.
- **Where.** A relative path is relative to the working directory lockrot runs in, which is the
  project directory: `-d <dir>` changes it before lockrot starts (in plugin mode and in the PHAR
  alike), so `composer -d app lockrot --output=json:r.json` writes `app/r.json`. In plugin mode,
  Composer's [`use-parent-dir`](https://getcomposer.org/doc/06-config.md#use-parent-dir) can switch
  to a parent project when the current directory has no `composer.json`, and the path is then
  relative to that project; the PHAR never walks up. An absolute path is used as given.
- **Directories.** The directory must already exist; lockrot creates none (`mkdir -p` first). An
  existing file is replaced.
- **How.** Each file is written atomically — a temporary file beside it, then a rename — after the
  report is on stdout, and each one gets a line on stderr: `lockrot: sarif report written to
  lockrot.sarif`.
- **Refusals.** A path naming `composer.json` or `composer.lock` (in any directory, in any letter
  case), the [baseline](baseline.md) file, or the manifest `COMPOSER` names and its lock; an unknown
  format, an empty path or one ending in a separator; the same file twice (compared
  case-insensitively, so `r.json` and `R.json` are one file even on Linux); a directory that does not
  exist; a path that exists and is not a regular file (a directory, a device such as `/dev/stdout`,
  a pipe), since the write is a rename over the path and stdout is what `--format` is for; a file
  name ending in a dot or a space or holding a colon, which Windows reads as another name
  (`composer.lock.` and `composer.lock::$DATA` are the lock there), refused on every system; an
  existing file that resolves to a protected one (a symlink, a Windows 8.3 short name) — each is a
  configuration error (exit `2`), found before the analysis starts, so nothing is fetched and
  nothing is written.
- **Failures.** A file that cannot be written when its turn comes is exit `2` with the reason. The
  files written before it stay; the report is already on stdout.
- **Exit code.** Otherwise untouched: `0` or `1` by `--fail-on`, as without `--output`.
- `--explain` refuses `--output` (exit `2`): it prints one package, not a report. `--generate-baseline`
  writes both: the reports first, then the baseline, and the reports carry no baseline comparison —
  the baseline is what the run is writing. A report that cannot be written stops the run before the
  baseline is replaced. `LOCKROT_DISABLE` writes nothing.

In your project, lockrot writes only the files you name: reports with `--output` and the baseline
with `--generate-baseline`. It never writes `composer.json` or `composer.lock`. Outside the project
it writes only its activity cache, under Composer's cache directory ([Caching](#caching)) — where
Composer also keeps the repository metadata it fetched, as it does for any command — and
`self-update` replaces the PHAR.

## Explaining one package

`composer lockrot --explain=vendor/package` answers "why is this flagged?" — and "why is it not?" —
for one package, in one call. The run is the ordinary one over the whole lock (the chain, the
transitive exposure and the priority are the report's), and what is printed is that package's
finding with everything it was decided on:

- the verdict and priority, how the package is reached (`direct requirement`, or the shortest
  `via` chain and the other direct requirements it is reached from), the allowlist reason or note,
  and the package's [libyears](verdicts.md#libyears) — `libyears 4.7 behind the newest stable
  release`, or `libyears not measured: no release date lockrot trusts` in the words the report's
  `unmeasured` block counts it under;
- every signal, its summary and its raw data — the dates the summary's years were computed from,
  and for S9 one line per advisory with what fixes it and where;
- the `composer.lock` entry: version, `php` constraint, source, and the entry's own date — `released
  2021-06-01` where the repository dated the version by a release, `dated 2026-09-13 by its commit`
  for a branch snapshot, `dated 2023-06-05 by a commit its tags share` for a subtree split, which is
  the date `installed release` below it replaces;
- the repository metadata: how many versions are listed, whether the package is abandoned and what
  replaces it, its last stable release, and the table S8 reads — every release branch with its
  highest tag, that tag's release date and the branch's newest dated release, the installed branch
  marked `*`. A highest tag the repository gives no date reads `undated`; one it dates only by a
  commit other tags share (a subtree split) reads `commit 2023-06-05` — the day the directory last
  changed, not a release. S8 measures neither kind of branch, and the table says so when it is the
  installed one (see [verdicts.md](verdicts.md#left-behind)). A split package the monorepo dates
  also reads `installed release v10.48.28 (2023-11-14, dated by laravel/framework)`, and one whose
  lock date is a shared commit's with no parent to replace it says the date is not a release's;
- the repository activity S3 and S4 read, or that it was not fetched;
- the thresholds and target PHP the signals were measured against, and the run's notes.

`--format=json` prints the same as JSON (`package`, `version`, `finding` — the finding as
`--format=json` carries it — `lock`, `metadata`, `activity`, `thresholds`, `target_php`,
`generated_at`, `notes`); no other format has an explanation form. The exit code is `0`: the run answers a question, it does not gate. A
package that is not in the lock, or is in `packages-dev` on a run without `--dev`, is a configuration
error (exit `2`). The baseline is not consulted.

## Caching

Repository metadata is cached and revalidated by Composer itself, under Composer's own cache
directory. lockrot adds no cache of its own for it, and there is no `--refresh` or `cache-ttl` knob
to bypass or resize it.

Repository-activity responses — GitHub, GitLab and Bitbucket alike — are cached separately under
Composer's cache directory, in a `lockrot/` subfolder, with a fixed 24-hour TTL. When Composer's
cache is disabled (`composer --no-cache`), they are kept in memory for the run only and nothing is
written to disk. A report built on cached activity says so in its footer, with the age of the oldest
cached answer — under a day for a fresh hit, more when a failed refetch fell back to a stale entry or
under `--offline`. More in [internals.md](internals.md).

## The allowlist

Packages that are finished by design — an interface package that will not release again, a polyfill
that is deliberately frozen, a metapackage — are not dependency rot. lockrot ships a built-in
allowlist at [`resources/finished-packages.json`](https://github.com/somework/lockrot/blob/main/resources/finished-packages.json): `psr/*`,
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

An allowlist entry silences the whole finding. To accept one security advisory (signal S9) and
keep the rest of the package's report, use Composer's own ignore configuration instead —
`config.policy.advisories.ignore-id` on Composer 2.10 and later, `config.audit.ignore` before:
lockrot drops exactly what `composer audit` drops. See
[verdicts.md](verdicts.md#security-advisories).

To propose an addition to the built-in list — a genuinely finished package used widely enough to
belong there — open a pull request against `resources/finished-packages.json` with the package
pattern and a one-line reason, the same shape as the existing entries.

## Testing hooks

Neither of these is part of the configuration contract.

| Variable | Effect |
|---|---|
| `LOCKROT_TODAY` (e.g. `2026-09-14`) | Fixes the reference date used for every "years ago" calculation |
| `LOCKROT_RELEASE_URL` | Sends `lockrot.phar self-update` to this release list (the shape of GitHub's `GET /repos/{owner}/{repo}/releases`) instead of GitHub's; for lockrot's own tests |
| `LOCKROT_RELEASE_KEY` | A PEM file whose public key `lockrot.phar self-update` verifies release signatures with, instead of the key built into the archive; for lockrot's own tests |
