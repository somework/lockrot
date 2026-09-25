---
title: lockrot in CI — GitHub Actions, GitLab CI, SARIF, exit codes
description: "Run lockrot in a pipeline: the --fail-on threshold, exit codes 0, 1 and 2, the GitHub Action, and the github, sarif, gitlab, markdown, json and html output formats."
---

# Running lockrot in CI

Add one step to the pipeline and pick the threshold that should fail it:

```bash
composer lockrot --fail-on=silent --target-php=8.4
```

`--fail-on` takes a verdict (`silent`: fail on what was observed, wherever the package sits) or a
[priority](verdicts.md#priority) (`high`: fail on how much it applies to this project — an abandoned
direct production requirement fails, the same verdict on a transitive development package does not).
`--fail-on=unchecked` is neither: it fails on a finding whose check did not run — the workflow that
forgot to pass `GITHUB_TOKEN` through, an exhausted rate limit — so an incomplete run is not read as
a clean one ([What was not checked](verdicts.md#what-was-not-checked)).

With a committed [baseline](baseline.md) the same command fails only on new or worsened findings; no extra flag is
needed, the file is picked up automatically. To run without installing the plugin, use the PHAR — [phar.md](phar.md)
has the CI snippet.

## GitHub Action

[somework/lockrot-action](https://github.com/somework/lockrot-action) is the one-step form for GitHub Actions: it
downloads the release it pins, checks the archive's sha256, uses the runner's PHP (or installs one), caches repository
metadata between runs, writes the report to the job summary and applies lockrot's exit code.

```yaml
- uses: somework/lockrot-action@v1
  with:
    target-php: '8.4'
    fail-on: silent
```

Every lockrot option is an input; its README has recipes for SARIF, pull-request comments, baselines and projects in a
subdirectory. The same repository publishes `ghcr.io/somework/lockrot`, a signed image for GitLab CI and other systems.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | No finding reached the `fail-on` threshold (or `fail-on=none`) |
| `1` | A finding reached or exceeded the `fail-on` threshold, or carried an unrun check under `--fail-on=unchecked` |
| `2` | Tool, configuration or usage error: unparsable `composer.json`/`composer.lock`, invalid config value, unreadable or unwritable [baseline](baseline.md); an `--output` that names `composer.json`, `composer.lock` or the baseline, an unknown format, an empty or repeated path, a directory that does not exist, or a file that cannot be written; a command line lockrot cannot read — an unknown option, an option missing its value, a value given to a flag, an argument too many |

A `2` writes nothing to stdout, except an `--output` file that cannot be written: that one is found after stdout has
the report. stderr says what went wrong, starting `lockrot:` for a configuration or usage error and `lockrot failed:`
for anything else.

`composer audit` follows the same convention: exit `1` when it finds a security advisory or, with Composer's default
`audit.abandoned=fail`, an abandoned package; exit `0` when it finds nothing. lockrot reads the same advisories
([signal S9](verdicts.md#security-advisories)) but never exits `1` for one alone: it raises the priority of a finding
nobody will fix, and the exit code follows `--fail-on` as usual.

A network failure — a configured Composer repository or a repository host (GitHub, GitLab, Bitbucket) unreachable — never turns into a non-zero exit code on
its own. It is reported as a note, and the checks that could not run are treated as absent evidence.
`--strict-network` changes that to exit `1`. This also covers `--offline` runs where a locked package has no cached
metadata: it is reported as a failure, not silently skipped. A `composer.lock` entry that Composer's own loader cannot
load (missing `name`/`version`, an unnormalizable version, a malformed entry) stops the report with exit `2` rather
than being skipped.

As a Composer plugin, a `composer.json` that Composer itself cannot parse never reaches lockrot at all: Composer parses
the project's manifest while collecting plugin commands, before any plugin class is loaded, so it stops with its own
exit `1` first. `composer lockrot` on an unparsable `composer.json` exits `1`, not `2`. The standalone PHAR reads and
validates `composer.json` itself, so the same failure there is exit `2`. `lockrot.phar self-update` uses the same three
codes with its own meanings; see [phar.md](phar.md).

More generally, an exit `1` can come from Composer or Symfony before lockrot runs at all — an unknown command name
(`lockrot.phar nope`, `composer lokrot`), or Composer stopping on its own while it starts up. That exit `1` puts
Composer's error box on stderr instead of a `lockrot:` line and writes no report, which is how a gate tells it apart
from findings.

Like Composer, lockrot reads the manifest the `COMPOSER` environment variable names: `COMPOSER=alt.json composer
lockrot` reads `alt.json` and `alt.lock`, and the default baseline sits next to `alt.json`.

With `LOCKROT_DISABLE=1` the analysis skips all of this: it reads nothing — not the command line, `composer.json`,
`extra.lockrot` or the lock — prints `lockrot disabled via LOCKROT_DISABLE` on stderr and exits `0`. It does not
disable `lockrot.phar self-update`.

The [install-time summary](install-time.md) never sets an exit code unless `install-time-strict` is on. The exit code
is identical for every output format below; only the output changes.

## Several reports from one run

`--format` decides what goes to stdout; `--output=<format>:<path>`, repeatable, writes more formats to files from the
same run. The analysis runs once, so every file carries the same report — the same clock, the same findings, the same
notes — where two runs could disagree on all three: a second run is a second round of requests, and offline it has its
own notes and its own reasons for what it could not check. Annotations on stdout, and SARIF, a page and the JSON
document as files:

```yaml
permissions:
  contents: read
  security-events: write

steps:
  - uses: actions/checkout@v7
  - name: lockrot
    run: >-
      composer lockrot --format=github --fail-on=silent --target-php=8.4
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
    uses: actions/upload-artifact@v4
    with:
      name: lockrot-report
      path: |
        lockrot-report.html
        lockrot.json
```

Each file is byte for byte what its `--format` prints; a `table` file has no colours and is wrapped at 120 columns.
Each one is written atomically after stdout, and named on stderr (`lockrot: sarif report written to lockrot.sarif`).
A relative path is relative to the project directory (`-d` sets it), and the directory must exist: lockrot creates
none. A path naming `composer.json`, `composer.lock` or the [baseline](baseline.md) is refused before the analysis
starts (exit `2`); otherwise the exit code is the one `--fail-on` decides, with or without `--output`. The
`> lockrot.sarif` redirections below still work; `--output` is for when one run should feed several consumers. Every
rule is in [configuration.md](configuration.md#writing-reports-to-files).

## `--format=github`

Workflow commands, one per finding, so every flagged package becomes an annotation on its own line of `composer.lock`
in the pull request's Files changed view:

```yaml
- name: lockrot
  run: composer lockrot --format=github --fail-on=silent --target-php=8.4
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

```text
::error file=composer.lock,line=8010,title=lockrot%3A abandoned (critical)::sensio/framework-extra-bundle v6.2.10: marked abandoned by its repository, replacement: Symfony; …
```

Findings at or above `--fail-on` are annotated as errors, everything else flagged as warnings, and the rows that only
`--all` shows as notices — so the annotation colour matches the exit code. The report's own notes are printed as
notices too, so a run can emit notices without `--all`. Under a verdict threshold the [priority](verdicts.md) changes
none of that: a `critical` finding below `--fail-on=silent` is still a warning. Under a priority threshold
(`--fail-on=high`) the line between error and warning is drawn by priority instead, in every format alike. With a
[baseline](baseline.md) in place, findings it already
carries drop to notices for the same reason. GitHub renders only a limited number of annotations per step, so
on a large lock file the annotations are the headline and the step's own log holds every finding. The summary line at
the end of the output always states the full counts, and `--format=sarif` uploads the complete set. A transitive
finding's message names the direct requirements it is reachable from — `(via a > b, also via c, d)` — and the
`pulled in by:` line before the summary sums that up per direct requirement, as a plain log line rather than an
annotation ([transitive exposure](verdicts.md#transitive-exposure)).

## `--format=sarif`

A [SARIF 2.1.0](https://json.schemastore.org/sarif-2.1.0.json) document for GitHub code scanning, which keeps the
findings in the repository's Security tab and tracks them across runs. The upload step needs the
`security-events: write` permission:

```yaml
permissions:
  contents: read
  security-events: write

steps:
  - uses: actions/checkout@v7
  - name: lockrot
    run: composer lockrot --format=sarif --fail-on=silent --target-php=8.4 > lockrot.sarif
    env:
      GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  - name: Upload SARIF
    if: always()
    uses: github/codeql-action/upload-sarif@v4
    with:
      sarif_file: lockrot.sarif
```

`if: always()` keeps the upload running when `--fail-on` already failed the step. Each result carries the
[priority](verdicts.md) as `rank`, the field SARIF 2.1.0 defines for it, and the same result's `properties` carry,
among others, `priority`, `direct`, `dev`, `chain` and `direct_dependents` — every direct requirement the package is
reachable from, see [transitive exposure](verdicts.md#transitive-exposure). The rule a result points at follows the
verdict; its `level` follows `--fail-on`, whichever kind of threshold it names. Both `github` and `sarif` point at `composer.lock` in the checkout root, so run them from
the directory that holds the lock file.

## `--format=gitlab`

A [GitLab Code Quality](https://docs.gitlab.com/ci/testing/code_quality/#implement-a-custom-tool) report: a JSON array
with one issue per flagged finding (every finding with `--all`), so a merge request shows them inline in the diff of
`composer.lock`. Publish it as a `codequality` artifact:

```yaml
lockrot:
  script:
    - composer lockrot --format=gitlab --fail-on=silent --target-php=8.4 > lockrot-codequality.json
  artifacts:
    reports:
      codequality: lockrot-codequality.json
```

Code Quality has no title field of its own, so each issue's description opens with the package, the version and the
same `<verdict> (<priority>)` phrase the GitHub annotation title uses:

```text
sensio/framework-extra-bundle v6.2.10 — abandoned (critical): marked abandoned by its repository, replacement: Symfony; …
```

For a transitive package the description ends with the chain and the other direct requirements that reach it
(`(via a > b, also via c, d)`). Severity follows the same rule as the GitHub and SARIF level: a finding at or above
`--fail-on` is `major`, any other flagged verdict `minor`, and a row only `--all` shows (or one a
[baseline](baseline.md) already knows) `info`. Each issue's fingerprint is a stable hash of the package name and
verdict, so a version bump that keeps the same verdict — or a reformatted lock that moves the entry to a different
line — keeps the same GitLab issue identity. The priority is
deliberately not part of it, so moving a package from `require` to `require-dev` does not open a second issue for a
finding GitLab already tracks. GitLab's Code Quality format has no field for a document-level note, so notes are
dropped here; use `--format=json` when you need them.

## `--format=markdown`

A report shaped for a pull-request comment: a heading with the flagged/checked counts, a table of the findings led by
their [priority](verdicts.md) — its `Via` column naming every direct requirement a transitive package is reachable
from — the `pulled in by:` line under the table, the report's notes as a bullet list, and a `<sub>` footer with the
full summary.

```bash
composer lockrot --format=markdown --fail-on=silent --target-php=8.4 > comment.md
gh pr comment --body-file comment.md
```

```markdown
| Priority | Package | Version | Verdict | Evidence | Via |
|---|---|---|---|---|---|
| critical | `sensio/framework-extra-bundle` | v6.2.10 | **abandoned** | marked abandoned by its repository, replacement: Symfony; … | direct |
| high | `hoa/ruler` | 2.17.05.16 | **abandoned** | marked abandoned by its repository; … | wallabag/rulerz, also via wallabag/rulerz-bundle |
| high | `friendsofsymfony/oauth-server-bundle` | dev-master | **pinned** | last release 2019-01-23 (7.6 years ago); pinned to branch snapshot dev-master; pulls in 2 flagged packages: symfony/security-guard (abandoned), … | direct |

pulled in by: wallabag/rulerz-bundle 15 · wallabag/rulerz 14 · wallabag/phpepub 5 · …
```

The evidence cells are abridged here; the real cells carry every signal. Everything that comes from the project or
from package metadata is rendered as plain text — Markdown and HTML punctuation is backslash-escaped — so a
`composer.lock` under someone else's control cannot put an image, a tag or link markup into the comment. Rows keep the
report's order, so a reviewer reads down the first column and stops where the rows stop applying. A clean run prints
`### lockrot: no dependency rot found in N packages` and no table. With a [baseline](baseline.md) in place, a second
line under the heading carries the same `known`/`new`/`worsened`/`stale` counts as the table format, and a verdict is
bold only when the baseline has not already accepted it.

## `--format=html`

The whole run as one page, for the person who has to act on it rather than the machine that gates it.
No server and no network: the report, the release branches behind every finding and the baseline
comparison all sit inside a single file, so it opens from `file://`, uploads as one CI artifact and
attaches to a ticket.

```bash
composer lockrot --format=html --target-php=8.4 > lockrot-report.html
```

```yaml
- run: composer lockrot --format=html --target-php=8.4 > lockrot-report.html
  if: always()
- uses: actions/upload-artifact@v4
  if: always()
  with:
    name: lockrot-report
    path: lockrot-report.html
```

`if: always()` keeps the upload running when `--fail-on` already failed the step, which is exactly
when somebody wants to read the page.

What the page has that a stream cannot:

- **One line per signal, not one line per finding.** A terminal row has one line, so a package with
  four signals gets one sentence with four semicolons in it. Here each signal keeps its own line and
  its own id, which links to what that signal observes.
- **The release branches on a time axis.** Every branch the repository lists, its newest dated
  release, the branch you are installed on and the one still shipping — the shape of being left
  behind, rather than two dates to subtract.
- **Advisories grouped by what the fix costs.** `fixed_by` and `fixed_on_branch` split them into
  the ones a patch on your own branch clears and the ones that need a move to another branch. A
  package with fourteen advisories is usually two tickets, not one.
- **What changed since the [baseline](baseline.md).** New, worsened and already-accepted, filterable.
- **How far behind the lock is.** The [libyears](verdicts.md#libyears) total in the ledger, and every
  package's own value as a sortable column.
- **What the run could not see.** The notes, the thresholds it used and the schema it validates
  against, on their own tab.

Filters, the open package and the search live in the URL hash, so the address bar is always a link
to what is on screen: filter a report published on Pages or served from a CI artifact, copy the
address, and whoever opens it lands on the same three packages rather than on eighty findings. The
query understands `verdict:`, `priority:`, `signal:`, `severity:`, `cve:`, `direct:` and `dev:`;
`/` searches, `j`/`k` move, `?` opens a glossary of every verdict and signal.

The page carries the run as JSON, and that payload's `report` key is the document `--format=json`
writes, [schema](schema.md), envelope and every field — the page's copy is compact where the
formatter pretty-prints, and identical once parsed:

```bash
composer lockrot --format=html > lockrot-report.html
sed -n 's/.*<script id="lockrot-data" type="application\/json">\(.*\)<\/script>.*/\1/p' lockrot-report.html \
  | jq .report > lockrot.json
```

Nothing in the page is fetched — no fonts, no CDN, no analytics — so it renders the same offline and
from a downloaded artifact. The page carries its own Content-Security-Policy: its one inline script
and one stylesheet are pinned by sha256, and every other source, `connect-src` included, is `'none'`,
so nothing the page renders can run or be sent anywhere. A host that adds a policy of its own in a
response header gets the intersection of the two; `script-src 'unsafe-inline'` and
`style-src 'unsafe-inline'` in the header are enough, because the page's own policy narrows them to
its hashes. Every other directive can stay shut. `--all` puts every package
in it, at roughly 4 KB a package; without it a 100-package lock lands around 300 KB.

## `--format=json`

The complete report, and the only format that carries every field: per-finding `signals`, `chain`,
`direct_dependents`, `evidence`, `data_date`, `libyears` and `baseline` — where that finding stands against the
baseline file — plus the document's `run`, `exposure`, `libyears` and `notes`. `run` is what the report is about and what
its verdicts were decided against: the project's own name from composer.json, the target PHP, the
thresholds, the `fail-on` and the name of the lock, with the list of verdicts the run counted as
findings. Without it a report could not be read twice the same
way, because the same lock under different thresholds gives different verdicts and nothing said
which had been used. The
document opens with `$schema`, naming its published JSON schema at
[`https://lockrot.dev/schema/report-1.json`](https://lockrot.dev/schema/report-1.json); see
[schema.md](schema.md) for what it types, the compatibility rule behind the number, and a CI
validation step.
[example-run.md](example-run.md) has a worked excerpt. Signal `S7` on a direct requirement carries every package it
pulls in, each with its chain, under `data.packages` — uncapped, so on a lock with many direct requirements and much
transitive rot that part of the document is the large one.
