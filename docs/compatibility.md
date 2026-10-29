---
title: Compatibility — what lockrot 1.0 freezes
description: Draft of the lockrot 1.0 promise — which fields, sets, exit codes and options stay stable, how a release can change verdicts, reserved names and deprecation.
---

# Compatibility

!!! warning "Draft until 1.0.0-RC1"
    lockrot is 0.x, so a minor release can change anything on this page. The
    [changelog](changelog.md) says what changed. The page becomes binding with 1.0.0-RC1. From
    0.13.0, lockrot follows [how a release can change a verdict](#verdict-changes) as project
    practice.

    - **Decided at 1.0.0-RC1:** the [default thresholds](#configuration), the
      [Docker tag scheme](#distribution) and the [PHP and Composer floor](#php-platform) for 1.x.
    - *Planned:* [`--schema=1` in 2.x](#documents), a
      [configuration file named by `extends`](#configuration) and the
      [floor-rise warning](#php-platform).

Key your integration on the Contract column. The Not contract column can change in any release,
patches included. Flags and verdicts change only as [Verdict changes](#verdict-changes) allows.

| Surface | Contract for all of 1.x | Not contract |
|---|---|---|
| [Command line](#command-line) | The commands, their options, the `--fail-on` values and the exit codes | The wording of its messages |
| [Configuration](#configuration) | `extra.lockrot` keys and the environment variables | The [testing hooks](configuration.md#testing-hooks) |
| [Machine output](#documents) | `json`, `sarif`, `gitlab` and `github`, the baseline file, and the `report` embedded in `html` | `table`, `markdown`, the look of `html`, the `--explain` text and the install-time summary |
| [Inside documents](#documents) | Ids, codes, keys and data fields | Every human-readable sentence: summaries, the score line, the move, the gate line, notes, evidence, messages |
| [Closed sets](#closed-sets-and-their-order) | The closed sets and their order | Which flags a package gets, and its score and verdict |
| [Distribution](#distribution) | The PHAR asset names and verification path, lockrot-action's inputs (Semantic Versioning), and `self-update` staying within its major and on a PHP it runs on. The action line that follows 1.x is named at 1.0 | The Docker tag scheme, until 1.0.0-RC1 chooses it |
| [PHP platform](#php-platform) | — | The PHP and Composer floor, until 1.0.0-RC1 chooses the one for 1.x |
| [PHP code](#what-is-not-contract) | — | The classes under `src/` |

CI owners: pin the lockrot version, and read a release's [Verdict changes](#verdict-changes)
before you move the pin.

## What 1.0 freezes

### Documents

- The report, the explanation, the baseline file and the configuration (report-2, explain-2,
  baseline-2 and config-2) only gain fields within 1.x, under the rule in
  [schema.md](schema.md#the-number-and-what-may-change-under-it).

- Their schemas stay published under `https://lockrot.dev/schema/` for all of 1.x. The schema
  bundled with the release you run is authoritative.

- Within 1.x no field becomes required, narrower or removed: a document valid against an earlier
  1.x schema validates against every later one.

- *Planned:* lockrot 2.x keeps writing report-2 behind `--schema=2`.

- Key on ids and data fields: for a run's notes, on `note_details` ([Run notes](#run-notes)),
  never on `notes`.

### Closed sets and their order

Nothing is added to these sets, removed from them or reordered within 1.x:

- Verdicts, worst first: `critical`, `high`, `medium`, `low`, `unknown`, `finished`, `ok`.
- Grades, highest first: `critical`, `high`, `medium`, `low`.
- Flags, in flag order: `abandoned`, `silent`, `pinned`, `left-behind`, `old-promise`, `stale`, `capped`, `vulnerable`.
- The maintenance flags, in flag order: `abandoned`, `silent`, `pinned`, `left-behind`, `old-promise`, `stale`, `capped`.
- The `priority` alias, highest first: `critical`, `high`, `medium`, `low`, `none`.
- Score parts: `run.score_model.parts[].id` and a flag's `part`: `maintenance`, `security`.
- A score term's `part`: `maintenance`, `security`.
- The verdicts at score 0, in `run.score_model.zero_verdicts`: `finished`, `unknown`, `ok`.
- Advisory severities, highest first: `critical`, `high`, `medium`, `unrated`, `low`.
- Signal levels, lowest first: `info`, `warn`, `high`.
- A finding's `reach`: `direct`, `transitive`, `unreached`.
- `security.status`: `vulnerable`, `clear`, `unchecked`.
- `security.move_in`: `next_step`, `also`.
- A finding's standing against the baseline: `known`, `new`, `worsened`.
- The `kind` of a move's fact and of a rerun's `remove`: `flag`, `advisory`.
- A flag's `headline.unit`: `years`, `php`, `php_minor`, `reason`, `advisories`.
- A flag's `headline.source`: `release`, `push`, `branch`.
- A holder's `source`, in `held_by[]` and `run.php_window.target_fix.held_by`: `root`, `package`.
- A holder's `link`, in `held_by[]` and `run.php_window.target_fix.held_by`: `require`, `require-dev`, `conflict`, `replace`, `root-require`, `platform`.
- A move's `composer_moves_because`: `holders`, `crosses_major`, `transitive`, `unreached`.
- A move check's `result`: `resolves`, `refused`, `not_checked`.
- `run.move_check.baseline`: `resolves`, `refused`, `not_checked`.
- `run.move_check.blocking_disabled_by`, in this order: `COMPOSER_NO_BLOCKING`, `COMPOSER_NO_SECURITY_BLOCKING`, `COMPOSER_POLICY`, `COMPOSER_POLICY_ADVISORIES_BLOCK`, `config.policy`, `config.policy.advisories`, `config.policy.advisories.block`, `config.audit.block-insecure`.
- A move check's `form`: `inline`, `with-dependencies`, `with-all-dependencies`.
- A PHP check's `raise_size`: `major`, `minor`, `patch`.
- `allowlist.by`: `builtin`, `project`, `type`.
- `allowlist.reason_by`: `user`, `lockrot`.
- `metadata.status`: `read`, `not_from_composer_repository`, `unavailable`, `not_found`.
- S1's `marked_by`: `repository`, `lock`.
- The `reasons` of an `abandoned` flag's `degree`: `marked`, `archived`.
- `run.fail_on_source`: `option`, `env`, `config`, `default`.
- `run.target_php_source`: `option`, `env`, `config`, `platform`, `runtime`.
- A finding's `gate.basis.limited_by`: `score`, `cap`, `equal`.
- A finding's `activity.status`: `read`, `not_found`, `not_read`.
- The `level` of an `age` reading: `warn`, `high`.
- A finding's `named_in`: `require`, `require-dev`.
- A feed's `answer`, in `security.feeds[]`: `answered`, `failed`, `not_asked`.
- An advisory repository's `outcome`, in `run.advisory_lookup.repositories[]`: `answered`, `failed`, `not_asked`, `no_feed`.
- `run.advisory_lookup.scope`: `all`, `composer-repositories`.
- `run.advisory_lookup.scope_source`: `config`, `default`.
- The `scope` of an `unchecked_accepted[]` entry's `accepted_by`: `host`, `repository`, `package`.
- The `link` of a PHP window link, in `run.php_window`: `require`, `conflict`.
- The `target` of a PHP window link, in `run.php_window`: `php`, `php-64bit`.
- `run.php_window.host_requires`: `php-debug`, `php-ipv6`, `php-zts`.
- S11's `newest_php_source`: `known`, `require_php`, `platform_php`.
- S11's `admits_newest.blocking`: `project`, `target`.
- `run.php_window.target_fix.check`: `resolves`, `refused`, `not_checked`.
- The `link` of a `run.php_window.target_fix.packages[]` entry: `require`, `conflict`.
- The `target` of a `run.php_window.target_fix.packages[]` entry: `php`, `php-64bit`.

The document number `lockrot.schema` is `2` for all of 1.x. A new value in a closed set needs
report-3 and lockrot 2.0. [verdicts.md](verdicts.md#the-nine-verdicts) says what each value means.

- Open, not closed: the fix kind, a score term's and a flag's `role`, the `status` of each score
  part, a flag's baseline `state`, and every other set that [Open sets](schema.md#open-sets) lists.
  A score model can add a value to one of them in a minor release.

- report-2 lists its grades in `run.graded_verdicts` and its flags in `run.flag_ids`.

- `finished` and `ok` share the lowest severity: no threshold and no comparison tells them apart.
  Where lockrot lists verdicts — `counts`, `run.verdicts`, the schema enums, this page —
  `finished` comes before `ok`. In SARIF output, the SARIF rules appear in the order the results
  first use them.

The order decides:

- `--fail-on`: a finding fails the run at or above a grade, at or above a flag in flag order, or
  with an advisory at or above a severity ([ci.md](ci.md)).
- The baseline: a finding is `worsened` when a flag above the recorded ones fires, an advisory is
  new, or a known one is rated higher or loses its fix ([baseline.md](baseline.md)).
- The report's order: verdict, then the security part of the score, then the score, then direct
  before transitive, then package name.
- The SARIF `rank`: a flag result's by the band of its own fact, an advisory result's by its
  severity.

### Open sets

The sets that [Open sets](schema.md#open-sets) lists grow in minor releases. The [closed
sets](#closed-sets-and-their-order) do not. Read a value that you do not know as [Open
sets](schema.md#open-sets) says. A score modifier or a move whose `reason` or `kind` you do not
know still carries its numbers and its `text`. In lockrot's own input an unknown format name is an error:
`--format`, `--output` and `extra.lockrot.format` accept only the names the release knows.

A minor release can split a `libyears_unmeasured` reason into
narrower ones, which moves findings out of the old `libyears.unmeasured` key.

### Run notes

What a run could not see is in the report and the explanation twice: as sentences in `notes`, and
typed in `note_details` ([notes.md](notes.md)).

Frozen for 1.x:

- `note_details` has one entry per `notes` string, at the same index, with the same `text`. Every
  document from 0.13.0 on carries it.
- Each entry's keys (`code`, `text`, `docs_url`, `sets_network_failures`, `data`) and each code's
  `data` keys, as the schemas list them.
- A code's meaning. A new meaning gets a new code, and lockrot never removes or reuses a retired
  code.
- `sets_network_failures`, and the rule that the report's `network_failures` is true exactly when an
  entry's is.
- Every `docs_url` a release has written resolves for all of 1.x.
- The specific reasons in `data`, as the Frozen reasons column lists them.

| Note codes | Frozen reasons | Catch-all |
|---|---|---|
| [`metadata_unavailable`](notes.md#metadata_unavailable), [`monorepo_parent_unavailable`](notes.md#monorepo_parent_unavailable) | `offline`, `install_time_budget`, `no_versions` | `fetch_failed` |
| [`advisories_not_checked`](notes.md#advisories_not_checked) | `offline`, `install_time_budget` | none |
| [`repository_activity_not_checked`](notes.md#repository_activity_not_checked) | `install_time_budget` | none |

Not frozen:

- The wording of `text`.
- Every `message` in `data`: a repository's, a host's or Composer's own words.
- Which page a `docs_url` points at. A page that moves stays behind as a stub that keeps every id.
- Which notes a run writes, and in what order.
- A catch-all reason: a minor release can move cases out of it into reasons of their own.

A new code or reason arrives only in a minor release, and changes no verdict. Under
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
- lockrot writes `package_url` and a finding's `replacement_url`. Do not build them.
  Link one only where it is a string. `replacement_url` is null when lockrot keeps no page for the
  registry that named the replacement, and always null when `replacement` is.

Not frozen:

- Which further registries lockrot names and links.
- Which kind an entry gets after a minor release that learns more: a new kind can take entries out
  of `unknown`.
- Which registries lockrot writes a `replacement_url` for, and whether a registry still keeps the
  page that a URL points at.

A new kind or registry arrives only in a minor release, and changes no verdict or exit
code.

### Finding identity

- lockrot reports one finding per package per lock.
- The baseline key is the package name.
- GitLab Code Quality: one issue per counted flag. `check_name` is `lockrot/<flag>`, and
  `fingerprint` is the sha256 of `lockrot|<package>|<flag>`. A flag that starts to count opens its
  own issue, and a flag that stops resolves its own.
- SARIF: `ruleId` is `lockrot/<flag>` per counted maintenance flag and `lockrot/advisory/<id>` per
  counted advisory. `partialFingerprints` holds `lockrot/package` with the package name, and an
  advisory result adds `lockrot/advisory`.
- Both: a finding with S10 and nothing counted is `lockrot/unchecked`, and under `--all` a score-0
  finding is `lockrot/ok`, `lockrot/finished` or `lockrot/unknown`. The run-level record of
  advisories that were not checked is `lockrot/advisories-unchecked`: in SARIF its
  `partialFingerprints` is `{"lockrot/run": "advisories-unchecked"}`, and in GitLab its
  `fingerprint` is the sha256 of `lockrot|advisories-unchecked`.
- No identity holds the grade, the score or a severity, so a change of grade opens no record.
- GitHub annotations: one per package, on the package's line of the analysed lock. The title,
  `lockrot: <verdict> — <flags>`, is not identity: key a script on the file and line.

### Severity mapping

The `github`, `sarif` and `gitlab` columns of
[How each format marks a finding](ci.md#how-each-format-marks-a-finding), and its rules for SARIF
`rank` and a SARIF rule's default level, are frozen for 1.x.

### Command line

- The commands: `composer lockrot` (alias `composer rot`), and the PHAR's `lockrot` and
  `self-update` (alias `selfupdate`).
- The options in [CLI options](configuration.md#cli-options), and `self-update`'s `--check`,
  `--force`, `--offline` and `--allow-major` ([phar.md](phar.md#keeping-it-updated)).
- Every format name a release has shipped keeps its meaning for all of 1.x, and new names can
  arrive ([Open sets](#open-sets)). The [table](#compatibility) says whose contents are contract.
- The `--fail-on` values.
- Exit codes: the closed set `0`, `1` and `2`, as [ci.md](ci.md#exit-codes) defines them.
  `self-update` gives them [meanings of its own](phar.md#self-update-exit-codes).
    - lockrot's own commands exit `2` on every usage or configuration error.
    - In a check or `--generate-baseline` run, `0` and `1` follow the report's `gate.fails`, with
      its causes in `gate.tripped_by`. A run that then cannot write an `--output` file or the
      baseline exits `2` instead.
    - An exit `1` from Composer or Symfony before lockrot runs is not lockrot's
      ([ci.md](ci.md#exit-1-not-from-lockrot)).
- Messages about lockrot itself (a failure, a warning, a deprecation) go to stderr, never into the
  report on stdout. A report's own notes are part of the report.

### Configuration

- `extra.lockrot` keys are never removed within 1.x.
- The variables in [Environment overrides](configuration.md#environment-overrides) are frozen with
  the keys. The [testing hooks](configuration.md#testing-hooks) are not.
- Precedence, where the first that sets a value wins: CLI options, environment variables,
  `extra.lockrot`, the defaults. The one exception: when Composer already holds a credential
  header for a host (`auth.json`, `COMPOSER_AUTH`), lockrot sends that header instead of its own
  token ([Which credentials](internals.md#which-credentials)).
- A key lockrot does not know, at the top level, inside an `ignore` entry or inside an
  `accept-unchecked` entry, prints one warning on
  stderr and changes nothing else, as [Unknown keys](configuration.md#unknown-keys) describes. The
  [reserved names](#names-reserved-for-extensions) never warn.
- 1.0.0-RC1 chooses the default thresholds, and a later change to one is a
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
- The PHAR, and the image and the Action that run it, bundle a Composer new enough for every check.
  Only the plugin runs on the project's Composer, and it checks less under an older one
  ([`advisories_not_checked`](notes.md#advisories_not_checked),
  [which advisory settings it reads](verdicts.md#security-advisories)).
- The PHAR's asset names and the [verification path](phar.md#verifying-the-download) are stable.
- lockrot-action's inputs follow Semantic Versioning. Before 1.0, lockrot promises nothing about
  the action's major lines. From 1.0, this page names the action line that follows lockrot 1.x.
- Unless you give `--allow-major`, `self-update` stays within the running major version. It skips a
  release that needs a newer PHP than the running one. It also skips a release signed with a key
  that the archive does not carry ([which release it installs](phar.md#which-release-it-installs)).
- All of 0.x is one major line for `self-update`. So `self-update` from 0.13 installs 0.14 at once,
  and every later 0.x minor too. There is no guard ([phar.md](phar.md#keeping-it-updated)).
- Composer's abandoned ignore list is `config.policy.abandoned.ignore`, with the legacy
  `config.audit.ignore-abandoned` as its fallback ([Abandoned](verdicts.md#abandoned)).

### PHP platform

- The PHP and Composer floors are in the [install requirements](index.md#install). 1.0.0-RC1 chooses
  the floor for 1.x.
- CI tests the plugin on the Composer 2.10 and 2.11 minors. The move check gives the answers of
  the Composer that runs lockrot, and these answers can differ between minors
  ([The move check](verdicts.md#the-move-check)).
- *Planned:* within 1.x the floor rises only in a minor release, never in a patch, with a warning
  on stderr one minor release ahead.

## What is not contract

- **Human-readable output:** the formats in the [table](#compatibility)'s Not contract column.
  The `html` page embeds the report-2 document under its `report` key, and that document is
  contract. The rest of the page's payload is internal to lockrot and its renderer.
- **Which flags a package gets, and its score and verdict:** thresholds and their defaults,
  heuristics, the [score model](verdicts.md#score), the curated package data lockrot ships, the
  repository-host clients, and S10's reasons. These change under
  [Verdict changes](#verdict-changes).
- **The exposure cap:** the value of `exposure_rule.max_fan_in`, and with it which graded
  transitive packages count in `exposure` and S7 and which in `unattributed`
  ([verdicts.md](verdicts.md#transitive-exposure)). The fields' shape is contract. The number is
  not. The report states the value it used, and a change gets a changelog line and is not a
  [verdict change](#verdict-changes).
- **The PHP classes under `src/`:** internal, and can change in any release
  ([CONTRIBUTING.md](https://github.com/somework/lockrot/blob/main/CONTRIBUTING.md#backward-compatibility)).

## Verdict changes

Semantic Versioning covers shapes and names, not which verdict a package gets. A release changes
verdicts only under these rules:

- A change that can alter the flags, the score or the verdict a package gets, or what
  `--fail-on=unchecked` matches (a new S10 reason, support for another host), ships in a minor
  release, never in a patch. A weight, band, sort or score rule change is such a change, and gets a
  new score model id. The changelog lists it under **Verdict changes**.
- The one patch exception is a curated-data fix that moves a package to `finished` or `ok`.
- A new signal that decides verdicts ships for one minor release as evidence only: it appears in
  the report and decides nothing until the next minor release.
- Two exceptions, once: in 0.14.0 the `vulnerable` and `capped` flags decide verdicts at once,
  because 0.14 changes every verdict anyway ([0.14.0's Verdict changes](changelog.md)). A baseline
  entry that records any maintenance flag covers `capped` ([Reading a
  baseline](baseline.md#reading-a-baseline)). The rule above holds for every later signal.

A committed baseline does not make an upgrade silent. A package that a release starts to grade is
`new`. A package whose facts worsen (a higher flag, a new advisory, a higher rating or a lost fix)
is `worsened`. A `new` finding fails the run when it reaches `--fail-on`. A `worsened` one fails
when its [gate score](baseline.md#the-gate-score) reaches it. An unchanged lock can cross a
threshold too, as its packages' releases age ([baseline.md](baseline.md)).

## Extending lockrot

1.0 ships no plugin API. Use one of these routes instead:

- **JSON out.** Every [distribution](#distribution) writes report-2 and explain-2 from the same
  code. Dashboards, fleet summaries, other formats and organisation policy (a `jq -e` gate) are
  programs over those documents.
- **Composer configuration in.** lockrot reads extra GitLab hosts from Composer's `gitlab-domains`
  and has no host keys of its own ([hosts](internals.md#repository-hosts-and-credentials)).
- **A pull request for code.** New signals, S10 reasons, hosts and fields go into lockrot itself,
  in minor releases, under the [additive rule](#documents) and [Verdict changes](#verdict-changes).

### Names reserved for extensions

- `S<n>` belongs to lockrot. A retired signal keeps its number, and no number is reused.
- A signal, run note code, origin kind or format that does not come from lockrot is named
  `<vendor>:<name>`. The vendor and the name each consist of lower-case letters, digits, `_`, `.`
  and `-`, and each starts with a letter or a digit. `acme:licence` fits. `Acme:Licence` and
  `acme:lint:licence` do not fit, and the published schemas reject them. No name that lockrot ships
  contains a colon.
- lockrot never gives a meaning of its own to:
    - `extensions` at the top level of `extra.lockrot`
    - any key that starts with `x-`, at the top level of `extra.lockrot`, inside an `ignore`
      entry or inside an `accept-unchecked` entry
    - any environment variable that starts with `LOCKROT_X_`
- The PHP namespace `Lockrot\Extension\` is reserved and declares nothing.

## Deprecation

- Nothing in the contract is removed within 1.x.
- An option, environment variable, configuration key, format name or Action input can be
  deprecated in a minor release. From then on it:
    - keeps working unchanged until the next major version
    - prints one line to stderr when you use it
    - appears under `Deprecated` in the changelog and in this section's register
    - is removed only in the next major version, and no sooner than six months after it was
      deprecated
- Exit codes and format names never get a new meaning.
- A JSON field is never deprecated on its own. lockrot continues to write it, the schema marks it
  `x-deprecated: true`, and it disappears only with report-3. A draft-04 validator ignores
  `x-deprecated`, as it ignores `x-known-values`.
- A signal is retired, never removed.
- A surface marked *Experimental* on its page can change in any minor release, with a line in the
  changelog.

The register lists every deprecated or *Experimental* surface:

| Surface | Status | Since | Replacement | Removed no earlier than |
|---|---|---|---|---|
| report-2's `priority` on a finding, and the root `priorities` | Deprecated | 0.14.0 | `verdict`, and the grades in `counts` | report-3 |

## What lockrot does not do

lockrot never writes `composer.json` or `composer.lock`, opens no pull or merge request, and has no
hosted service or telemetry.
[SECURITY.md](https://github.com/somework/lockrot/blob/main/SECURITY.md#what-lockrot-does-and-does-not-do)
lists every file that lockrot writes, every host it contacts and where each credential goes.

## Related

- [schema.md](schema.md) — the schemas, their number, and how a validator reads the open sets
- [verdicts.md](verdicts.md) — what each flag, verdict and signal means
- [ci.md](ci.md#exit-codes) — what each exit code means, and each output format
- [configuration.md](configuration.md) — every key, variable and option this page freezes
- [notes.md](notes.md) — every run note code and what to do about it
- [phar.md](phar.md) — PHAR verification, and the `self-update` rules
- [changelog.md](changelog.md) — each release's Verdict changes and deprecations
