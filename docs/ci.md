---
title: In CI — GitHub Actions, GitLab CI, SARIF, exit codes
description: "Fail a pipeline on dependency rot: the step to copy, choosing --fail-on, exit codes 0, 1 and 2, and what each output format gives GitHub, GitLab and reviewers."
---

# In CI {#running-lockrot-in-ci}

Add one step and pick the threshold that fails it. The step exits `0` when no finding reaches the
threshold, `1` when one does, and `2` when lockrot could not run ([Exit codes](#exit-codes)).

```yaml
- name: lockrot
  run: composer lockrot --fail-on=high --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

`--target-php` is the PHP version the project runs on
([configuration.md](configuration.md#extralockrot-keys)). `GITHUB_TOKEN` lifts the anonymous cap
on repository-activity checks
([Repository hosts and credentials](internals.md#repository-hosts-and-credentials)).
Without the plugin installed, run the PHAR instead ([phar.md](phar.md#in-ci)).

`--fail-on` takes one value, and every threshold is inclusive:

| Fail the build on | Value | Kind |
|---|---|---|
| What was observed, wherever the package sits: `silent` fails on `silent` and `abandoned` | a verdict, e.g. `--fail-on=silent` | [verdicts](verdicts.md#the-nine-verdicts) |
| How much it applies to this project: `high` fails on an abandoned direct production requirement, not on the same verdict on a transitive development package | a priority, e.g. `--fail-on=high` | [priority](verdicts.md#priority) |
| A finding whose check did not run: no token passed through, an exhausted rate limit | `--fail-on=unchecked` | [What was not checked](verdicts.md#what-was-not-checked); unflagged ones that fail are listed only with `--all`, or in `json` and `html` ([below](#unchecked-in-the-formats)) |
| Nothing: report only | `--fail-on=none` (the default) | |

- **Where to start.** Use `--fail-on=high`: a priority gates on what applies to this project. If
  the project does not start clean, add a [baseline](baseline.md): the same step then fails only on
  findings that are new or have got worse, and reads the default baseline file without a flag.

- **One threshold per run.** `unchecked` cannot be added to a verdict or priority threshold. To gate
  on both, run it as a second step.

Every accepted value is listed at [`fail-on` values](configuration.md#fail-on-values).

## Exit codes

| Code | Meaning | stdout |
|---|---|---|
| `0` | No finding reached `--fail-on`, apart from ones the baseline accepts (under `--fail-on=none` none can), and no `--strict-network` trip | The report; nothing under `--generate-baseline` |
| `1` | A finding reached `--fail-on` and the baseline does not already accept it, or `--strict-network` is on and a run note counts as a network failure (below) | The report; nothing under `--generate-baseline` |
| `2` | lockrot could not run or could not finish: an input, configuration or usage error, or a file it could not write | Nothing, except when an `--output` file fails (outside `--generate-baseline`): the report is already on stdout |

The exit code is the same for every output format. On stderr, a configuration or usage error starts
with `lockrot:`, any other failure with `lockrot failed:`; `-v` adds the stack trace to the second.

**Exit `2`** comes from:

- A file lockrot cannot write: an `--output` file or the baseline. `2` wins over `1`: a run whose
  gate has failed still exits `2` when a write then fails.
- A `composer.json` or `composer.lock` lockrot cannot parse, a missing `composer.lock`, or a lock
  entry Composer's loader rejects (no `name` or `version`, a version it cannot normalise).
- A value the configuration rejects: in `extra.lockrot`, in an environment variable, or on the
  command line ([configuration.md](configuration.md#extralockrot-keys)).
- A command line lockrot cannot read: an unknown option, an option without its value, a value given
  to a flag, an argument too many.
- A baseline lockrot cannot read ([baseline.md](baseline.md#when-lockrot-cannot-read-the-file)).
- An `--output` lockrot refuses before the analysis, such as an unknown format or `composer.lock`
  ([configuration.md](configuration.md#writing-reports-to-files)).
- An `--explain` lockrot cannot answer: a package the run did not analyse, or `--explain` combined
  with `--output` or with a format other than `table` and `json`
  ([configuration.md](configuration.md#explaining-one-package)).

**Network failures do not change the exit code on their own.** An unreachable Composer repository or
repository host becomes a [run note](notes.md), and the checks it blocked count as absent evidence
([S10](verdicts.md#what-was-not-checked)). With `--strict-network`, a run note whose
`sets_network_failures` is true exits `1`; that includes an `--offline` run where a locked package
has no cached metadata. To fail on the missing checks themselves, use `--fail-on=unchecked`.

**A security advisory alone never exits `1`.** When no fix is expected for it, it raises the
priority of an `abandoned`, `silent` or `left-behind` finding by one step
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
| Exit `1` with a report, or under `--generate-baseline` with a `lockrot: baseline written` line | lockrot's own exit `1`: a finding reached `--fail-on`, or `--strict-network` | Read the report; this is the gate working |
| Exit `1`, Composer's error box or Symfony's message, no `lockrot:` line | An unknown command: `composer lokrot`, `php lockrot.phar nope` | Fix the command name |
| Exit `1`, `There are no commands defined in the "json" namespace` | `php lockrot.phar --output json:r.json`: before the command name, a value after a space is read as the command | Join the value with `=`: `--output=json:r.json` |
| Exit `1`, Composer's error box about `composer.json`, in plugin mode | Composer cannot parse or validate `composer.json` | Fix the file; the PHAR reads it itself and exits `2` for it |

## GitHub Action

[somework/lockrot-action](https://github.com/somework/lockrot-action) v1 runs lockrot as one step;
its README lists the inputs.

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
| `github` | Annotations on `composer.lock` in the pull request | [below](#-formatgithub) |
| `sarif` | GitHub code scanning (Security tab) | [below](#-formatsarif) |
| `gitlab` | GitLab Code Quality | [below](#-formatgitlab) |
| `markdown` | A pull-request comment or job summary | [below](#-formatmarkdown) |
| `html` | One self-contained page to read, attach or publish | [below](#-formathtml) |
| `json` | Scripts and dashboards: every field | [below](#-formatjson) |

### How each format marks a finding {#how-each-format-marks-a-finding}

The formats with a severity give each finding one mark by the same rule, so the mark matches the
exit code. The first row that matches decides:

| # | The finding | `github` | `sarif` `level` | `gitlab` `severity` | `markdown` verdict |
|---|---|---|---|---|---|
| 1 | Accepted by the baseline (`known`) | `notice` | `note` | `info` | plain |
| 2 | Reaches `--fail-on` (for `--fail-on=unchecked`: carries S10, whatever its verdict) | `error` | `error` | `major` | bold |
| 3 | Any other flagged finding | `warning` | `warning` | `minor` | bold |
| 4 | Anything else (listed only with `--all`) | `notice` | `note` | `info` | plain |

- SARIF `rank` is the [priority](verdicts.md#priority) as a number: `critical` `100.0`, `high`
  `75.0`, `medium` `50.0`, `low` `25.0`, `none` `0.0`.
- A SARIF rule's default level is `warning` for a flagged verdict and `note` otherwise.
- A finding is marked `error` exactly where its json `gate.reaches_fail_on` is true and
  `gate.exempt_by` is not `baseline`. In a `--generate-baseline` run, `gate.fail_on_applied` is
  false, so such a finding fails nothing.
- `--strict-network` marks no finding: its cause is in the report's notes, which `gitlab` lacks.

### Where annotations point

Annotations or Code Quality issues that do not land on the lock come from a project in a
subdirectory of the checkout. `github`, `sarif` and `gitlab` name the analysed lock by its path
relative to the directory lockrot runs in (`composer.lock`, `alt.lock` under `COMPOSER=alt.json`,
`app/alt.lock` under `COMPOSER=app/alt.json`), or by its file name alone when it lies outside it.
For `github` and `gitlab`, GitHub and GitLab resolve that path against the checkout root, so
`-d app` still names it `composer.lock`. `sarif` also carries the directory the path is relative to,
as `%SRCROOT%` ([below](#-formatsarif)).

For a project in `app/`, run the [PHAR](phar.md#in-ci) from the checkout root with
`COMPOSER=app/composer.json`, which names the lock `app/composer.lock`. The
[action's README](https://github.com/somework/lockrot-action#readme) has a recipe for a project in a
subdirectory.

### `--fail-on=unchecked` in the formats {#unchecked-in-the-formats}

The findings that fail such a run are often `ok` ones carrying S10, which every format but `json`
and `html` lists only with `--all`. With `--all`, `github`, `sarif`, `gitlab` and `markdown` mark
them by row 2 of [How each format marks a finding](#how-each-format-marks-a-finding).
Pass `--all`, or read `gate` in the json report, to see which packages failed the step.

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

`if: always()` keeps the uploads running after `--fail-on` has failed the lockrot step.

## `--format=github` {#-formatgithub}

One [workflow command](https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions)
per flagged finding (every finding with `--all`), so each package becomes an annotation on its line
of `composer.lock` in the pull request's Files changed view.

```yaml
- name: lockrot
  run: composer lockrot --format=github --fail-on=high --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

The shape of one annotation:

```text
::<level> file=composer.lock,line=<line>,title=lockrot%3A <verdict> (<priority>)::<package> <version>: <evidence>
```

- The title is `lockrot: <verdict> (<priority>)`; the level follows the rule in
  [How each format marks a finding](#how-each-format-marks-a-finding).
- A transitive finding's message ends with the direct requirements that reach it:
  `(via a > b, also via c, d)` ([transitive exposure](verdicts.md#transitive-exposure)).
- The report's notes, the stale-baseline note included, follow as `::notice title=lockrot::`
  lines.
- The output ends with plain log lines, not annotations: `pulled in by:`, a count of the advisories
  on packages the report does not flag, pointing to `composer audit`, and the summary line with the
  full counts.
- GitHub displays a limited number of annotations per step. The step log holds every line, and
  `--format=sarif` uploads the complete set.

## `--format=sarif` {#-formatsarif}

A [SARIF 2.1.0](https://json.schemastore.org/sarif-2.1.0.json) document for GitHub code scanning,
with one result per flagged finding (every finding with `--all`). Code scanning keeps them in the
Security tab and tracks them across runs. The upload needs `security-events: write`:

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
| `ruleId` | `lockrot/<verdict>`; one rule per verdict among the results |
| `level` | The mark from [How each format marks a finding](#how-each-format-marks-a-finding) |
| `rank` | The priority as a number ([ranks](#how-each-format-marks-a-finding)) |
| `locations` | The package's line in the analysed lock |
| `partialFingerprints` | `lockrot/package`: the package name, so a finding keeps its identity when its line moves |
| `properties` | `package`, `version`, `verdict`, `priority`, `direct`, `dev`, `signals`, `chain`, `direct_dependents`, `data_date`, and on a flagged finding `baseline` (`known`, `new` or `worsened`) when the run read one |
| `invocations[].toolExecutionNotifications` | The report's notes |

`originalUriBaseIds.%SRCROOT%` is the directory the lock's path is relative to (the directory
lockrot runs in, or the lock's own when it lies outside it), as an absolute `file://` URL on the
machine that ran lockrot. Code scanning resolves the lock's path against it. To publish the file
anywhere else, strip it first ([what a report reveals](schema.md#where-a-package-came-from)):

```bash
jq 'del(.runs[].originalUriBaseIds)' lockrot.sarif > lockrot.public.sarif
```

## `--format=gitlab` {#-formatgitlab}

A [GitLab Code Quality](https://docs.gitlab.com/ci/testing/code_quality/#code-quality-report-format)
report: a JSON array with one issue per flagged finding (every finding with `--all`). Publish it as
a `codequality` artifact:

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

- `description` opens with the package, the version and `<verdict> (<priority>)`, and for a
  transitive package ends with `(via a > b, also via c, d)`:

    ```text
    <package> <version> — <verdict> (<priority>): <evidence>
    ```

- `severity` follows the rule in [How each format marks a finding](#how-each-format-marks-a-finding).
- `fingerprint` is a hash of the package name and the verdict. A version bump, a moved line, a
  change of priority or another `COMPOSER` manifest keeps the same issue; a change of verdict opens
  a new one.
- `location` is the package's line in the analysed lock.
- The format has no field for a document-level note, so the report's notes are not in it; use
  `--format=json` for them.

## `--format=markdown` {#-formatmarkdown}

A report shaped for a pull-request comment or a job summary. The steps after lockrot run with
`if: always()`, so the comment is posted when the gate fails too:

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

1. A heading: `### lockrot: dependency rot in <flagged> of <checked> packages`, or on a clean run
   `### lockrot: no dependency rot found in <checked> packages` and no table unless `--all` is
   given, which lists every package under it.
2. With a [baseline](baseline.md), its `known`/`new`/`worsened`/`stale` counts.
3. A table of the flagged findings (every finding with `--all`) in report order, led by
   [priority](verdicts.md#priority). Its `Via` column holds the shortest chain from a direct
   requirement and the other direct requirements that reach the package, shortened with
   `and N more`; `?` when nothing reaches it.
4. The libyears line, the `pulled in by:` line, and a count of the advisories on packages the
   report does not flag, pointing to `composer audit`.
5. The report's notes as a bullet list, and a `<sub>` footer with the summary counts.

The shape of the table:

```text
| Priority | Package | Version | Verdict | Evidence | Via |
|---|---|---|---|---|---|
| <priority> | `<package>` | <version> | **<verdict>** | <evidence> | direct, or <a> › <b>, also via <c> |
```

Every value from the project or from package metadata is rendered as plain text, with Markdown and
HTML punctuation backslash-escaped. A `composer.lock` under someone else's control cannot put an
image, a tag or link markup into the comment; a bare URL still autolinks.

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

The page is [lockrot-report](https://github.com/somework/lockrot-report), vendored at the
version the [changelog](changelog.md) names; what it shows and how to navigate it are in that
repository. It carries the run as JSON in `<script id="lockrot-data" type="application/json">`,
and that document's `report` key is the [report-1 document](schema.md), the part of the page that
is [contract](compatibility.md#what-is-not-contract).

Without `--all` the page carries release detail for flagged packages and for unflagged ones with an
advisory. `--all` adds that detail for every package, so the file grows with the lock.

**Content-Security-Policy.** The page carries its own policy (lockrot-report describes it). A host
that adds its own policy header gets the intersection of the two; `script-src 'unsafe-inline'` and
`style-src 'unsafe-inline'` in that header are enough.

### The JSON beside the page {#the-json-beside-the-page}

To keep the report as a JSON file next to the page, ask for both in one run:

```bash
composer lockrot --format=html --output=json:lockrot.json > lockrot-report.html
```

## `--format=json` {#-formatjson}

The complete report, and the only format that carries every field. It holds every finding, with or
without `--all`, and opens with `$schema`, the [report schema URL](schema.md).

It carries the evidence and data behind each signal, each finding's standing against the baseline
and the gate, and the run's thresholds and typed notes ([notes.md](notes.md)). `gate` records the
exit decision: `gate.fails`, its causes in `gate.tripped_by`, and each finding's own `gate`.
[schema.md](schema.md#what-the-report-schema-types) types every field and has a CI validation step;
[example-run.md](example-run.md) has a worked excerpt.

## Related

- [baseline.md](baseline.md) — adopt lockrot on a project that does not start clean
- [configuration.md](configuration.md) — every option, key and environment variable, and `--output` in full
- [verdicts.md](verdicts.md) — what each verdict, priority and signal means
- [schema.md](schema.md) — the JSON schemas and what a report reveals
- [notes.md](notes.md) — every run note and what it means for the exit code
- [phar.md](phar.md) — running the standalone PHAR in CI
