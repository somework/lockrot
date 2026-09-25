---
title: lockrot compatibility — what 1.0 freezes, and what it does not
description: Draft of the lockrot 1.0 compatibility promise — the machine-readable contract, the closed sets and their order, how verdicts may change, reserved names, deprecation, and what lockrot never does.
---

# Compatibility

!!! warning "Draft until 1.0.0-RC1"
    This page describes the promise lockrot will make at 1.0.0. Until then lockrot is 0.x and, as
    Semantic Versioning allows, a minor release may change anything; the [changelog](changelog.md)
    says what did. The page becomes binding with the first release candidate. Items marked
    *planned* are not in lockrot yet.

1.0 promises what a machine reads or writes: the command line and its exit codes, the configuration
keys, the machine-readable formats (`json`, `sarif`, `gitlab`, `github`) and the baseline file.
`table`, `markdown` and `html` are for people and may change in any release.

It does not promise which verdict a given package gets. That is the product getting better, and it
changes in minor releases under a heading of its own — see [Verdict changes](#verdict-changes).

## What 1.0 freezes

### Documents

- The report, the explanation, the baseline file and the configuration — report-1, explain-1,
  baseline-1 and config-1 — only ever gain fields within 1.x. That is the rule
  [schema.md](schema.md#the-number-and-what-may-change-under-it) already states for the schema
  number.
- Their URLs under `https://lockrot.dev/schema/` stay published for all of 1.x. The authoritative copy
  is the one in `resources/` of the release you run.
- *Planned:* the schemas are tested against recorded documents from earlier releases, so a field
  cannot quietly become required.
- *Planned:* lockrot 2.x keeps writing report-1 behind `--schema=1`.
- Human-readable strings inside those documents — a signal's `summary`, a note, an evidence sentence —
  are text, not contract. Key on ids and data fields, never on wording.

### Closed sets and their order

Four sets are closed: nothing is added to them, removed from them or reordered within 1.x.

- Verdicts, most severe first: `abandoned`, `silent`, `pinned`, `left-behind`, `old-promise`, `stale`, `unknown`, `finished`, `ok`.
- Priorities, highest first: `critical`, `high`, `medium`, `low`, `none`.
- Signal levels, lowest first: `info`, `warn`, `high`.
- A finding's standing against the baseline: `known`, `new`, `worsened`.

The document number `lockrot.schema` is `1` for all of 1.x.

The first six verdicts are the flagged ones. They are what the report lists in
`run.flagged_verdicts`, the only verdicts a baseline entry holds, and the only verdicts `--fail-on`
accepts. `finished` and `ok` share the lowest severity: no threshold and no comparison tells them
apart. Where lockrot lists verdicts — `counts`, `run.flagged_verdicts`, the schema enums, this page —
`finished` comes first; the SARIF rules appear in the order the results first use them.

The order decides four things:

- `--fail-on`: a finding fails the run at or above the threshold.
- The baseline: a finding is `worsened` when its verdict is more severe than the one the baseline
  accepted.
- The report's own order: priority, then verdict, then direct before transitive, then name.
- The SARIF `rank`, which follows the priority.

A new verdict, priority, level or standing would need report-2 and lockrot 2.0. The test suite holds
the code, the published schemas and this page to the same lists in the same order. See
[verdicts.md](verdicts.md) for what each value means.

### Open sets

These grow in minor releases:

- signal ids;
- the checks, reasons and blocked signals of S10;
- S8's `floor_source`;
- repository hosts;
- format names.

A consumer treats a value it does not know as "other".

*Planned (before 1.0):* the schemas describe these as open strings. Until then a new value arrives
with a schema update, and a vendored copy of the schema needs refreshing. The changelog says so when
that happens.

### Finding identity

- lockrot reports one finding per package per lock, for all of 1.x.
- The baseline key is the package name.
- GitLab Code Quality: `check_name` is `lockrot/<verdict>`, and `fingerprint` is the sha256 of
  `lockrot|<package>|<verdict>`. The verdict is part of the fingerprint on purpose, and it is not part
  of the baseline key.
- SARIF: `ruleId` is `lockrot/<verdict>`, and `partialFingerprints` holds `lockrot/package` with the
  package name.
- GitHub annotations: the title is `lockrot: <verdict> (<priority>)`, on the package's line of
  `composer.lock`.

### Severity mapping

Each finding gets one level. The rules below are applied in order, and the first one that matches
decides:

| # | The finding is… | SARIF `level` | GitLab `severity` | GitHub annotation |
|---|---|---|---|---|
| 1 | already `known` to the baseline | `note` | `info` | `notice` |
| 2 | at or above `--fail-on`: a verdict or priority at or above the threshold, or — for `--fail-on=unchecked` — any finding carrying S10, whatever its verdict | `error` | `major` | `error` |
| 3 | any other flagged verdict | `warning` | `minor` | `warning` |
| 4 | anything else (shown only with `--all`) | `note` | `info` | `notice` |

- SARIF `rank` follows the priority: `critical` 100.0, `high` 75.0, `medium` 50.0, `low` 25.0,
  `none` 0.0.
- A SARIF rule's default level is `warning` for a flagged verdict and `note` otherwise.

No schema checks this table, but it is contract all the same: changing it changes which pull
requests are blocked.

### Command line

- The commands: `composer lockrot` (alias `composer rot`), and the PHAR's `lockrot` and
  `self-update`.
- The options documented in [configuration.md](configuration.md#cli-options).
- The format names `table`, `json`, `github`, `sarif`, `gitlab`, `markdown` and `html`. All seven
  names are fixed; only the contents of the machine-readable four are contract.
- The `--fail-on` values.
- The variables listed in [Environment overrides](configuration.md#environment-overrides). The
  [testing hooks](configuration.md#testing-hooks) are not included.
- Exit codes are the closed set `0`, `1` and `2`, as [ci.md](ci.md#exit-codes) defines them.
  - One failure differs by distribution: under the plugin, an unparsable `composer.json` exits `1`,
    because Composer stops before lockrot runs.
  - `self-update` gives the same three codes meanings of its own ([phar.md](phar.md#self-update-exit-codes)).
- Messages about lockrot itself — a failure, a warning, a deprecation — go to stderr, never into the
  report on stdout. A report's own notes are part of the report.
- `--fail-on=unchecked` can match more findings after a minor release. A new reason a check could not
  run, or a newly supported host, is a [Verdict change](#verdict-changes).

### Configuration

- `extra.lockrot` keys are never removed within 1.x.
- Precedence: CLI options, then environment variables, then `extra.lockrot`, then an `extends`d file
  (*planned*), then the defaults. The first one that sets a value wins.
- From 0.13, each `extra.lockrot` key lockrot does not know prints one warning on stderr,
  suggesting the closest known key when one is near, and the run goes on. Nested keys are checked
  too. The reserved `extensions` key and keys that start with `x-`, at either level, never warn.
- The default thresholds are not frozen. They are chosen at the RC, and a later change to them is a
  [Verdict change](#verdict-changes). If you need fixed numbers, set them in `extra.lockrot`.

### Blocking at `composer require`

Today, [`install-time-strict`](install-time.md#install-time-strict) is how a project stops a
`composer require` that would bring in a finding at or above `fail-on`. It is part of the
configuration contract above.

Composer 2.10 can also read a policy from a source the project configures. Whether lockrot will serve
one — as a command of its own, as a plugin hook, or only as an Experimental format — is undecided,
pending a spike. Nothing on this page promises any of the three.

### Distribution

- The Composer plugin, the signed PHAR, the Docker image `ghcr.io/somework/lockrot` and
  `somework/lockrot-action` run the same code and write the same documents.
- The PHAR's asset names and the verification path in [phar.md](phar.md) are stable.
- `lockrot-action@v1` follows lockrot 1.x, and its inputs follow semver. lockrot 2.0 means action v2.
- The Docker tag scheme is decided at the RC.
- *Planned:* `self-update` stays within the running major version, skips a release whose PHP floor is
  above the running PHP, and updates through the transition release of a signing-key rotation.
  - Today `self-update` installs the newest release, whatever it is.
  - The rules will protect only archives from the release that introduces them on;
    [phar.md](phar.md#keeping-it-updated) will say which.

### PHP platform

- lockrot runs on PHP 7.4+ with Composer 2.2+ today. The floor for 1.x is chosen at the RC.
- *Planned:* within 1.x the floor rises only in a minor release, never in a patch. A warning on stderr
  comes one minor release ahead, and `self-update` protects older runtimes.

## What is not contract

- **Human-readable output:** `table`, `markdown`, the `--explain` text, the install-time summary, and
  the HTML page's look. The page embeds the report-1 document under its `report` key, and that
  document is covered above. The rest of the page's payload is an internal format between lockrot and
  its renderer, pinned by that renderer's manifest.
- **Which verdict and which priority a package gets:** thresholds and their defaults, heuristics,
  the priority ladder (the level each verdict starts at, the steps down for transitive and
  development packages, the step up for an advisory no release will fix), the curated data in
  `resources/` (`finished-packages.json`, `monorepo-parents.json`, `php-ga-dates.json`), the
  repository-host clients, and S10's reasons. All of these change under
  [Verdict changes](#verdict-changes).
- **The PHP classes under `src/`.** They are internal and may change in any release.

## Verdict changes

Semantic Versioning covers the shape and the names. It cannot cover which verdict a package gets,
because that is what improving lockrot changes. So:

- A release changes the verdict or the priority a package gets only in a minor version, never in a
  patch. It lists every such change in the changelog under **Verdict changes**. That heading is used
  from 0.13 on.
- A patch may correct curated data only when the correction removes a false verdict: a package moves
  to `finished` or `ok`, never to a flagged verdict.
- A new signal that decides verdicts ships for one minor release as evidence only. It appears in the
  report and decides nothing; it starts deciding in the next minor.
- New reasons a check could not run, and newly supported hosts, widen `--fail-on=unchecked` and can
  change verdicts. Both are Verdict changes.

A committed [baseline](baseline.md) does not make an upgrade silent:

- It absorbs every finding it already accepted.
- A package a new release starts flagging has no baseline entry, so it counts as `new`.
- A package whose verdict becomes more severe counts as `worsened`.
- Either one fails a run at or above `--fail-on`, even on a lock that did not change.

The baseline compares verdict severity only, never priority, so a priority change alone never makes a
finding `worsened`. Time works the same way as an upgrade: an unchanged lock can cross a threshold
simply because a year has passed.

Before upgrading, read the release's Verdict changes, or pin the lockrot version and upgrade on
purpose. *Planned:* a comparison of the base branch's lock and the pull request's lock in one run, with
one clock and one set of fetched data, so a pull request fails only on what it changed.

## Extending lockrot

1.0 ships no plugin API. There are three routes instead:

- **JSON out.** report-1 and explain-1 are the same from the plugin, the PHAR, the Docker image and
  the Action. Dashboards, fleet summaries, other formats and organisation policy (a `jq -e` gate)
  are programs over those documents.
- **Composer configuration in.** lockrot finds repository hosts through Composer's own settings —
  today `gitlab-domains` — and has no host keys of its own.
- **A pull request for code.** New signals, S10 reasons, hosts and fields go into lockrot itself, in
  minor releases, following the additive rule above and [Verdict changes](#verdict-changes).

### Names reserved for extensions

These names are reserved so that an extension mechanism can arrive later without breaking anyone:

- `S<n>` belongs to lockrot. A retired signal keeps its number, and no number is reused.
- Anything that does not come from lockrot — a signal, a format — is named `<vendor>:<name>`. No name
  lockrot ships contains a colon.
- lockrot will never give a meaning of its own to:
  - the `extra.lockrot.extensions` key;
  - any `extra.lockrot` key that starts with `x-`;
  - any environment variable that starts with `LOCKROT_X_`.
- The PHP namespace `Lockrot\Extension\` is reserved. Nothing in it exists, and reserving it
  promises nothing about what will.

## Deprecation

- Nothing in the contract is removed within 1.x.
- An option, environment variable, configuration key, format name or Action input can be deprecated
  in a minor release. From then on it:
  - keeps working unchanged until the next major version;
  - prints one line to stderr when used;
  - is listed under `Deprecated` in the changelog and in the table below.

  It is removed only in the next major version, and at least six months after it was deprecated.
- Exit codes and format names never take on a new meaning.
- A JSON field is never deprecated on its own. It keeps being written, the schema marks it
  `x-deprecated: true`, and it disappears only with report-2.
- A signal is retired rather than removed.
- An Experimental surface can change in any minor release, with a line in the changelog.

| Deprecated | Experimental |
|---|---|
| nothing | nothing |

## What lockrot does not do

- In your project, lockrot writes only the files you name: reports with `--output` and the baseline
  with `--generate-baseline`. It never writes `composer.json` or `composer.lock`. Outside the project
  it writes only its activity cache, under Composer's cache directory, and `self-update` replaces the
  PHAR; Composer writes its own metadata cache there too, as it would for any install.
- It opens no pull request or merge request, and it changes no code.
- It has no hosted service, no account, no telemetry and no usage metering. It talks to:
  - the Composer repositories your project configures, through Composer;
  - GitHub, GitLab and Bitbucket, for repository activity;
  - GitHub's release API, for `self-update` only.

  `--offline` talks to none of them.
- Anything lockrot may serve later, such as a Composer policy source, runs where you run it.

## Related

- [schema.md](schema.md) — the schemas and the rule behind their numbers
- [verdicts.md](verdicts.md) — what each verdict, priority and signal means
- [ci.md](ci.md) — exit codes and the output formats
- [configuration.md](configuration.md) — `extra.lockrot`, the environment and the CLI
- [phar.md](phar.md) — the PHAR, its verification and `self-update`
- [changelog.md](changelog.md) — what changed, release by release
