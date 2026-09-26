---
title: lockrot compatibility — what 1.0 freezes, and what it does not
description: Draft of the lockrot 1.0 compatibility promise — the machine-readable contract, the closed sets and their order, how verdicts may change, reserved names, deprecation, and what lockrot never does.
---

# Compatibility

!!! warning "Draft until 1.0.0-RC1"
    This page describes the promise lockrot will make at 1.0.0. Until then lockrot is 0.x and, as
    Semantic Versioning allows, a minor release may change anything; the [changelog](changelog.md)
    says what did. The page becomes binding with the first release candidate. One rule applies
    already, as project practice from 0.13 on: how a release may change a verdict, in
    [Verdict changes](#verdict-changes). Items marked *planned* are not in lockrot yet.

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
- The schemas are tested against what earlier releases published and wrote — each release's
  schemas from 0.9.0 on, and the report, baseline and explanations the 0.9.0, 0.10.0 and 0.11.0
  archives wrote — so a field cannot quietly become required, narrowed or dropped.
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
`finished` comes before `ok` wherever both appear; the SARIF rules appear in the order the results
first use them.

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

A consumer treats a value it does not know as "other": it shows the value as written, and does not
fail on it.

The schemas describe signal ids, S10's checks, reasons and blocked signals, S8's `floor_source` and
the configuration's `format` as open strings: a `pattern`, which also admits the `<vendor>:<name>`
form for a signal id and a format, plus `x-known-values`, the values lockrot writes, which only grows
within 1.x. A signal whose id is not listed validates with any object as its `data`; a listed id
keeps its `data` typed. A consumer that validates strictly reads `x-known-values` as an enum and
refreshes its copy on upgrade. A copy of a schema taken before 0.13.0 still holds these as enums.
Repository hosts are in no schema as a set: S3 and S4's `host` and the explanation's `forge` are plain
strings. lockrot itself accepts only the format names it knows, in `--format`, `--output` and
`extra.lockrot.format`.

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
  `self-update` (alias `selfupdate`).
- The options documented in [configuration.md](configuration.md#cli-options), and `self-update`'s
  `--check`, `--force`, `--offline` and `--allow-major`, documented with its exit codes in
  [phar.md](phar.md#keeping-it-updated).
- The format names `table`, `json`, `github`, `sarif`, `gitlab`, `markdown` and `html`. All seven
  names are fixed; only the contents of the machine-readable four are contract.
- The `--fail-on` values.
- The variables listed in [Environment overrides](configuration.md#environment-overrides). The
  [testing hooks](configuration.md#testing-hooks) are not included.
- Exit codes are the closed set `0`, `1` and `2`, as [ci.md](ci.md#exit-codes) defines them.
    - lockrot's own commands exit `2` on every usage or configuration error: an unknown option, a
      missing or invalid value, an `extra.lockrot` the schema rejects.
    - Exit `1` can also come from Composer or Symfony, before lockrot runs: an unknown command, or
      an error Composer raises itself — under the plugin, a `composer.json` Composer cannot parse or
      that fails Composer's own schema. A `1` from lockrot comes with a report, or under
      `--generate-baseline` with a `lockrot: baseline written` line; one from before lockrot runs
      comes with neither, and with Composer's or Symfony's message rather than a `lockrot:` line.
    - `self-update` gives the same three codes meanings of its own
      ([phar.md](phar.md#self-update-exit-codes)).
- Messages about lockrot itself — a failure, a warning, a deprecation — go to stderr, never into the
  report on stdout. A report's own notes are part of the report.
- `--fail-on=unchecked` can match more findings after a minor release. A new reason a check could not
  run, or a newly supported host, is a [Verdict change](#verdict-changes), so it never arrives in a
  patch.

### Configuration

- `extra.lockrot` keys are never removed within 1.x.
- Precedence: CLI options, then environment variables, then `extra.lockrot`, then an `extends`d file
  (*planned*), then the defaults. The first one that sets a value wins. One exception: a credential
  Composer already holds for a host (`auth.json`, `COMPOSER_AUTH`) is the one sent, whatever the
  token variables say — see [configuration.md](configuration.md#environment-overrides).
- From 0.13, each `extra.lockrot` key lockrot does not know prints one warning on stderr,
  suggesting the closest known key when one is near, and the run goes on. The keys inside each
  `ignore` entry are checked too. Reserved names never warn: `extensions` at the top level of
  `extra.lockrot`, and any key that starts with `x-`, at the top level or inside an `ignore` entry.
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
  `somework/lockrot-action` run the same lockrot code and write documents to the same schemas. The
  Composer underneath differs: the PHAR, and the image and the Action that run it, bundle Composer
  2.10.x, while the plugin runs on the project's own Composer. On an older one:
    - below 2.4, S9 (security advisories) is not checked, so a priority can be one step lower than
      the PHAR gives the same lock; the report carries a note saying so;
    - below 2.10, only `config.audit.ignore` (and `audit.ignore-severity`) silences an advisory;
      `config.policy.advisories` is not read.
- The PHAR's asset names and the verification path in [phar.md](phar.md) are stable.
- `lockrot-action@v1` follows lockrot 1.x, and its inputs follow semver. lockrot 2.0 means action v2.
- The Docker tag scheme is decided at the RC.
- `self-update` stays within the running major version (`--allow-major` moves to the next one),
  passes over a release whose lowest PHP is above the running one, and passes over a release signed
  with a key the archive does not carry, so it updates through the transition release of a
  signing-key rotation. The rules belong to the archive doing the update, so they hold for archives
  from 0.13.0 on; 0.12 and older take whatever GitHub's latest release is
  ([phar.md](phar.md#keeping-it-updated)).

### PHP platform

- lockrot runs on PHP 7.4+ with Composer 2.2+ today. The floor for 1.x is chosen at the RC.
- *Planned:* within 1.x the floor rises only in a minor release, never in a patch, with a warning on
  stderr one minor release ahead.
- `self-update` already protects an older runtime: it passes over a release whose lowest PHP is above
  the running one.

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

- A change that can alter the verdict or the priority a package gets, or what `--fail-on=unchecked`
  matches — a new S10 reason, a newly supported host — ships in a minor release, never in a patch,
  and is listed in the changelog under **Verdict changes**.
- The only patch exception is a curated-data fix that moves a package to `finished` or `ok`.
- A new signal that decides verdicts ships for one minor release as evidence only. It appears in the
  report and decides nothing; it starts deciding in the next minor.

This section is project practice from 0.13 on, ahead of the rest of the page; it becomes binding with
it at 1.0.0-RC1.

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

- **JSON out.** The plugin, the PHAR, the Docker image and the Action write report-1 and explain-1
  from the same code; [Distribution](#distribution) says what the Composer underneath changes. Dashboards, fleet summaries, other formats and organisation policy (a `jq -e` gate)
  are programs over those documents.
- **Composer configuration in.** lockrot finds repository hosts through Composer's own settings —
  today `gitlab-domains` — and has no host keys of its own.
- **A pull request for code.** New signals, S10 reasons, hosts and fields go into lockrot itself, in
  minor releases, following the additive rule above and [Verdict changes](#verdict-changes).

### Names reserved for extensions

These names are reserved so that an extension mechanism can arrive later without breaking anyone:

- `S<n>` belongs to lockrot. A retired signal keeps its number, and no number is reused.
- Anything that does not come from lockrot — a signal, a format — is named `<vendor>:<name>`. The
  vendor and the name are each lower-case letters, digits, `_`, `.` and `-`, and start with a letter
  or a digit: `acme:licence` fits, while `Acme:Licence` and `acme:lint:licence` do not, and the
  published schemas reject them. No name lockrot ships contains a colon.
- lockrot will never give a meaning of its own to:
    - `extensions` at the top level of `extra.lockrot`;
    - any key that starts with `x-`, at the top level of `extra.lockrot` or inside an `ignore` entry;
    - any environment variable that starts with `LOCKROT_X_`.
- The PHP namespace `Lockrot\Extension\` is reserved. Nothing in it exists, and reserving it
  promises nothing about what will.

## Deprecation

- Nothing in the contract is removed within 1.x.
- An option, environment variable, configuration key, format name or Action input can be deprecated
  in a minor release. From then on it:
    - keeps working unchanged until the next major version;
    - prints one line to stderr when used;
    - is listed under `Deprecated` in the changelog and in the table below;
    - is removed only in the next major version, and no sooner than six months after it was
      deprecated.
- Exit codes and format names never take on a new meaning.
- A JSON field is never deprecated on its own. It keeps being written, the schema marks it
  `x-deprecated: true`, and it disappears only with report-2. `x-deprecated` and `x-known-values`
  (the values an open set holds today, see [Open sets](#open-sets)) are the two annotations lockrot's
  schemas carry; a draft-04 validator ignores both.
- A signal is retired rather than removed.
- An Experimental surface can change in any minor release, with a line in the changelog.

| Deprecated | Experimental |
|---|---|
| nothing | nothing |

## What lockrot does not do

- lockrot writes the files you name — reports with `--output`, the baseline with
  `--generate-baseline` — each through a temporary file beside it that is renamed over it, so it
  needs write access to that directory; its activity cache under Composer's cache directory; and,
  with `self-update`, the PHAR. It never writes `composer.json` or `composer.lock`. Composer keeps
  the repository metadata it fetched in its own cache, as it does for any command.
- It opens no pull request or merge request, and it changes no code.
- It has no hosted service, no account, no telemetry and no usage metering. It talks to:
    - the Composer repositories your project configures, through Composer, for package metadata
      and security advisories;
    - the GitHub, GitLab and Bitbucket APIs, for repository activity: `api.github.com`, gitlab.com
      and every host in Composer's `gitlab-domains`, and `api.bitbucket.org` (plus `bitbucket.org`,
      to exchange a Composer `bitbucket-oauth` consumer for a token);
    - for `self-update` only, GitHub's release API and the release's asset downloads, from
      `github.com` and the download host it redirects them to.

    `--offline` talks to none of them. The tokens it reads from the environment are the
    `LOCKROT_GITHUB_TOKEN`, `GITHUB_TOKEN`, `LOCKROT_GITLAB_TOKEN` and `GITLAB_TOKEN` variables
    [configuration.md](configuration.md#environment-overrides) lists; beside them it uses the
    credentials Composer already holds, and it sends each one only to its own host.

- Anything lockrot may serve later, such as a Composer policy source, runs where you run it.

## Related

- [schema.md](schema.md) — the schemas and the rule behind their numbers
- [verdicts.md](verdicts.md) — what each verdict, priority and signal means
- [ci.md](ci.md) — exit codes and the output formats
- [configuration.md](configuration.md) — `extra.lockrot`, the environment and the CLI
- [phar.md](phar.md) — the PHAR, its verification and `self-update`
- [changelog.md](changelog.md) — what changed, release by release
