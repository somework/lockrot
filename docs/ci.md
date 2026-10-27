---
title: In CI — GitHub Actions, GitLab CI, SARIF, exit codes
description: "Fail a pipeline on dependency rot: the step to copy, choosing --fail-on, the gate line, exit codes 0, 1 and 2, and what each output format gives GitHub, GitLab and reviewers."
---

# In CI {#running-lockrot-in-ci}

Add one step and pick the values that fail it. The step exits `0` when no finding fails a value, `1`
when one does, and `2` when lockrot could not run ([Exit codes](#exit-codes)).

```yaml
- name: lockrot
  run: composer lockrot --fail-on=high --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

Pin `--target-php` to the PHP version the project runs on. Without it, lockrot judges fixes and old
promises against the PHP that runs it, so the same lock can grade differently on two machines
([configuration.md](configuration.md#extralockrot-keys)). `GITHUB_TOKEN` lifts the anonymous cap
on repository-activity checks
([Repository hosts and credentials](internals.md#repository-hosts-and-credentials)).
Without the plugin installed, run the PHAR instead ([phar.md](phar.md#in-ci)).

`--fail-on` takes a comma-separated list, at most one value of each kind. A finding fails the run
when it reaches any value of the list:

| Fail the build on | Value | Kind |
|---|---|---|
| How much a finding matters to this project, flags and advisories together | a grade: `critical`, `high`, `medium` or `low` | `grade` ([the verdicts](verdicts.md#the-nine-verdicts)) |
| A maintenance flag at or above a place in flag order, wherever the package sits: `silent` fails on `silent` and `abandoned` | a flag, such as `left-behind` | `flag` ([flags](verdicts.md#flags)) |
| A counted advisory at or above a severity, whatever the rest of the score | `vulnerable` (any), `vulnerable-medium`, `vulnerable-high` or `vulnerable-critical` | `vulnerable` ([severity](verdicts.md#severity)) |
| A finding whose check did not run | `unchecked` | `unchecked` ([What `--fail-on=unchecked` fails on](#what-unchecked-fails-on)) |
| Nothing: report only | `none` (the default) | |

- **Where to start.** Use `--fail-on=high`: a grade counts every flag and advisory, as they apply
  to this project. If the project does not start clean, add a [baseline](baseline.md). The same
  step then fails only on findings that are new or have got worse. It reads the default baseline
  file without a flag.

- **Recipes.**

    | Goal | `--fail-on` |
    |---|---|
    | Rot only | `left-behind` |
    | Rot, plus serious advisories | `left-behind,vulnerable-high` |
    | What `composer audit` fails on, plus abandonment, failing closed | `abandoned,vulnerable,unchecked`, with `--dev` |
    | A grade, with `composer audit` parity for development packages | `high,vulnerable-high`, with `--dev` |

- **`--dev` and a grade.** packages-dev halves a package's whole score, so with
  `--dev --fail-on=high` a high advisory with a reachable fix in a development package is `medium`
  and passes. A
  `vulnerable-*` value reads the advisory's own severity, so add one for parity with
  `composer audit`.

- **One source.** The option, then `LOCKROT_FAIL_ON`, then `extra.lockrot.fail-on`: the first that
  is set replaces the others whole.

[`fail-on` values](configuration.md#fail-on-values) lists every accepted value.

## Exit codes

| Code | Meaning | stdout |
|---|---|---|
| `0` | No finding failed a `--fail-on` value, apart from ones the baseline exempts (under `--fail-on=none` none can), and no `--strict-network` trip | The report. Nothing under `--generate-baseline` |
| `1` | A finding failed a `--fail-on` value and the baseline does not exempt it, or `--strict-network` is on and a run note counts as a network failure | The report. Nothing under `--generate-baseline` |
| `2` | lockrot could not run or could not finish: an input, configuration or usage error, or a file it could not write | Nothing, except when an `--output` file fails (outside `--generate-baseline`): the report is already on stdout |

The exit code is the same for every output format. On stderr, a configuration or usage error starts
with `lockrot:`, any other failure with `lockrot failed:`. `-v` adds the stack trace to the second.

**Exit `2`** comes from:

- A file lockrot cannot write: an `--output` file or the baseline. `2` wins over `1`: a run whose
  gate has failed still exits `2` when a write then fails.
- A `composer.json` or `composer.lock` lockrot cannot parse, a missing `composer.lock`, or a lock
  entry Composer's loader rejects (no `name` or `version`, a version it cannot normalise).
- A value the configuration rejects: in `extra.lockrot`, in an environment variable, or on the
  command line ([configuration.md](configuration.md#extralockrot-keys)). That includes two values
  of one kind in `--fail-on`, `none` beside another value, and `vulnerable-low`.
- A command line lockrot cannot read: an unknown option, an option without its value, a value given
  to a flag, an argument too many.
- A baseline lockrot cannot read, a baseline-1 file included
  ([baseline.md](baseline.md#when-lockrot-cannot-read-the-file)).
- An `--output` lockrot refuses before the analysis, such as an unknown format or `composer.lock`
  ([configuration.md](configuration.md#writing-reports-to-files)).
- An `--explain` lockrot cannot answer: a package the run did not analyse, or `--explain` combined
  with `--output` or with a format other than `table` and `json`
  ([configuration.md](configuration.md#explaining-one-package)).

**Network failures do not change the exit code on their own.** An unreachable Composer repository or
repository host becomes a [run note](notes.md), and the checks it blocked count as absent evidence
([S10](verdicts.md#what-was-not-checked)). With `--strict-network`, a run note whose
`sets_network_failures` is true makes the run exit `1`. That includes an `--offline` run where a
locked package has no cached metadata. To fail on the missing checks themselves, use
`--fail-on=unchecked`.

**A vulnerable package exits `1` when its verdict reaches a grade value, or an advisory's severity
reaches a `vulnerable` value.** An advisory adds its points to the score
([Security advisories](verdicts.md#security-advisories)), and `--fail-on` decides as usual.

Other exit paths:

- `--generate-baseline` exits `0` whatever `--fail-on` says, and `1` only under `--strict-network`
  ([baseline.md](baseline.md#what-generate-baseline-does)).
- `--explain` exits `0` ([configuration.md](configuration.md#explaining-one-package)).
- `LOCKROT_DISABLE=1` skips the run and exits `0`
  ([configuration.md](configuration.md#environment-overrides)).
- The [install-time summary](install-time.md) sets no exit code unless `install-time-strict` is on.
- `lockrot.phar self-update` has its own codes ([phar.md](phar.md#self-update-exit-codes)).

### When exit `1` does not come from lockrot {#exit-1-not-from-lockrot}

Composer or Symfony can stop before lockrot runs, with their own exit `1`:

| Symptom | Cause | Fix |
|---|---|---|
| Exit `1` with a report and a `gate: fails` line, or under `--generate-baseline` with a `lockrot: baseline written` line | lockrot's own exit `1`: a finding failed `--fail-on`, or `--strict-network` | Read the `gate:` line. This exit is the gate at work |
| Exit `1`, Composer's error box or Symfony's message, no `lockrot:` line | An unknown command: `composer lokrot`, `php lockrot.phar nope` | Fix the command name |
| Exit `1`, `There are no commands defined in the "json" namespace` | `php lockrot.phar --output json:r.json`: before the command name, Symfony reads a value after a space as the command | Join the value with `=`: `--output=json:r.json` |
| Exit `1`, Composer's error box about `composer.json`, in plugin mode | Composer cannot parse or validate `composer.json` | Fix the file. The PHAR reads the file itself and exits `2` for it |

## <a name="reading-the-gate-line"></a>Reading the gate line {#reading-the-gate-line}

Every text format prints one `gate:` line that says whether the run fails and why:

```text
gate: fails · --fail-on=high · 42 findings fail it · target php 8.4 (--target-php)
```

- The first word is `fails`, `passes`, `off` (no `--fail-on` value) or
  `not applied (--generate-baseline)`.
- Then the `--fail-on` list, with its source in brackets when it is not the option:
  `(LOCKROT_FAIL_ON)`, `(extra.lockrot.fail-on)` or `(default)`.
- Then how many findings fail it, per value when the list holds two or more:
  `1 finding fails it (left-behind 1, vulnerable-high 1)`. Findings that the baseline exempts are
  counted apart: `1 reaches it and is known to the baseline`.
- `--strict-network: a lookup failed` when `--strict-network` tripped.
- One clause per gap that the gate could not read: `advisories not applied to 6 packages: not
  checked (offline)`. A grade or `vulnerable` value cannot fail on advisories that lockrot did not
  check, and the clause says so.
- Last, the target PHP and its source. `(the running PHP)` says that nothing pinned it.

The JSON report writes the line as `gate.text` and the gate data as fields: `gate.failing_by`
(failing findings per value), `gate.standing` (reaching findings by baseline standing),
`gate.failing_packages` (the package names of the failing findings) and `gate.not_applied` (each
gap, with its package count). Key a script on the fields. The line is prose.

A run that writes `json`, `sarif`, `gitlab` or `html` to stdout also writes `lockrot: ` and the
`gate:` line to stderr when the gate fails, so the job log says why the job exits `1`. Read stdout
alone: do not merge stderr into a report with `2>&1`.

## What `--fail-on=unchecked` fails on {#what-unchecked-fails-on}

`unchecked` fails on every finding that carries [S10](verdicts.md#what-was-not-checked): a check
that lockrot did not run on a package it judges. It catches a workflow that does not pass
`GITHUB_TOKEN`, and also these gaps:

- an advisory lookup that did not complete: `--offline`, Composer below 2.4, a repository that
  failed, the install-time budget, or no configured repository that publishes advisories
- release data that did not come for a package with a counted advisory (`releases_unknown`)
- a repository on a host that lockrot cannot ask (`host_not_supported`)
- a package installed from vcs, or from an origin that lockrot cannot tell
  (`not_from_composer_repository`)

A package installed from a path is the project's own code: its release and activity gaps are
skips. An advisory lookup that did not complete is S10 for it too. Under a baseline, a `known`
finding is exempt, and a `worsened` one fails on whatever S10 it carries.

Most gaps close on a rerun. For a self-hosted GitLab, list the host in `gitlab-domains`
([Self-hosted GitLab](internals.md#self-hosted-gitlab)). Three gaps stay on every run. Accept each
one in [`extra.lockrot.accept-unchecked`](configuration.md#accept-unchecked), with a reason:

```json
{
    "extra": {
        "lockrot": {
            "fail-on": "high,unchecked",
            "accept-unchecked": [
                {"check": "repository_activity", "host": "codeberg.org", "reason": "Forgejo, which lockrot cannot ask; we watch the repository by hand"},
                {"check": "advisories", "repository": "https://mirror.example.com", "reason": "the mirror has no advisory feed; composer audit runs against packagist.org in another job"},
                {"check": "release_metadata", "repository": "https://github.com/example/fork", "reason": "our fork, installed from vcs"},
                {"check": "repository_activity", "repository": "https://github.com/example/fork", "reason": "our fork; we follow upstream by hand"},
                {"check": "release_metadata", "package": "example/private-*", "reason": "served by a Satis that leaves no notification-url"},
                {"check": "repository_activity", "package": "example/private-*", "reason": "served by a Satis that leaves no notification-url"}
            ]
        }
    }
}
```

- A package from an origin that lockrot cannot tell, such as asset-packagist.org or a private
  Satis without a notification-url, is accepted by `package`.
- `undated_releases` (tags with no date lockrot trusts) can stay on every run too, and no entry
  accepts it.
- An accepted gap writes no `lockrot/unchecked` record in SARIF or GitLab. A record that an earlier
  run wrote then shows as fixed in code scanning and in the merge-request widget, not as
  accepted. The json report holds what was accepted: `unchecked_accepted[]` on each finding and
  `packages_unchecked_accepted` at the root.
- An accepted `no_feed` still leaves the advisories unchecked: the `gate:` line counts the package
  in its `advisories not applied` clause.
- On Composer below 2.4 (the plugin on an LTS Composer, 2.2 or 2.3), advisories are never checked,
  and `accept-unchecked` cannot accept that gap. Upgrade Composer, or run the
  [PHAR](phar.md#in-ci), which bundles a newer one.

A path package, an artifact or an inline `package` entry is a skip, not S10. To fail on such a
package anyway, check its skips with jq:

```bash
jq -e '[.findings[] | select(.checks_skipped[]?.reason == "not_from_composer_repository")] | length == 0' lockrot.json
```

## GitHub Action

[somework/lockrot-action](https://github.com/somework/lockrot-action) v1 runs lockrot as one step.
Its README lists the inputs, and its `version` input pins the lockrot release.

```yaml
- uses: somework/lockrot-action@v1
  with:
    target-php: '8.4'
    fail-on: high
```

For other CI systems, run the PHAR as in [PHAR in CI](phar.md#in-ci), or the
[container image](phar.md#the-docker-image).

## Choosing a format {#choosing-a-format}

| Format | For | Section |
|---|---|---|
| `table` (default) | A person reading the job log | [example-run.md](example-run.md) |
| `github` | Annotations on `composer.lock` in the pull request | [`--format=github`](#-formatgithub) |
| `sarif` | GitHub code scanning (Security tab) | [`--format=sarif`](#-formatsarif) |
| `gitlab` | GitLab Code Quality | [`--format=gitlab`](#-formatgitlab) |
| `markdown` | A pull-request comment or job summary | [`--format=markdown`](#-formatmarkdown) |
| `html` | One self-contained page to read, attach or publish | [`--format=html`](#-formathtml) |
| `json` | Scripts and dashboards: every field | [`--format=json`](#-formatjson) |

### <a name="how-each-format-marks-a-finding"></a>How each format marks a finding {#how-each-format-marks-a-finding}

`github`, `sarif` and `gitlab` write records: one annotation per package in `github`, one result
per counted flag and per counted advisory in `sarif`, one issue per counted flag in `gitlab`. Each
record gets one mark, by the fact it stands for, so the marks agree with the exit code:

| Mark | `github` | `sarif` `level` | `gitlab` `severity` | When |
|---|---|---|---|---|
| Known | `notice` | `note`, with `suppressions` | `info` | The baseline covers the record's fact |
| Fails | `error` | `error` | `major` | The finding fails, and the record's own fact reaches a failing value (below) |
| Not graded | `notice` | `note` | `info` | A `lockrot/unchecked` record that fails no value, or a score-0 record under `--all` |
| Other | `warning` | `warning` | `minor` | Anything else |

A record's fact reaches a failing value when:

- **a grade:** it is the lead or the security term of the score, and its contribution is at least
  half of the grade's floor. Under a baseline entry, it is a term of the new facts, and a quarter
  of the floor is enough
- **a flag:** it is a maintenance flag at or above the value that the baseline does not cover
- **a `vulnerable` value:** it is an advisory at or above the value that the baseline does not know
  (in `gitlab`, the `vulnerable` issue)
- **`unchecked`:** it is the finding's first record, and the finding carries S10

So every failing finding has at least one failing record, and each finding's `gate.by[]` in the json
report names those facts per value. `github` writes one annotation per package, with the finding's
mark.

- **SARIF `rank`** is the band of the record's own fact: `100.0` for critical, `75.0` high, `50.0`
  medium, `25.0` low. An advisory result ranks by its severity: `95.0`, `80.0`, `55.0` (medium and
  unrated) and `20.0`. An advisory rule carries the `security-severity` that code scanning reads:
  `9.5`, `8.0`, `5.5` and `2.0`. An unrated advisory's rule has no `security-severity`.
- **The run-level record.** When advisories were not checked, `github`, `sarif` and `gitlab` add
  one record for the run: `lockrot/advisories-unchecked`. It is `error` (`major`) only when
  `unchecked` is a failing value and a finding fails it on an advisory gap. Otherwise it is
  `warning` (`minor`).
- **A finding with S10 and nothing counted** (score 0, whatever its verdict) gets its own
  `lockrot/unchecked` record when `unchecked` is active. `sarif` and `gitlab` also write it under
  `--all`.
- **GitHub caps annotations per step** (10 errors and 10 warnings). The report's sort puts the
  findings with a critical advisory first. The step log holds every line, and `--format=sarif`
  uploads the complete set.
- `--strict-network` marks no record: its cause is in the `gate:` line and in `gate.tripped_by`.

### Where annotations point

Annotations or Code Quality issues that do not land on the lock come from a project in a
subdirectory of the checkout. `github`, `sarif` and `gitlab` name the analysed lock by its path
relative to the directory that lockrot runs in (`composer.lock`, `alt.lock` under
`COMPOSER=alt.json`, `app/alt.lock` under `COMPOSER=app/alt.json`). When the lock lies outside that
directory, they name it by its file name alone. For `github` and `gitlab`, GitHub and GitLab resolve
that path against the checkout root, so `-d app` still names it `composer.lock`. `sarif` also
carries the directory that the path is relative to, as `%SRCROOT%`
([`--format=sarif`](#-formatsarif)).

For a project in `app/`, run the [PHAR](phar.md#in-ci) from the checkout root with
`COMPOSER=app/composer.json`, which names the lock `app/composer.lock`. The
[action's README](https://github.com/somework/lockrot-action#readme) has a recipe for a project in a
subdirectory.

### `--fail-on=unchecked` in the formats {#unchecked-in-the-formats}

The findings that fail such a run can be `ok` or `unknown` ones that carry S10. `github`, `sarif`
and `gitlab` give each of them a record when `unchecked` is active. `table` and `markdown` list them
only with `--all`. Read the `gate:` line, or `gate` in the json report, to see how many failed.

## Several reports from one run

`--format` decides what goes to stdout. Each `--output=<format>:<path>` writes one more format to a
file from the same analysis, so every file carries the same findings, notes and timestamp. Paths,
refusals and failures: [Writing reports to files](configuration.md#writing-reports-to-files).

```yaml
permissions:
  contents: read
  security-events: write

steps:
  - uses: actions/checkout@v7
  - name: lockrot
    run: >-
      composer lockrot --format=github --fail-on=high --target-php=8.4
      --output=sarif:lockrot.sarif --output=html:lockrot-report.html --output=json:lockrot.json
    env:
      GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  - name: Upload SARIF
    if: always()
    uses: github/codeql-action/upload-sarif@v4
    with:
      sarif_file: lockrot.sarif
  - name: Upload the page and the JSON
    if: always()
    uses: actions/upload-artifact@v7
    with:
      name: lockrot-report
      path: |
        lockrot-report.html
        lockrot.json
```

With `if: always()`, the uploads run after `--fail-on` fails the lockrot step.

`--markdown-max-bytes=<n>` keeps each markdown report written through `--output` within `n` bytes,
for a job summary that GitHub rejects above 1 MiB. lockrot leaves rows out from the end and adds one
row that counts them. The heading, the `gate:` line, the table head and the footer always print. The
option never cuts the report on stdout.

## `--format=github` {#-formatgithub}

One [workflow command](https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions)
per graded finding (every finding with `--all`), so each package becomes an annotation on its line
of `composer.lock` in the pull request's Files changed view.

```yaml
- name: lockrot
  run: composer lockrot --format=github --fail-on=high --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

The shape of one annotation:

```text
::<level> file=composer.lock,line=<line>,title=lockrot%3A <verdict> — <flags>::<package> <version>: <score line>. Do: <move>. <flag>: <evidence>.
```

- The title is `lockrot: <verdict> — <flags>`, with the counted flags in flag order and
  `(<severity> advisory)` after `vulnerable`. Key a script on the annotation's file and line, not
  its title. The level follows [How each format marks a finding](#how-each-format-marks-a-finding).
- The message holds the score line, the move and the evidence of each counted flag. A transitive
  finding's message ends with the direct requirements that reach it: `(via a, b)`
  ([transitive exposure](verdicts.md#transitive-exposure)).
- The report's notes, the stale-baseline note included, follow as `::notice title=lockrot::`
  lines.
- The output ends with plain log lines, not annotations: `flags:`, `depth:`, `security:`,
  `update now:`, [`php:`](verdicts.md#the-php-window), then the
  [`gate:` line](#reading-the-gate-line). When the lock excludes the target PHP, the `php:` line
  gives Composer's answer to
  [the update of the packages that exclude it](verdicts.md#the-update-that-admits-the-target).

## <a name="-formatsarif"></a>`--format=sarif` {#-formatsarif}

A [SARIF 2.1.0](https://json.schemastore.org/sarif-2.1.0.json) document for GitHub code scanning.
Code scanning keeps the results in the Security tab and tracks them across runs. The upload needs
`security-events: write`:

```yaml
permissions:
  contents: read
  security-events: write

steps:
  - uses: actions/checkout@v7
  - name: lockrot
    run: composer lockrot --format=sarif --fail-on=high --target-php=8.4 > lockrot.sarif
    env:
      GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  - name: Upload SARIF
    if: always()
    uses: github/codeql-action/upload-sarif@v4
    with:
      sarif_file: lockrot.sarif
```

| SARIF field | Holds |
|---|---|
| `ruleId` | `lockrot/<flag>` for a counted maintenance flag, `lockrot/advisory/<id>` for a counted advisory, `lockrot/unchecked` for a finding with S10 and nothing counted. Under `--all`, `lockrot/ok`, `lockrot/finished` and `lockrot/unknown` |
| `level` | The mark from [How each format marks a finding](#how-each-format-marks-a-finding) |
| `rank` | The band of the result's own fact ([ranks](#how-each-format-marks-a-finding)) |
| `locations` | The package's line in the analysed lock |
| `partialFingerprints` | `lockrot/package`: the package name, so a result keeps its identity when its line moves. An advisory result adds `lockrot/advisory` |
| `suppressions` | On a result whose fact the baseline covers |
| `properties` | `package`, `version`, `verdict` (the grade), `priority`, `lead`, `score_total`, `score_text`, `score_model_id`, `flag_ids`, `direct`, `dev`, `data_date`, `baseline_status` (`known`, `new`, `worsened` or null), `error_by` (the failing values), `chain`, `depth`, `direct_dependents`, `signal_ids` and `age`. An advisory result adds the advisory's `fix` |
| `runs[0].properties` | `lockrot_schema`, `text_grammar`, `score_text_grammar`, `score_model_id`, `fix_model` and `advisory_lookup` |
| `invocations[].toolExecutionNotifications` | The report's notes, and a warning per advisory repository that failed |

- Guard a script on `runs[0].properties.lockrot_schema == 2`, then read `properties.lead` for the
  first maintenance flag.
- A tool that dismisses the alerts of suppressed results (such as `advanced-security/dismiss-alerts`)
  dismisses every result whose fact the baseline covers. A result that worsens loses its
  suppression.
- A message holds text from a Composer repository, such as an advisory title. lockrot writes each
  `](` in it as `]\(`, so a SARIF viewer reads no `[text](uri)` as a link.

`originalUriBaseIds.%SRCROOT%` is the directory that the lock's path is relative to (the directory
that lockrot runs in, or the lock's own when it lies outside it), as an absolute `file://` URL on
the machine that ran lockrot. Code scanning resolves the lock's path against it. To publish the file
anywhere else, strip it first ([what a report reveals](schema.md#where-a-package-came-from)):

```bash
jq 'del(.runs[].originalUriBaseIds)' lockrot.sarif > lockrot.public.sarif
```

### <a name="sarif-exit-code"></a>SARIF exit code {#sarif-exit-code}

`runs[0].invocations[0].exitCode` is the exit code that the gate decides: `1` when `gate.fails`,
else `0`. `exitCodeDescription` is the [`gate:` line](#reading-the-gate-line), and the invocation's
`properties.gate` holds the json report's `gate`. `executionSuccessful` stays `true`: the run
worked, and the gate failed.

## <a name="-formatgitlab"></a>`--format=gitlab` {#-formatgitlab}

A [GitLab Code Quality](https://docs.gitlab.com/ci/testing/code_quality/#code-quality-report-format)
report: a JSON array with one issue per counted flag. Publish it as a `codequality` artifact:

```yaml
lockrot:
  script:
    - composer lockrot --format=gitlab --fail-on=high --target-php=8.4 > lockrot-codequality.json
  artifacts:
    when: always
    reports:
      codequality: lockrot-codequality.json
```

`when: always` uploads the report when `--fail-on` fails the job. A merge request shows only issues
new or fixed against the target branch
([features per tier](https://docs.gitlab.com/ci/testing/code_quality/#features-per-tier)).

- `check_name` is `lockrot/<flag>`. `categories` is `["Security"]` for `vulnerable` and
  `["Bug Risk"]` for the other flags.
- `description` opens with the package, the version, the grade and the score line, then the flag's
  evidence and the move, and for a transitive package ends with `(via a, b)`:

    ```text
    <package> <version> — finding <verdict> (<score line>) — <flag>: <evidence>. Do: <move>.
    ```

- `severity` follows [How each format marks a finding](#how-each-format-marks-a-finding).
- `fingerprint` is the sha256 of `lockrot|<package>|<flag>`. A version bump, a moved line, a
  change of grade or another `COMPOSER` manifest keeps the same issue. A flag that starts to count
  opens its own issue, and a cleared flag resolves its own.
- A package that becomes vulnerable opens `lockrot/vulnerable`. A new advisory on a package that is
  already vulnerable opens nothing new in GitLab: SARIF has one result per advisory.
- An accepted flag writes no issue. A finding with S10 and nothing counted writes
  `lockrot/unchecked` when `unchecked` is active, and under `--all`. Under `--all`, a score-0
  finding writes `lockrot/ok`, `lockrot/finished` or `lockrot/unknown`.
- `location` is the package's line in the analysed lock.
- The format has no field for a document-level note, so the report's notes are not in it. Use
  `--format=json` for them.

## <a name="-formatmarkdown"></a>`--format=markdown` {#-formatmarkdown}

A report shaped for a pull-request comment or a job summary. The steps after lockrot run with
`if: always()`, so they post the comment when the gate fails too:

```yaml
permissions:
  contents: read
  pull-requests: write

steps:
  - uses: actions/checkout@v7
  - name: lockrot
    run: composer lockrot --format=markdown --fail-on=high --target-php=8.4 > comment.md
    env:
      GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  - name: Comment on the pull request
    if: always() && github.event_name == 'pull_request'
    run: gh pr comment "${{ github.event.pull_request.number }}" --body-file comment.md
    env:
      GH_TOKEN: ${{ github.token }}
  - name: Job summary
    if: always()
    run: cat comment.md >> "$GITHUB_STEP_SUMMARY"
```

Top to bottom, it holds:

1. A heading: `### lockrot: <graded> of <checked> packages flagged — <grade counts>`, or on a clean
   run `### lockrot: nothing flagged in <checked> packages`. A clean run has no table unless you
   pass `--all`, which lists every package under the heading.
2. The [`gate:` line](#reading-the-gate-line), its first part in bold.
3. A table of the graded findings (every finding with `--all`) in report order. The verdict is bold
   where the finding fails the gate.
4. The `flags:`, `depth:`, `security:`, `libyears:`, `pulled in by:`, `advisories:`, `moves:` and
   [`php:`](verdicts.md#the-php-window) lines. With a [baseline](baseline.md), its
   `known`/`new`/`worsened`/`stale` counts follow. Then **Update now** with the command.
5. The report's notes as a bullet list, and a `<sub>` footer with the data date and the score model.

The shape of the table:

```text
| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **<verdict>** | `<package>` | <version> | <flag> · <flag> | `<score line>`<br>**Do:** <move><br>*<flag>:* <evidence> | direct, or <direct requirements> |
```

lockrot renders every value from the project or from package metadata as plain text, and
backslash-escapes Markdown and HTML punctuation. A `composer.lock` under someone else's control
cannot put an image, a tag or link markup into the comment. It cannot mention a user or a team,
or make a bare URL or an email address into a link. It cannot link `#1`, `GH-1` or a commit hash
to an issue or a commit of your repository. It cannot render `$…$` as math or `:name:` as an emoji. A control character prints as an escape, such as `\x1B`.

## `--format=html` {#-formathtml}

The whole run as one self-contained page: it fetches nothing, so it opens from `file://`, uploads as
one CI artifact and attaches to a ticket.

```yaml
- name: lockrot
  run: composer lockrot --format=html --fail-on=high --target-php=8.4 > lockrot-report.html
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
- uses: actions/upload-artifact@v7
  if: always()
  with:
    name: lockrot-report
    path: lockrot-report.html
```

The page is [lockrot-report](https://github.com/somework/lockrot-report), vendored at the version
that the [changelog](changelog.md) names. That repository describes what the page shows and how to
navigate it. The page carries the run as JSON in
`<script id="lockrot-data" type="application/json">`, and that document's `report` key is the
[report-2 document](schema.md), the part of the page that is
[contract](compatibility.md#what-is-not-contract). A script that extracts it guards on its
`$schema` first.

The page reads report-2 only. Each page embeds its own renderer, so a page that an older lockrot
wrote keeps opening as it did.

Without `--all` the page carries release detail for graded packages. `--all` adds that detail for
every package, so the file grows with the lock.

**Content-Security-Policy.** The page carries its own policy (lockrot-report describes it). A host
that adds its own policy header gets the intersection of the two. `script-src 'unsafe-inline'` and
`style-src 'unsafe-inline'` in that header are enough.

### <a name="the-json-beside-the-page"></a>The JSON beside the page {#the-json-beside-the-page}

To keep the report as a JSON file next to the page, ask for both in one run:

```bash
composer lockrot --format=html --output=json:lockrot.json > lockrot-report.html
```

## `--format=json` {#-formatjson}

The complete report, and the only format that carries every field. It holds every finding, with or
without `--all`, and opens with `$schema`, the [report schema URL](schema.md).

It carries each finding's flags, score, move, security block and the data behind each signal. It
also carries each finding's standing against the baseline and the gate, and the run's thresholds,
score model and typed notes ([notes.md](notes.md)). `gate` records the exit decision: `gate.fails`, its causes in
`gate.tripped_by`, the [`gate:` line](#reading-the-gate-line) as `gate.text`, and each finding's
own `gate`. [schema.md](schema.md#what-the-report-schema-types) types every field and has a CI
validation step. [example-run.md](example-run.md) has a worked excerpt.

## Related

- [baseline.md](baseline.md) — adopt lockrot on a project that does not start clean
- [configuration.md](configuration.md) — every option, key and environment variable, `--output`
  and `accept-unchecked` in full
- [verdicts.md](verdicts.md) — what each flag, verdict and signal means
- [schema.md](schema.md) — the JSON schemas and what a report reveals
- [notes.md](notes.md) — every run note and what it means for the exit code
- [phar.md](phar.md) — running the standalone PHAR in CI
