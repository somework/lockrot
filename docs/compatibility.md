---
title: Compatibility — what lockrot 1.0 freezes
description: Draft of the lockrot 1.0 promise — which fields, sets, exit codes and options stay stable, how a release may change verdicts, reserved names and deprecation.
---

# Compatibility

!!! warning "Draft until 1.0.0-RC1"
    lockrot is 0.x, so a minor release may change anything on this page; the
    [changelog](changelog.md) says what changed. The page becomes binding with 1.0.0-RC1. From
    0.13.0, lockrot follows [how a release may change a verdict](#verdict-changes) as project
    practice.

    - **Decided at 1.0.0-RC1:** the [default thresholds](#configuration), the
      [Docker tag scheme](#distribution) and the [PHP and Composer floor](#php-platform) for 1.x.
    - *Planned:* [`--schema=1` in 2.x](#documents), a
      [configuration file named by `extends`](#configuration) and the
      [floor-rise warning](#php-platform).

Key your integration on the Contract column. The Not contract column can change in any release,
patches included; verdicts and priorities change only as [Verdict changes](#verdict-changes) allows.

| Surface | Contract for all of 1.x | Not contract |
|---|---|---|
| [Command line](#command-line) | The commands, their options, the `--fail-on` values and the exit codes | The wording of its messages |
| [Configuration](#configuration) | `extra.lockrot` keys and the environment variables | The [testing hooks](configuration.md#testing-hooks) |
| [Machine output](#documents) | `json`, `sarif`, `gitlab` and `github`, the baseline file, and the `report` embedded in `html` | `table`, `markdown`, the look of `html`, the `--explain` text and the install-time summary |
| [Inside documents](#documents) | Ids, codes, keys and data fields | Every human-readable sentence: summaries, notes, evidence, messages |
| [Closed sets](#closed-sets-and-their-order) | The closed sets and their order | Which verdict and priority a package gets |
| [Distribution](#distribution) | The PHAR asset names and verification path, `lockrot-action@v1` and its inputs, `self-update` staying within its major and on a PHP it runs on | The Docker tag scheme, until 1.0.0-RC1 chooses it |
| [PHP platform](#php-platform) | — | The PHP and Composer floor, until 1.0.0-RC1 chooses the one for 1.x |
| [PHP code](#what-is-not-contract) | — | The classes under `src/` |

CI owners: pin the lockrot version, and read a release's [Verdict changes](#verdict-changes)
before you move the pin.

## What 1.0 freezes

### Documents

- The report, the explanation, the baseline file and the configuration (report-1, explain-1,
  baseline-1 and config-1) only gain fields within 1.x, under the rule in
  [schema.md](schema.md#the-number-and-what-may-change-under-it).

- Their schemas stay published under `https://lockrot.dev/schema/` for all of 1.x. The schema
  bundled with the release you run is authoritative.

- Within 1.x no field becomes required, narrower or removed: a document valid against an earlier
  1.x schema validates against every later one.

- *Planned:* lockrot 2.x keeps writing report-1 behind `--schema=1`.

- Key on ids and data fields: for a run's notes, on `note_details` ([Run notes](#run-notes)),
  never on `notes`.

### Closed sets and their order

Nothing is added to these sets, removed from them or reordered within 1.x:

- Verdicts, most severe first: `abandoned`, `silent`, `pinned`, `left-behind`, `old-promise`, `stale`, `unknown`, `finished`, `ok`.
- Priorities, highest first: `critical`, `high`, `medium`, `low`, `none`.
- Signal levels, lowest first: `info`, `warn`, `high`.
- A finding's standing against the baseline: `known`, `new`, `worsened`.
- A `priority_basis` step's `from` and `to`: the priority order without `none`.

The document number `lockrot.schema` is `1` for all of 1.x. A new verdict, priority, level or
standing needs report-2 and lockrot 2.0. [verdicts.md](verdicts.md#the-nine-verdicts) says what each
value means.

- The flagged verdicts are `abandoned` to `stale`: the ones `run.flagged_verdicts` lists, a baseline
  entry holds and `--fail-on` accepts.

- `finished` and `ok` share the lowest severity: no threshold and no comparison tells them apart.
  Where lockrot lists verdicts — `counts`, `run.flagged_verdicts`, the schema enums, this page —
  `finished` comes before `ok`; the SARIF rules appear in the order the results first use them.

The order decides:

- `--fail-on`: a finding fails the run at or above the threshold.
- The baseline: a finding is `worsened` when its verdict is more severe than the one the baseline
  accepted.
- The report's order: priority, then verdict, then direct before transitive, then package name.
- The SARIF `rank`, which follows the priority.

### Open sets

These sets grow in minor releases:

- signal ids, S10's `blocks` included;
- S10's `check` and `reason`;
- S8's `floor_source`, and the explanation's `php_blocked_by`, which holds the same values;
- the explanation's `misses_target_php` and `misses_project_php`;
- S6's `reason`;
- a finding's `libyears_unmeasured`, and the keys of the report's `libyears.unmeasured`;
- a `priority_basis` step's `reason`, and a `no_fix_expected` item's `reason`;
- `run.mode` and `run.fail_on_kind`;
- `gate.tripped_by`, and a finding's `gate.exempt_by`;
- a run note's `code`, and the `forge_id` and `reason` in its `data`;
- a finding's `origin.kind` and `origin.registry`;
- format names, the configuration's `format` among them.

The schemas describe signal ids, S10's `check` and `reason`, S6's `reason`, S8's `floor_source`,
the explanation's `php_blocked_by`, `misses_target_php` and `misses_project_php`, a finding's
`libyears_unmeasured`, a `priority_basis` step's `reason`, a `no_fix_expected` item's `reason`,
`run.mode`, `run.fail_on_kind`, `gate.tripped_by`, a finding's `gate.exempt_by`, a run note's
`code`, and the `forge_id` and `reason` in its `data`, a finding's `origin.kind` and
`origin.registry`, and the configuration's `format` as open strings.

Repository hosts are an open set only in a run note's `forge_id`, and Composer registries only in a
finding's `origin.registry`; S3 and S4's `host` and the explanation's `activity.forge` are plain
strings.

[schema.md](schema.md#open-sets) says how to read a value you do not know. A `priority_basis` step
whose `reason` you do not know still shows its direction: compare `from` and `to`. In lockrot's own
input an unknown format name is an error: `--format`, `--output` and
`extra.lockrot.format` accept only the names the release knows.

A minor release may split a `libyears_unmeasured` reason, `no_stable_release_date` included, into
narrower ones, which moves findings out of the old `libyears.unmeasured` key
([schema.md](schema.md#open-sets)).

### Run notes

What a run could not see is in the report and the explanation twice: as sentences in `notes`, and
typed in `note_details` ([notes.md](notes.md)).

Frozen for 1.x:

- `note_details` has one entry per `notes` string, at the same index, with the same `text`. Every
  document from 0.13.0 on carries it.
- Each entry's keys (`code`, `text`, `docs_url`, `sets_network_failures`, `data`) and each code's
  `data` keys, as the schemas list them.
- A code's meaning. A new meaning gets a new code; a retired code is never removed or reused.
- `sets_network_failures`, and the rule that the report's `network_failures` is true exactly when an
  entry's is.
- Every `docs_url` a release has written resolves for all of 1.x.
- The specific reasons in `data`, in the table below.

| Note codes | Frozen reasons | Catch-all |
|---|---|---|
| [`metadata_unavailable`](notes.md#metadata_unavailable), [`monorepo_parent_unavailable`](notes.md#monorepo_parent_unavailable) | `offline`, `install_time_budget`, `no_versions` | `fetch_failed` |
| [`advisories_not_checked`](notes.md#advisories_not_checked) | `offline`, `composer_too_old`, `install_time_budget` | none |
| [`repository_activity_not_checked`](notes.md#repository_activity_not_checked) | `install_time_budget` | none |

Not frozen:

- The wording of `text`.
- Every `message` in `data`: a repository's, a host's or Composer's own words.
- Which page a `docs_url` points at. A page that moves stays behind as a stub that keeps every id.
- Which notes a run writes, and in what order.
- A catch-all reason: a minor release may move cases out of it into reasons of their own.

A new code or reason arrives only in a minor release, and changes no verdict or priority. Under
`--strict-network`, a new code whose `sets_network_failures` is true can make a run exit `1`
([what trips it](ci.md#exit-codes)).

### Package origins

A finding's `origin` says where its lock entry came from
([schema.md](schema.md#where-a-package-came-from)). Frozen for 1.x:

- Its keys `kind`, `registry`, `package_url` and `local`, on every finding from 0.13.0 on, and the
  finding's boolean `from_composer_repository`.
- The rules [schema.md](schema.md#where-a-package-came-from) gives for `kind`, `registry`, `local`
  and `from_composer_repository`. A new case gets a new kind, never a new meaning for an old one.
- A minor release can add a registry lockrot names or links, never withdraw one.
- `package_url` and a finding's `replacement_url` are written by lockrot, never built by a reader:
  link one only where it is a string. `replacement_url` is null when lockrot keeps no page for the
  registry that named the replacement, and always null when `replacement` is.

Not frozen:

- Which further registries lockrot names and links.
- Which kind an entry gets after a minor release that learns more: a new kind may take entries out
  of `unknown`.
- Which registries a `replacement_url` is written for, and whether a registry still keeps the page a
  URL points at.

A new kind or registry arrives only in a minor release, and changes no verdict, priority or exit
code.

### Finding identity

- lockrot reports one finding per package per lock.
- The baseline key is the package name.
- GitLab Code Quality: `check_name` is `lockrot/<verdict>`, and `fingerprint` is the sha256 of
  `lockrot|<package>|<verdict>`. The verdict is part of the fingerprint and not of the baseline
  key, so a finding whose verdict changes gets a new fingerprint.
- SARIF: `ruleId` is `lockrot/<verdict>`, and `partialFingerprints` holds `lockrot/package` with the
  package name.
- GitHub annotations: the title is `lockrot: <verdict> (<priority>)`, on the package's line of the
  analysed lock.

### Severity mapping

The `github`, `sarif` and `gitlab` columns of
[How each format marks a finding](ci.md#how-each-format-marks-a-finding), and its rules for SARIF
`rank` and a SARIF rule's default level, are frozen for 1.x.

### Command line

- The commands: `composer lockrot` (alias `composer rot`), and the PHAR's `lockrot` and
  `self-update` (alias `selfupdate`).
- The options in [CLI options](configuration.md#cli-options), and `self-update`'s `--check`,
  `--force`, `--offline` and `--allow-major` ([phar.md](phar.md#keeping-it-updated)).
- Every format name a release has shipped keeps its meaning for all of 1.x, and new names may
  arrive ([Open sets](#open-sets)); the [table](#compatibility) says whose contents are contract.
- The `--fail-on` values.
- Exit codes: the closed set `0`, `1` and `2`, as [ci.md](ci.md#exit-codes) defines them.
  `self-update` gives them [meanings of its own](phar.md#self-update-exit-codes).
    - lockrot's own commands exit `2` on every usage or configuration error.
    - In a check or `--generate-baseline` run, `0` and `1` follow the report's `gate.fails`, with
      its causes in `gate.tripped_by`; a run that then cannot write an `--output` file or the
      baseline exits `2` instead.
    - An exit `1` from Composer or Symfony before lockrot runs is not lockrot's
      ([ci.md](ci.md#exit-1-not-from-lockrot)).
- Messages about lockrot itself (a failure, a warning, a deprecation) go to stderr, never into the
  report on stdout. A report's own notes are part of the report.

### Configuration

- `extra.lockrot` keys are never removed within 1.x.
- The variables in [Environment overrides](configuration.md#environment-overrides) are frozen with
  the keys; the [testing hooks](configuration.md#testing-hooks) are not.
- Precedence, where the first that sets a value wins: CLI options, environment variables,
  `extra.lockrot`, the defaults. The one exception: when Composer already holds a credential
  header for a host (`auth.json`, `COMPOSER_AUTH`), lockrot sends that header instead of its own
  token ([Which credentials](internals.md#which-credentials)).
- A key lockrot does not know, at the top level or inside an `ignore` entry, prints one warning on
  stderr and changes nothing else, as [Unknown keys](configuration.md#unknown-keys) describes. The
  [reserved names](#names-reserved-for-extensions) never warn.
- The default thresholds are chosen at 1.0.0-RC1, and a later change to one is a
  [Verdict change](#verdict-changes). To hold the numbers fixed, set them in `extra.lockrot`.
- *Planned:* a configuration file named by `extends`, read after `extra.lockrot` and before the
  defaults.

### Blocking at install time {#blocking-at-composer-require}

The `install-time-strict` key and its effect, stopping a `composer require`, `update` or `install`
when a finding reaches `fail-on`, are frozen with the rest of the [configuration](#configuration)
([install-time.md](install-time.md#install-time-strict)).

### Distribution

- The Composer plugin, the signed PHAR, the Docker image `ghcr.io/somework/lockrot` and
  `somework/lockrot-action` run the same lockrot code and write documents to the same schemas.
- The PHAR, and the image and the Action that run it, bundle a Composer new enough for every check,
  so only the plugin, which runs on the project's Composer, checks less under an older one
  ([`advisories_not_checked`](notes.md#advisories_not_checked),
  [which advisory settings it reads](verdicts.md#security-advisories)).
- The PHAR's asset names and the [verification path](phar.md#verifying-the-download) are stable.
- `lockrot-action@v1` follows lockrot 1.x, and its inputs follow Semantic Versioning. lockrot 2.0
  means action v2.
- `self-update` stays within the running major version unless `--allow-major` is given, and passes
  over a release that needs a newer PHP than the running one or is signed with a key the archive
  does not carry ([which release it installs](phar.md#which-release-it-installs)).

### PHP platform

- The PHP and Composer floors are in the [install requirements](index.md#install); the floor for
  1.x is chosen at 1.0.0-RC1.
- *Planned:* within 1.x the floor rises only in a minor release, never in a patch, with a warning
  on stderr one minor release ahead.

## What is not contract

- **Human-readable output:** the formats in the [table](#compatibility)'s Not contract column.
  The `html` page embeds the report-1 document under its `report` key, and that document is
  contract; the rest of the page's payload is internal to lockrot and its renderer.
- **Which verdict and priority a package gets:** thresholds and their defaults, heuristics, the
  [priority rules](verdicts.md#priority), the curated package data lockrot ships, the
  repository-host clients, and S10's reasons. These change under
  [Verdict changes](#verdict-changes).
- **The exposure cap:** the value of `exposure_rule.max_fan_in`, and with it which flagged
  transitive packages count in `exposure` and S7 and which in `unattributed`
  ([verdicts.md](verdicts.md#transitive-exposure)). The fields' shape is contract; the number is
  not. The report states the value it used, and a change gets a changelog line and is not a
  [verdict change](#verdict-changes).
- **The PHP classes under `src/`:** internal, and may change in any release
  ([CONTRIBUTING.md](https://github.com/somework/lockrot/blob/main/CONTRIBUTING.md#backward-compatibility)).

## Verdict changes

Semantic Versioning covers shapes and names, not which verdict a package gets. A release changes
verdicts only under these rules:

- A change that can alter the verdict or the priority a package gets, or what
  `--fail-on=unchecked` matches (a new S10 reason, a newly supported host), ships in a minor
  release, never in a patch. The changelog lists it under **Verdict changes**.
- The one patch exception is a curated-data fix that moves a package to `finished` or `ok`.
- A new signal that decides verdicts ships for one minor release as evidence only: it appears in
  the report and decides nothing until the next minor release.

A committed baseline does not make an upgrade silent: a package a release starts flagging is `new`,
one whose verdict worsens is `worsened`, and either one fails the run when it reaches `--fail-on`.
An unchanged lock can cross a threshold too, as its packages' releases age
([baseline.md](baseline.md)).

## Extending lockrot

1.0 ships no plugin API. Use one of these routes instead:

- **JSON out.** Every [distribution](#distribution) writes report-1 and explain-1 from the same
  code. Dashboards, fleet summaries, other formats and organisation policy (a `jq -e` gate) are
  programs over those documents.
- **Composer configuration in.** lockrot reads extra GitLab hosts from Composer's `gitlab-domains`
  and has no host keys of its own ([hosts](internals.md#repository-hosts-and-credentials)).
- **A pull request for code.** New signals, S10 reasons, hosts and fields go into lockrot itself,
  in minor releases, under the [additive rule](#documents) and [Verdict changes](#verdict-changes).

### Names reserved for extensions

- `S<n>` belongs to lockrot. A retired signal keeps its number, and no number is reused.
- A signal, run note code, origin kind or format that does not come from lockrot is named
  `<vendor>:<name>`. The vendor and the name are each lower-case letters, digits, `_`, `.` and `-`,
  starting with a letter or a digit. `acme:licence` fits; `Acme:Licence` and `acme:lint:licence` do
  not, and the published schemas reject them. No name lockrot ships contains a colon.
- lockrot never gives a meaning of its own to:
    - `extensions` at the top level of `extra.lockrot`;
    - any key that starts with `x-`, at the top level of `extra.lockrot` or inside an `ignore`
      entry;
    - any environment variable that starts with `LOCKROT_X_`.
- The PHP namespace `Lockrot\Extension\` is reserved and declares nothing.

## Deprecation

- Nothing in the contract is removed within 1.x.
- An option, environment variable, configuration key, format name or Action input can be
  deprecated in a minor release. From then on it:
    - keeps working unchanged until the next major version;
    - prints one line to stderr when used;
    - is listed under `Deprecated` in the changelog and in the register below;
    - is removed only in the next major version, and no sooner than six months after it was
      deprecated.
- Exit codes and format names never take on a new meaning.
- A JSON field is never deprecated on its own. It keeps being written, the schema marks it
  `x-deprecated: true`, and it disappears only with report-2. A draft-04 validator ignores
  `x-deprecated`, as it ignores `x-known-values`.
- A signal is retired, never removed.
- A surface marked *Experimental* on its page can change in any minor release, with a line in the
  changelog.

The register lists every deprecated or *Experimental* surface:

| Surface | Status | Since | Replacement | Removed no earlier than |
|---|---|---|---|---|
| none | — | — | — | — |

## What lockrot does not do

lockrot never writes `composer.json` or `composer.lock`, opens no pull or merge request, and has no
hosted service or telemetry. Every file it writes, every host it contacts and where each credential
goes:
[SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do).

## Related

- [schema.md](schema.md) — the schemas, their number, and how a validator reads the open sets
- [verdicts.md](verdicts.md) — what each verdict, priority and signal means
- [ci.md](ci.md#exit-codes) — what each exit code means, and each output format
- [configuration.md](configuration.md) — every key, variable and option this page freezes
- [notes.md](notes.md) — every run note code and what to do about it
- [phar.md](phar.md) — verifying the PHAR, and the `self-update` rules
- [changelog.md](changelog.md) — each release's Verdict changes and deprecations
