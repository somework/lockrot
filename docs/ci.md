---
title: lockrot in CI — GitHub Actions, GitLab CI, SARIF, exit codes
description: "Run lockrot in a pipeline: the --fail-on threshold, exit codes 0, 1 and 2, the GitHub Action, and the github, sarif, gitlab, markdown and json output formats."
---

# Running lockrot in CI

Add one step to the pipeline and pick the threshold that should fail it:

```bash
composer lockrot --fail-on=silent --target-php=8.4
```

`--fail-on` takes a verdict (`silent`: fail on what was observed, wherever the package sits) or a
[priority](verdicts.md#priority) (`high`: fail on how much it applies to this project — an abandoned
direct production requirement fails, the same verdict on a transitive development package does not).

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
| `1` | A finding reached or exceeded the `fail-on` threshold |
| `2` | Tool or configuration error (unparsable `composer.json`/`composer.lock`, invalid config value, unreadable or unwritable [baseline](baseline.md)) |

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

The [install-time summary](install-time.md) never sets an exit code unless `install-time-strict` is on. The exit code
is identical for every output format below; only the output changes.

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

## `--format=json`

The complete report, and the only format that carries every field: per-finding `signals`, `chain`,
`direct_dependents`, `evidence` and `data_date`, plus the document's `exposure` and `notes`.
[example-run.md](example-run.md) has a worked excerpt. Signal `S7` on a direct requirement carries every package it
pulls in, each with its chain, under `data.packages` — uncapped, so on a lock with many direct requirements and much
transitive rot that part of the document is the large one.
