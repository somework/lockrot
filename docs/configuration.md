---
title: Configuration — extra.lockrot keys, environment and CLI options
description: Every extra.lockrot key with its default, the environment variables, every command-line option, --output, --explain, caching and the allowlist.
---

# Configuration {#configuration-reference}

Set project defaults under `extra.lockrot` in `composer.json`, and override them for one run with
an environment variable or a command-line option. The first source that sets a value wins: option,
then environment variable, then `extra.lockrot`, then the default.

```json
{
    "extra": {
        "lockrot": {
            "fail-on": "high",
            "target-php": "8.4",
            "include-dev": true
        }
    }
}
```

## `extra.lockrot` keys {#extralockrot-keys}

| Key | Type | Default | Effect | Option / variable |
|---|---|---|---|---|
| `fail-on` | string | `none` | Exit `1` threshold: a verdict, a priority, `unchecked` or `none` ([values](#fail-on-values), [choosing one](ci.md)) | `--fail-on`, `LOCKROT_FAIL_ON` |
| `target-php` | string such as `"8.4"` | `config.platform.php`, else the running PHP | The PHP version the project runs on. S5 checks whether the installed release predates this PHP's major ([Old promise](verdicts.md#old-promise)), and S8 names only a branch that can install on it ([Within reach](verdicts.md#within-reach)) | `--target-php`, `LOCKROT_TARGET_PHP` |
| `format` | string | `table` | What stdout gets: `table`, `json`, `github`, `sarif`, `gitlab`, `markdown` or `html` ([ci.md](ci.md)). The exit code is the same for every format | `--format` |
| `include-dev` | boolean | `false` | Also check `packages-dev`. A development package gets one [priority](verdicts.md#priority) step lower | `--dev` (turns it on only) |
| `baseline` | string | `lockrot-baseline.json` | The [baseline](baseline.md) file, relative to `composer.json` or absolute. A file that this key names must exist, except under `--generate-baseline`. The default file can be missing | `--baseline` |
| `release-warn-years` / `release-high-years` | integer ≥ 1 | `3` / `5` | Years without a stable release at which S2 ("no stable release") and S8 ("no stable release on the installed branch") reach `warn` / `high` ([signals](verdicts.md#the-signals)) | — |
| `push-warn-years` / `push-high-years` | integer ≥ 1 | `3` / `5` | Years without a push or commit at which S4 reaches `warn` / `high` | — |
| `ignore` | array | `[]` | Packages to report as `finished` ([the allowlist](#the-allowlist)) | — |
| `project` | string | `composer.json`'s `name` | The name reports give the project (`run.project`). Reports also carry `composer.json`'s `name` as `run.root_package` ([what the run was told](schema.md#what-the-run-was-told)) | — |
| `install-time` | `on` / `off` | `on` | Print the [install-time summary](install-time.md) during `composer require`, `update` and `install` | — |
| `install-time-strict` | boolean | `false` | Apply `fail-on` at install time and stop the transaction ([install-time-strict](install-time.md#install-time-strict)) | — |
| `install-time-budget` | integer, 1–120 | `5` | The time limit of the install-time pass, in seconds ([Time budget](install-time.md#time-budget)) | — |

### `fail-on` values {#fail-on-values}

The `fail-on` key, `--fail-on` and `LOCKROT_FAIL_ON` each take one of these values:

| Kind | Values |
|---|---|
| A [verdict](verdicts.md#the-nine-verdicts) | `abandoned`, `silent`, `pinned`, `left-behind`, `old-promise`, `stale` |
| A [priority](verdicts.md#priority) | `critical`, `high`, `medium`, `low` |
| [What was not checked](verdicts.md#what-was-not-checked) | `unchecked` |
| No threshold | `none`, the default |

What each value fails the run on, and which to start with, is in [In CI](ci.md).

## CLI options

`composer lockrot` (alias `composer rot`) and the [PHAR](phar.md) take the same options.

| Option | Effect | Falls back to |
|---|---|---|
| `--format=<format>` | Output format for stdout. Values as for the `format` key | `format` |
| `--fail-on=<threshold>` | Exit-`1` threshold for this run. Values as for the `fail-on` key | `LOCKROT_FAIL_ON`, `fail-on` |
| `--target-php=<version>` | PHP version the project runs on, such as `8.4` | `LOCKROT_TARGET_PHP`, `target-php` |
| `--dev` | Also check `packages-dev` | `include-dev` |
| `--all` | List every checked package, not only flagged ones (in `table`, under a final `not flagged` group) | — |
| `--offline` | Never reach the network: repository metadata comes from Composer's cache, activity from lockrot's. lockrot reports a package that is missing from the cache as unavailable ([Working offline](internals.md#working-offline)) | — |
| `--strict-network` | Exit `1` when a run note counts as a network failure ([which notes do](notes.md), [exit codes](ci.md#exit-codes)) | — |
| `--baseline=<path>` | Baseline file to read, or to write with `--generate-baseline`. Path rules as for the `baseline` key | `baseline` |
| `--generate-baseline` | Write this run's flagged findings to the [baseline](baseline.md) file and exit `0` whatever `--fail-on` says. `--strict-network` still applies | — |
| `--explain=<vendor/package>` | Explain one package and exit `0` ([Explaining one package](#explaining-one-package)) | — |
| `--output=<format>:<path>` | Also write the report to a file. Repeatable ([Writing reports to files](#writing-reports-to-files)) | — (command line only) |

Composer's global options apply as well: `-d <dir>` (`--working-dir`) runs lockrot in another
project directory, and `-q` silences everything lockrot prints, the report on stdout included.
Join the directory to the option (`--working-dir=app` or `-dapp`): Composer 2.2 does not read
`-d app`. Exit codes are in [ci.md](ci.md#exit-codes).

## Environment overrides

| Variable | Effect |
|---|---|
| `LOCKROT_DISABLE` | `1` or `true`: `composer lockrot` and the PHAR print `lockrot disabled via LOCKROT_DISABLE` on stderr and exit `0` before they read anything — the command line, `composer.json` or the lock. lockrot also skips the install-time summary. `lockrot.phar self-update` ignores it |
| `LOCKROT_FAIL_ON` | Overrides `fail-on` |
| `LOCKROT_TARGET_PHP` | Overrides `target-php` |
| `LOCKROT_GITHUB_TOKEN`, else `GITHUB_TOKEN` | Token for github.com (S3, S4). When Composer holds credentials for github.com, lockrot sends Composer's instead ([credentials](internals.md#repository-hosts-and-credentials)) |
| `LOCKROT_GITLAB_TOKEN`, else `GITLAB_TOKEN` | Personal access token, sent to gitlab.com only. Without credentials, GitLab hides the archived flag (S3). A self-hosted instance uses Composer's `gitlab-token` or `gitlab-oauth` ([credentials](internals.md#repository-hosts-and-credentials)) |
| `COMPOSER` | The manifest, as for every Composer command: `COMPOSER=alt.json composer lockrot` reads `extra.lockrot` from `alt.json`, analyses `alt.lock` and looks for the baseline next to `alt.json` |

Variables that start with `LOCKROT_X_` are reserved for your own tooling
([reserved names](compatibility.md#names-reserved-for-extensions)).

## Validation {#validation}

Every `composer lockrot` and PHAR run validates the whole configuration, and any error is exit `2`
(unless `LOCKROT_DISABLE` is set). At install time an error is the `install-time check skipped`
line instead ([install-time.md](install-time.md#never-fails-the-install)).

- lockrot validates every source, even one that a higher source overrides. An environment
  variable set to the empty string counts as unset.
- lockrot checks `extra.lockrot` against its published schema
  ([`config-1.json`](https://lockrot.dev/schema/config-1.json), see [schema.md](schema.md)).
  Integer keys take JSON integers: `3`, not `"3"`.
- `format` accepts only the values its [key row](#extralockrot-keys) lists.
- An empty `--fail-on=` or `--baseline=` is an error, never a fall-back to the next source.

### Unknown keys

lockrot ignores a key that it does not read and names it on stderr. When a known key is close,
lockrot suggests it:

```text
lockrot: unknown key extra.lockrot.install-tme ignored (did you mean install-time?)
lockrot: unknown key extra.lockrot.slack-webhook ignored
lockrot: unknown key extra.lockrot.ignore[1].expire ignored (did you mean expires?)
```

- **What is checked.** Top-level keys against the key table, and the keys of each `ignore` entry
  against `package`, `reason`, `version` and `expires`. lockrot does not check the environment. A
  digit-only top-level key such as `"5"` disappears without a warning.
- **Effect.** A warning only. The run goes on, the report and the exit code are unchanged, and
  nothing reaches stdout.
-  **Reserved names never warn.** `extensions` at the top level, whose contents lockrot does not
  check, and any key that starts with lower-case `x-` (`x-ci`, or `x-ticket` inside an `ignore`
  entry) ([reserved names](compatibility.md#names-reserved-for-extensions)).
-  **When.** `composer lockrot` and the PHAR print the lines on every run, `--explain` and
  `--generate-baseline` included, once the configuration has loaded and before lockrot reads the
  lock. The [install-time summary](install-time.md) prints them above its block whenever it runs,
  even when nothing is flagged. `LOCKROT_DISABLE` suppresses them, and `-q` silences them with
  everything else lockrot prints.
- **How a key is printed.** As written, with every byte that a terminal can act on escaped (control
  bytes, invalid UTF-8, bidirectional controls, and a backslash as `\\`), so two keys never print
  alike. lockrot cuts a key longer than 255 bytes there and ends it in `…`.
- **With a schema error.** lockrot appends the unknown-key lines to the list of schema errors, so
  an `ignore` entry that misspells a required key says why the key is missing:

    ```text
    lockrot: extra.lockrot is invalid:
      - ignore[0].reason: <schema error>
      - unknown key extra.lockrot.ignore[0].reasn ignored (did you mean reason?)
    ```

    At install time the same error is the one `install-time check skipped` line.

## Writing reports to files

`--output` writes more formats from the same run.

```bash
composer lockrot --format=github --fail-on=high --target-php=8.4 \
  --output=sarif:lockrot.sarif --output=html:lockrot-report.html --output=json:lockrot.json
```

- **Syntax.** `<format>:<path>`, with a format that `--format` takes. lockrot takes the path
  verbatim, colons included, so `json:C:\reports\lockrot.json` works. One format can go to several
  files. Join the value with `=`. In the PHAR, `--output json:r.json` before the command name reads
  as a command ([PHAR](phar.md#what-the-phar-does-and-does-not-do)).
- **Contents.** Each file is byte for byte what `--format=<that format>` prints for the same run.
  The exception is `table`: in a file it has no colours or console markup, and lockrot wraps it at
  120 columns, whatever the terminal.
- **Relative paths.** Relative to the directory lockrot runs in. `-d <dir>` changes it, so
  `composer --working-dir=app lockrot --output=json:r.json` writes `app/r.json`. In plugin mode,
  when the current directory has no `composer.json`, Composer's
  [`use-parent-dir`](https://getcomposer.org/doc/06-config.md#use-parent-dir) can move the run to a
  parent project, and the path is then relative to it. The PHAR never walks up.
- **Directories.** The directory must exist, and lockrot creates none (`mkdir -p` first). lockrot
  needs write access to the directory, not only to the file. lockrot replaces an existing file.
-  **Writing.** lockrot writes the files after stdout, in the order given. A new file gets the umask
  default. [What lockrot does and does not
  do](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do)
  covers the atomic replace, a leftover `*.tmp` file and what a replaced file keeps. Each file gets
  a line on stderr: `lockrot: sarif report written to lockrot.sarif`.
- **Exit code.** `0` or `1` by `--fail-on`, as without `--output`. A file that cannot be written is
  exit `2` with the reason. Files written before it stay, and outside `--generate-baseline` stdout
  already has the report.
- **With other options.** `--explain` refuses it ([Explaining one
  package](#explaining-one-package)). `--generate-baseline` writes the reports first, without a
  baseline comparison, and then the baseline. A report that fails stops the run before lockrot
  replaces the baseline. Under `LOCKROT_DISABLE`, lockrot writes nothing.

lockrot refuses these values before the analysis starts (exit `2`, nothing fetched, nothing
written):

| Refused | Detail |
|---|---|
| A malformed value | An unknown format, an empty path, a path ending in a separator |
| `composer.json` or `composer.lock` | Any file of that name, in any directory and any letter case |
| The files this run reads | The manifest `COMPOSER` names and its lock |
| A baseline | This run's baseline, and the project's own (`extra.lockrot.baseline`, else `lockrot-baseline.json`) when `--baseline` points elsewhere |
| Another name for a refused file | By spelling: dot segments, as Windows folds them, even through a directory that does not exist. On disk, for files that exist: symlinks, hard links, 8.3 short names, case folds |
| A name Windows reads as another | A file name ending in a dot or a space, or holding a colon (`composer.lock.`, `composer.lock::$DATA`), on every system |
| The same file twice | Compared case-insensitively on every system (`r.json` and `R.json`), and on disk for files that exist |
| A place that cannot take the file | A directory that does not exist, or a path that exists and is not a regular file, such as a directory, `/dev/stdout` or a pipe |

Two paths can prove to be one file once lockrot writes the first (on macOS, `café.json` spelled
precomposed and decomposed). Then the run stops with exit `2` before the second overwrites the
first. The files already written stay.

lockrot never writes `composer.json` or `composer.lock`.
[SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do)
lists every file that lockrot writes and every host that it contacts.

## Explaining one package

`composer lockrot --explain=vendor/package` shows why one package got its verdict, or why it was not
flagged. It runs the ordinary analysis over the whole lock, so the chain, the transitive exposure
and the priority are the report's.

| Part | What it shows | JSON key |
|---|---|---|
| Verdict | Verdict and priority | `finding.verdict`, `finding.priority` |
| Chain | `direct requirement`, or the shortest `via` chain and the other direct requirements that reach the package | `finding.direct`, `finding.chain`, `finding.direct_dependents` |
| Allowlist reason | Why the allowlist accepts the package | `finding.allowlist_reason` |
| Libyears | The package's [libyears](verdicts.md#libyears), or why they were not measured | `finding.libyears`, `finding.libyears_unmeasured` |
| Origin | Where the package came from and, for an abandoned package whose replacement packagist.org names, the replacement's page ([Where a package came from](schema.md#where-a-package-came-from)) | `finding.origin`, `finding.replacement_url` |
| Signals | Every signal with its summary and raw data: the dates behind each age, and for S9 each advisory with what fixes it | `finding.signals` |
| Lock entry | Version, `php` constraint, source, and the entry's date with what it is dated by: a release, a branch snapshot's commit, or a commit a subtree split's tags share | `lock` |
| Repository metadata | Number of versions, whether the package is abandoned and what replaces it, the last stable release, and the release-branch table S8 reads | `metadata` |
| Repository activity | What S3 and S4 read, or that nothing was fetched | `activity` |
| Settings | Thresholds, target PHP, and the project's own `require.php` as composer.json writes it (lockrot tests the branch rows against its lowest version) | `thresholds`, `target_php`, `project_php` |
| Run notes | The run's notes | `notes`, `note_details` |

The document also carries `lockrot`, `package`, `version` and `generated_at`. The full shape is in
[explain-1 fields](schema.md#the-explanation).

- **Formats.** Text when the format is `table`. `--format=json` prints an
  [explain-1](https://lockrot.dev/schema/explain-1.json) document ([schema.md](schema.md)). Any
  other format, including one that `extra.lockrot.format` sets, is exit `2`. Pass `--format=table`
  or `--format=json`. Only the JSON is contract, and the text can change
  ([What is not contract](compatibility.md#what-is-not-contract)).
- **Exit code.** `0`, whatever `--fail-on` says.
- **Errors.** A package that is not in the lock, or that is in `packages-dev` on a run without
  `--dev`, is exit `2`. So is `--explain` with `--output`.
- **Baseline.** Not read, so a missing or unreadable baseline does not stop an explanation.

In the release-branch table, S8 does not measure a branch whose highest tag is `undated` or dated
only by a `commit <date>` that other tags share ([Left behind](verdicts.md#left-behind),
[Dates from the monorepo](verdicts.md#dates-from-the-monorepo)).

## Caching

No option refreshes or resizes Composer's metadata cache or lockrot's activity cache, and
`--offline` reads only from them. [Caching](internals.md#caching) gives where they live and how
long they keep an answer.

## The allowlist

A package on the allowlist reports `finished`, whatever its signals say.

The built-in allowlist,
[`resources/finished-packages.json`](https://github.com/somework/lockrot/blob/main/resources/finished-packages.json),
covers packages that are complete, such as `psr/*` and `symfony/polyfill-*`. Packages of
Composer type `metapackage` or `symfony-pack` count as finished too.

To accept a dependency of your own, add it to `extra.lockrot.ignore`:

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

| Field | Required | Effect |
|---|---|---|
| `package` | yes | The package name, or a shell-style pattern such as `acme/*` |
| `reason` | yes | Why you accept the package, shown with the finding. It must not be blank |
| `version` | no | Match only this exact version of the package |
| `expires` | no | `YYYY-MM-DD`, a real date. After that day (UTC), lockrot skips the entry and the package gets its normal verdict |

An entry silences the whole finding. To accept one security advisory and keep the rest, ignore it
where `composer audit` does ([Security advisories](verdicts.md#security-advisories)).

To propose an addition to the built-in list, see
[Contributing a finished package](https://github.com/somework/lockrot/blob/main/CONTRIBUTING.md#contributing-a-finished-package).

## Testing hooks

These variables exist for lockrot's own tests and are not part of the configuration contract.

| Variable | Effect |
|---|---|
| `LOCKROT_TODAY` | An ISO 8601 date or date-time, such as `2026-09-14`, used as "now": every age (S2, S4, S8), `generated_at` and the `expires` check of ignore entries. Libyears do not depend on it |
| `LOCKROT_RELEASE_URL` | The release list `lockrot.phar self-update` reads, in the shape of GitHub's `GET /repos/{owner}/{repo}/releases`, instead of GitHub's |
| `LOCKROT_RELEASE_KEY` | A PEM file whose public key `lockrot.phar self-update` verifies release signatures with, instead of the key built into the archive |

## Related

- [ci.md](ci.md) — choosing `--fail-on`, exit codes and every output format
- [baseline.md](baseline.md) — accepting the findings you have and failing only on new ones
- [verdicts.md](verdicts.md) — what each verdict, signal and priority means
- [install-time.md](install-time.md) — the summary the `install-time` keys control
- [internals.md](internals.md) — hosts, tokens, rate caps and caching
- [phar.md](phar.md) — running without the plugin, and `self-update`
- [compatibility.md](compatibility.md) — which options, keys and variables are frozen for 1.x
