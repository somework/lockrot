---
title: JSON schemas — lockrot report, explanation, baseline and configuration
description: The published JSON Schema of every lockrot document, what can change under a schema number, which value sets grow, how to validate in CI, and the report's fields.
---

# JSON schemas

Validate lockrot's machine-readable documents against these schemas and build on the fields they
type. Under one schema number a document only gains fields, the sets in [Open sets](#open-sets)
only gain values and a field's type only widens.

| Document | Schema | Written by / read from |
|---|---|---|
| The report | [`https://lockrot.dev/schema/report-1.json`](https://lockrot.dev/schema/report-1.json) | `composer lockrot --format=json` |
| The explanation | [`https://lockrot.dev/schema/explain-1.json`](https://lockrot.dev/schema/explain-1.json) | `composer lockrot --explain=vendor/package --format=json` |
| The baseline file | [`https://lockrot.dev/schema/baseline-1.json`](https://lockrot.dev/schema/baseline-1.json) | `--generate-baseline` writes `lockrot-baseline.json`. Every later run reads it |
| The configuration | [`https://lockrot.dev/schema/config-1.json`](https://lockrot.dev/schema/config-1.json) | `extra.lockrot` in `composer.json` |

- **Dialect.** [JSON Schema draft-04](https://json-schema.org/specification-links#draft-4).

- **What lockrot validates.** `composer lockrot` exits `2` on a baseline file or an `extra.lockrot`
  that fails its schema ([exit codes](ci.md#exit-codes)). At install time lockrot skips its
  install-time check and prints one `lockrot: install-time check skipped: …` line
  ([install-time.md](install-time.md#never-fails-the-install)).

- **Copies.** The PHAR and each [release tag](https://github.com/somework/lockrot/tags) ship the
  same files as `resources/lockrot-<name>-<number>.schema.json`. The copy in your PHAR, or at your
  release's tag, describes exactly that release. The URL describes the newest release under the
  same number.

## The documents say which schema they follow

The report, the explanation and the baseline file open with a `$schema` key that names their URL:

```json
{
    "$schema": "https://lockrot.dev/schema/report-1.json",
    "lockrot": {
        "version": "0.13.0",
        "schema": 1
    },
    …
}
```

An editor that reads `$schema` completes and checks these documents as you edit them.

`extra.lockrot` sits inside `composer.json`, which has a schema of its own, so it carries no
`$schema`. Map `config-1.json` to the `extra.lockrot` path in the JSON schema settings of your
editor.

## The number, and what can change under it {#the-number-and-what-may-change-under-it}

The `1` in `report-1.json` is the `lockrot.schema` value the document carries.

- **Fields are only added.** No object sets `additionalProperties: false`, so a field your code
  does not know is not an error.

- **Types can widen.** A field can accept a further type (for example `null`) under the same
  number. A document that uses the wider type fails an older copy. When you upgrade, refresh a
  pinned copy.

- **The number moves** only when a field is removed, renamed, made required or narrowed, or a
  closed set changes. The old file stays published at its old URL.

- **The file at a URL widens in place.** Every document that a lockrot release of the same number
  wrote validates against it.

[compatibility.md](compatibility.md) lists what else 1.0 freezes.

## Open sets

Objects are open, and so are these sets of values, which grow in minor releases:

- signal ids: a signal's `id` and S10's `blocks`
- S10's `check` and `reason`
- S6's `reason`, and S8's `floor_source`
- a finding's `libyears_unmeasured`, and the keys of the report's `libyears.unmeasured`
- a `priority_basis` step's `reason`, and a `no_fix_expected` item's `reason`
- `run.mode`, `run.fail_on_kind` and `gate.tripped_by`, and a finding's `gate.exempt_by`
- a finding's `origin.kind` and `origin.registry`
- a run note's `code`, and the `forge_id` and `reason` in its `data`
- the explanation's `php_blocked_by`, `misses_target_php` and `misses_project_php`
- the configuration's `format`, and format names wherever lockrot writes one.

Repository hosts are an open set only in a run note's `forge_id`, and Composer registries only in a
finding's `origin.registry`. S3 and S4's `host` and the explanation's `activity.forge` are plain
strings.

How the schemas write an open set:

- It is a string with a `pattern`, plus `x-known-values`, the values that this release writes.
  Every known value matches the `pattern`, and the list only grows under one number.

- A draft-04 validator ignores `x-known-values`, so your copy accepts a value that a later release
  adds.

- The pattern for signal ids, note codes, origin kinds and formats also admits `<vendor>:<name>`,
  the form [reserved](compatibility.md#names-reserved-for-extensions) for names that do not come
  from lockrot.

- A signal whose id the schema does not list validates with any object as its `data`. A listed id
  keeps its `data` typed: `S2` with S4's data fails, and so does `S2` with no `data`. A run note's
  `data` is typed per `code` the same way.

- The keys of the report's `libyears.unmeasured` grow with `libyears_unmeasured`. A key that a
  later release adds is typed as a count and never joins `required`. A later release can also split
  a reason into narrower keys, which moves findings out of the old one.

How to read one:

- Read a value that you do not know as "other". Show it as written, and do not fail on it.

- To hold a document to the values you know, read `x-known-values` as an `enum`. lockrot reads it
  as an `enum` when it validates `extra.lockrot`, so a mistyped `format` is a configuration error.

The [closed sets](compatibility.md#closed-sets-and-their-order) stay closed. A new value in a
closed set needs a new schema number.

!!! note "Older releases"
    A copy of a schema taken before 0.13.0 spells these sets as enums, and rejects a value that a
    later release adds until you refresh it.

## What the report schema types

**Since** is the release that added a field. The schema marks such a field optional so that
earlier reports validate, and lockrot writes it in every report from that release on. A field
without a **Since** is in every report that has its parent. `report-1.json` holds every nested key.

### The document

| Field | Type | Since | Holds |
|---|---|---|---|
| `$schema` | string | | The URL of `report-1.json` |
| `lockrot` | object | | `version`, the lockrot release that wrote the document, and `schema`, the schema number |
| `generated_at` | date-time | | When lockrot wrote the report |
| `run` | object or null | 0.10.0 | What the run was told to do: see [What the run was told](#what-the-run-was-told) |
| `activity_cache_oldest_at` | date-time or null | | Fetch time of the oldest repository-activity answer served from lockrot's cache. Null when lockrot fetched every answer in this run |
| `packages_checked` | integer | | Packages analysed |
| `include_dev` | boolean | | Whether lockrot analysed `packages-dev` |
| `not_from_composer_repository` | integer | | Findings whose `from_composer_repository` is false |
| `network_failures` | boolean | | True exactly when a `note_details` entry has `sets_network_failures: true`. `--strict-network` fails on it ([ci.md](ci.md#exit-codes)) |
| `counts` | object | | Findings per verdict. Every verdict is a key, zero included |
| `abandoned` | object | 0.11.0 | `total`, equal to `counts.abandoned`, and `with_replacement`, the abandoned findings whose `replacement` names a package |
| `priorities` | object | | Findings per priority. Every priority is a key, zero included |
| `exposure` | array | | Direct requirements that pull in flagged packages: see [Transitive exposure](#transitive-exposure) |
| `exposure_rule` | object | 0.13.0 | `max_fan_in`, the fan-in cap this run attributed `exposure` by: see [Transitive exposure](#transitive-exposure) |
| `unattributed` | array | 0.13.0 | Flagged transitive packages above the cap |
| `libyears` | object | 0.11.0 | Totals over the findings' `libyears`: see [Libyears](#libyears) |
| `baseline` | object or null | | `path`, the counts `known`, `new` and `worsened`, and `stale`, the baselined names absent from the lock. Null when the run read no baseline |
| `gate` | object or null | 0.13.0 | Why the run exits as it does: see [The gate](#the-gate) |
| `notes` | array of strings | | What the run could not see, as sentences: text, not contract |
| `note_details` | array | 0.13.0 | The same notes, typed: see [Run notes](#run-notes) |
| `findings` | array | | One per analysed package, by priority, then severity order, direct before transitive, then name |

### Each finding

| Field | Type | Since | Holds |
|---|---|---|---|
| `package` | string | | The package name, `vendor/name` |
| `version` | string | | The installed version, as the lock records it |
| `verdict` | enum | | A [verdict](verdicts.md#the-nine-verdicts) |
| `priority` | enum | | A [priority](verdicts.md#priority) |
| `priority_basis` | object | 0.13.0 | How `priority` was reached: see [How a priority was reached](#how-a-priority-was-reached) |
| `direct` | boolean | | Whether the project requires the package directly |
| `dev` | boolean | | Whether the lock lists it under `packages-dev` |
| `signals` | array | | `{id, level, summary, data}` per [signal](verdicts.md#the-signals). `data` is typed per id, `summary` is text |
| `chain` | array | | Package names from a direct requirement down to this one. One element when it is direct, empty when no direct requirement reaches it |
| `direct_dependents` | array | | The run's direct requirements that reach the package, sorted by name. A direct package lists itself |
| `evidence` | string | | The sentence behind the verdict: text, not contract |
| `allowlist_reason` | string or null | | The reason the [allowlist](configuration.md#the-allowlist) gives. Null when the package is not allowlisted |
| `note` | string or null | | Why lockrot has no repository metadata for the package, as a sentence. Null when it has |
| `data_date` | date-time or null | | When lockrot read the repository data behind the verdict: the later of the metadata and the repository activity. Null when lockrot had neither |
| `from_composer_repository` | boolean | 0.13.0 | Whether the lock entry carries a Composer `notification-url`: see [Where a package came from](#where-a-package-came-from) |
| `origin` | object | 0.13.0 | Where the lock entry came from: see [Where a package came from](#where-a-package-came-from) |
| `replacement` | string or null | 0.11.0 | On an abandoned finding, the package its repository names as the replacement, when that is a package name other than its own. Null otherwise ([Abandoned](verdicts.md#abandoned-and-where-to)) |
| `replacement_url` | string or null | 0.13.0 | The replacement's page: see [Where a package came from](#where-a-package-came-from) |
| `libyears` | number or null | 0.11.0 | Years behind the newest stable release, at least 0, two decimals. A lower bound when the newest tag is undated ([Libyears](verdicts.md#libyears)). Null when unmeasured |
| `libyears_unmeasured` | string or null | 0.13.0 | Null when `libyears` is a number, 0 included. Otherwise the `libyears.unmeasured` key the finding counts under. An open set |
| `no_fix_expected` | array or null | 0.13.0 | The advisories lockrot expects no fix for: see [Advisories with no fix expected](#advisories-with-no-fix-expected) |
| `baseline` | object or null | 0.10.0 | `status` (`known`, `new` or `worsened`) and `previous_verdict`, the verdict the baseline accepted or null. Null when the run read no baseline or the finding is not flagged |
| `gate` | object or null | 0.13.0 | Where the finding stands against `run.fail_on`: see [The gate](#the-gate). Null exactly when the root `gate` is |

A finding without `libyears_unmeasured` comes from a report that predates the field. Its absence
never means "measured".

### What the run was told

`run` holds what lockrot decided every verdict against. The command writes it on every run.

| Key | Type | Since | Holds |
|---|---|---|---|
| `project` | string or null | | What the report calls the project: composer.json's `name`, or `extra.lockrot.project` instead. A display name, not an identifier. Null when the manifest has no `name` and `extra.lockrot.project` is unset |
| `root_package` | string or null | 0.13.0 | The `name` of the manifest Composer reads, exactly as written, whatever `extra.lockrot.project` says: the key to match a report to its repository or to join several projects' reports on |
| `target_php` | string or null | | The PHP version the verdicts target, as the run resolved it ([`target-php`](configuration.md#extralockrot-keys)) |
| `project_php` | string or null | 0.13.0 | The manifest's `require.php` exactly as written (`>=8.2`): a constraint, not a version. The second floor that S8 holds a higher branch against ([Within reach](verdicts.md#within-reach)). `--explain` writes the same value |
| `lock_file` | string or null | | The lock's file name, never its path |
| `fail_on` | string or null | | The threshold the run used, from `--fail-on` or its [key](configuration.md#extralockrot-keys) ([choosing one](ci.md)) |
| `fail_on_kind` | string or null | 0.13.0 | Which kind of threshold `fail_on` is: `none`, `verdict`, `priority` or `unchecked`. An open set. Null only where `fail_on` is |
| `strict_network` | boolean | 0.13.0 | Whether `--strict-network` was on |
| `mode` | string | 0.13.0 | `check`, or `generate_baseline` for `--generate-baseline`, which records the findings and judges none. An open set |
| `thresholds` | object or null | | `release-warn-years`, `release-high-years`, `push-warn-years` and `push-high-years` ([keys](configuration.md#extralockrot-keys)) |
| `flagged_verdicts` | array | | The verdicts this run counted as flagged, most severe first |

`root_package` and `project_php` are null when the manifest has no such key, and in a run that read
a lock without its composer.json.

### The gate

The root `gate` is the decision behind the exit code. lockrot takes it over the document's own
findings, by what `run` says. It is null only where `run` is null or has no `fail_on`. The command
always writes both.

| Key | Type | Holds |
|---|---|---|
| `fails` | boolean | True: the run exits `1`. False: it exits `0`. A later write error overrides both with `2` ([exit codes](ci.md#exit-codes)) |
| `tripped_by` | array | Each cause once: `strict_network` (it was on and `network_failures` is true) and `fail_on` (some finding's `gate.fails` is true). Empty exactly when `fails` is false. An open set. The order is not contract |
| `fail_on_applied` | boolean | True in a `check` run. False in a `generate_baseline` run, which only `--strict-network` can fail |

Each finding's `gate`:

| Key | Type | Holds |
|---|---|---|
| `reaches_fail_on` | boolean | Whether the finding is at or above `run.fail_on`. lockrot decides it in every mode. Always false under `none` |
| `exempt_by` | string or null | `baseline` when the finding reaches `run.fail_on` and the baseline accepted it (`baseline.status` is `known`, so a `worsened` finding is not exempt). Otherwise null. Non-null only where `reaches_fail_on` is true. An open set |
| `fails` | boolean | True exactly when `reaches_fail_on` is true, `exempt_by` is null and the root `fail_on_applied` is true |

### Run notes

`note_details` has one entry per `notes` string, at the same index and with the same `text`. A code
can repeat, so identify an entry by its index. It is `[]` when `notes` is. The `--explain` document
carries the same list beside its own `notes`.

| Key | Type | Holds |
|---|---|---|
| `code` | string | What the run could not see. The id of its section in [notes.md](notes.md). An open set |
| `text` | string | The `notes` string: text, not contract |
| `data` | object | The facts behind the note, typed per `code`. `{}` for a code with none |
| `docs_url` | string or null | The code's section on lockrot.dev. Null means no page. Read it, never build it |
| `sets_network_failures` | boolean | Whether this note makes the root `network_failures` true |

For a code the schema does not list, show `text`, link `docs_url` when it is a string, ignore
`data`, and still honour `sets_network_failures`. Key on `note_details`, never on the `notes`
sentences.

### How a priority was reached

`priority_basis` has `base`, the priority that the verdict starts at, and `steps`, each
`{reason, from, to}` in the order that lockrot applies them:

1. `transitive`, or `unreached` when `chain` is empty: exactly when `direct` is false.

2. `dev`: the finding's `dev` is true.

3. `no_fix_expected`: the finding's `no_fix_expected` names an advisory.

How the steps chain:

- lockrot records a step whenever its fact holds on a flagged finding, also when the step cannot
  move the priority. `from` then equals `to`.

- The first step starts at `base`, each later one where the last ended, and the last ends at
  `priority`. With no steps, `priority` is `base`.

- An unflagged verdict (`unknown`, `finished`, `ok`) gives `{"base": "none", "steps": []}`.

- `from` and `to` take the priority order without `none`. A step whose `reason` you do not know
  still reads as a move from one priority to another.

[Priority](verdicts.md#priority) says what each step does to the priority.

### Advisories with no fix expected

| `no_fix_expected` | Meaning |
|---|---|
| absent | A report written before 0.13.0 |
| `null` | The verdict makes no fix prediction: every verdict but `abandoned`, `silent` and `left-behind` |
| `[]` | One of those three verdicts, and every advisory is fixed by a release it lets the project reach, or there is none |
| non-empty | `{id, reason}` per advisory that lockrot expects no fix for, in S9's order. They raise the priority ([Priority](verdicts.md#priority)) |

`id` is the `id` of one of the finding's S9 advisories. `reason` is the first that applies, from an
open set:

| `reason` | When |
|---|---|
| `not_on_installed_branch` | The finding is left-behind and the fix is only on a higher branch |
| `releases_unknown` | S9's `releases_read` is false, so lockrot looked for no fix |
| `affected_range_unknown` | The advisory gives no affected range |
| `no_release_fixes` | No listed release above the installed version fixes it |

### Libyears

lockrot derives the root `libyears` block from the findings' `libyears`:

| Key | Type | Holds |
|---|---|---|
| `total` | number or null | Sum over the measured findings, two decimals. Null when `measured` is 0. It sums the unrounded values, so it can differ from the sum of the findings' printed `libyears` by up to 0.005 per measured finding |
| `direct_requirements` | number or null | The same sum over `direct: true`. Null when `measured` is 0 |
| `measured` | integer | Findings whose `libyears` is a number |
| `unmeasured` | object | Findings whose `libyears` is null, counted per reason. Every key that this release writes is present |
| `furthest_behind` | object or null | `{package, version, libyears}` of the finding furthest behind. Null when nothing measured is behind |

`measured` plus every `unmeasured` value is the number of findings, and counting findings by
`libyears_unmeasured` gives `unmeasured` key for key. [Libyears](verdicts.md#libyears) defines the
number and says what it is not.

### Transitive exposure

| Key | Type | Holds |
|---|---|---|
| `exposure` | array | `{package, flagged}` per direct requirement that pulls in attributed flagged packages, most first, then by name. `flagged` is the count S7 carries on that requirement's finding |
| `exposure_rule.max_fan_in` | integer | The most direct requirements that can reach a flagged transitive package while it still counts under each. The value that this run used: read it, and do not hard-code it |
| `unattributed` | array | `{package, verdict, fan_in}` per flagged transitive package above that cap, in report order. `fan_in` is how many of the run's direct requirements reach it. Such a package counts in no `exposure` entry and no S7 |

See [Transitive exposure](verdicts.md#transitive-exposure).

### Signal data

`report-1.json` types every signal's `data` (`S9` in `definitions.s9`), and [The
signals](verdicts.md#the-signals) says when each fires. This table lists only the fields that a
release after 0.9.0, the first published schema, added or changed. A field that the table omits is
still typed and still written.

| Signal | Field | Since | Holds |
|---|---|---|---|
| S5 | `target_major` | 0.11.0 | The first release of the target's major, `8.0` for a target of `8.4` |
| S5 | `ga_date` | | The GA date of `target_major`. In a report written before 0.11.0, the GA of the target minor |
| S5 | `written_for_php` | 0.11.0 | The PHP major of the constraint's lower bound. Null when it has none (`*`) |
| S6 | `reason`, `has_stable_release`, `last_stable_release`, `last_stable_version`, `last_stable_dated_by`, `snapshot_time` | 0.13.0 | See [What S6 carries](verdicts.md#what-s6-carries) |
| S8 | `newest_php` | 0.11.0 | The php requirement of the newest branch's release. Null when it requires no PHP |
| S8 | `newest_within_reach` | 0.11.0 | Whether the newest branch's php requirement admits both the project's own `require.php` and the target PHP ([Within reach](verdicts.md#within-reach)) |
| S8 | `floor_php`, `floor_source` | 0.11.0 | What holds the newest branch back, and which floor that is (`project` or `target`, an open set). Both null when it is within reach |
| S8 | `reachable_branch`, `reachable_version`, `reachable_release` | 0.11.0 | The newest higher branch that releases and is within reach, the one that `suggested_constraint` follows. Null when none is |
| S9 | `releases_read` | 0.13.0 | True when lockrot read the releases and compared them with the installed version, so a null `fixed_by` means none fixes it. False with no metadata or an incomparable version ([Security advisories](verdicts.md#security-advisories)) |
| S10 | `unchecked`, `blocks` | 0.11.0 | See [What was not checked](verdicts.md#what-was-not-checked) |

### Dates

Dates are RFC 3339 strings (`format: date-time`). S5's `ga_date` and a baseline entry's
`first_seen` are plain `YYYY-MM-DD`.

### The explanation

The explanation's `finding` has a report finding's fields without `baseline` and `gate`. Its
`project_php` and `note_details` are the report's. `explain-1.json` types its `lock`, `metadata` and
`activity`. [Explaining one package](configuration.md#explaining-one-package) says what each shows.

`explain-1.json` does not type a signal's `data`. Validate it against the matching
`definitions.s<N>` of `report-1.json` (`S9` → `definitions.s9`), or read it as the report types
it.

| Field | Since |
|---|---|
| `metadata.installed_release`, `metadata.installed_release_dated_by` | 0.11.0 |
| `metadata.branches[].php` | 0.11.0 |
| `metadata.branches[].admits_target_php`, `admits_project_php`, `php_blocked_by`, `misses_target_php`, `misses_project_php` | 0.13.0 |
| `project_php`, `note_details` | 0.13.0 |

## Where a package came from

A finding's `origin` says where its lock entry came from. lockrot reads it from the entry and the
`repositories` of the manifest that Composer reads (`COMPOSER=alt.json` means alt.json). It asks no
repository, so the same lock and manifest give the same `origin` on every machine.

| Key | Type | Holds |
|---|---|---|
| `kind` | string | Where the lock entry came from. An open set |
| `registry` | string or null | The known host that the notification-url reports to, or null. A label: build no URL from it. An open set |
| `package_url` | string or null | The package's page on `registry`, for a registry that keeps a public page per package name, when the lock's name is a package name. Null otherwise. Link it only when it is a string, and never build one |
| `local` | boolean | Whether Composer installed the package from the machine it ran on: a `path` or `artifact` repository, or a dist or source that is a path or a `file://` URL, such as a VCS checkout on the disk |

An entry with a notification-url is `packagist` or `composer`. For any other entry, lockrot walks
the manifest's repositories in the order Composer consults them (one named `packagist` last, `only`
and `exclude` applied), and the first repository that could have served the entry decides its
kind. An entry that no repository explains is `path` when its dist is a local directory, and
`unknown` otherwise.

| `kind` | The lock entry | `registry` | `from_composer_repository` |
|---|---|---|---|
| `packagist` | Its notification-url reports to packagist.org: packagist.org itself, or a mirror that keeps packagist.org's notify URL | `packagist.org` | true |
| `composer` | Its notification-url reports to a host other than packagist.org | A known host, or null for a host lockrot does not know | true |
| `path` | Its dist is a local directory | null | false |
| `vcs` | Its source is a VCS repository the manifest lists | null | false |
| `artifact` | Its dist is an archive inside an `artifact` repository the manifest lists | null | false |
| `package` | An inline `package` definition in the manifest gives exactly this entry | null | false |
| `unknown` | lockrot cannot decide the kind | null | false |

An entry is `unknown` when lockrot cannot decide, for example:

- an entry with no notification-url that no `path`, `vcs`, `artifact` or `package` repository
  explains. One example is an entry from a `type: composer` repository that advertises no notify
  URL (asset-packagist.org, a Satis build without `notify-batch`,
  [Which Composer repository answers](internals.md#two-passes)).

- a name that a repository earlier in the list could also have served.

- an entry from a VCS repository renamed or removed since the lock was written. lockrot reads the
  manifest as it is at the time of the run.

- in a run without composer.json, or with a `repositories` list that Composer refuses, every
  entry that has no notification-url and no local dist. There is no repository to read.

Read a kind that you do not know by `from_composer_repository`. Show it as written and link nothing.

- **`from_composer_repository`** is true when the lock entry carries a `notification-url`. True
  does not mean packagist.org. lockrot asks a repository for metadata, advisories and repository
  activity only about such an entry, so a false one has no metadata, advisories, activity or
  libyears. `--explain` writes it as `lock.from_composer_repository`. It stays a boolean in 1.x,
  and `origin` is the finer account.

- **`registry`** takes its values from the schema's `x-known-values`, the hosts that this release
  knows. Of `packagist.org`, `repo.packagist.com`, `wp-packages.org` and `packages.drupal.org`, only
  packagist.org and wp-packages.org get a `package_url`. Private Packagist keeps no public pages,
  and a drupal.org project page does not follow from the package name.

- **`package_url`** is the page the registry keeps for that name. Whether this run's repositories
  still list the package is the finding's `note`.

- **`replacement_url`** is the page that packagist.org keeps for an abandoned finding's
  `replacement`. It is a string only when the registry that named the replacement is packagist.org,
  null otherwise. That registry is the one whose metadata marked the package abandoned or, with no
  metadata, the one that the lock entry came from. lockrot does not check that the page exists.
  Link it only when it is a string, and never build one from `replacement`.

- **`local`** says nothing about where. An explanation's `lock.repository` shows such a path by its
  last segment (`.../lib`). A path dist is local whichever notification-url the entry carries.

### What a report says about your repositories

`origin` copies nothing from the entry's URLs or the manifest's. It writes a kind, a registry from
lockrot's own list and a URL built from that list. It never writes:

- the notification-url, which can carry a login and a password.

- a dist, which for an `artifact` repository is a path on the machine that wrote the lock.

- a mirror URL, which for Private Packagist names the organisation.

A private registry is `kind: composer` with `registry: null`: its host is not written.
`repo.packagist.com` says that the project uses Private Packagist, never which organisation.

Other parts of a report can name a private registry or a repository host, and `origin` does not
change them:

- a run note about a Composer repository that lockrot could not read (`advisories_unavailable`,
  `metadata_unavailable`, `monorepo_parent_unavailable`): its `text`, its `notes` entry, and a
  `message` or `composer_repository` in its `data`.

- a finding's `note` and `evidence` when its metadata did not come.

- the hosts and repositories in S3, S4 and the repository activity notes.

- on the HTML page, each package's `details` (`lock.repository`, `metadata.repository`,
  `repository_link`, `activity`).

None of them carries a credential or a local path. Hosts and URL paths stay visible, and name a
private repository as plainly as its host does.

lockrot redacts what it quotes:

- A URL loses its userinfo, query and fragment, whatever Composer masked of it.

- A repository without a scheme loses its user (`git.example.com:lib.git`).

- A `file://` URL and every other path of the machine keep their last segment alone (`.../ca.pem`),
  and a home directory is `...` alone.

- A value that starts with `...` is a redacted local path. When `lock.repository` is one, the
  finding's `origin.local` is true.

- lockrot replaces a message that it cannot read whole with
  `(withheld: lockrot could not redact this message)`.

- `baseline.path` is relative to the project directory, or the file name alone. `run.lock_file` is
  the lock's name alone.

The one absolute path a report carries is SARIF's `%SRCROOT%` ([ci.md](ci.md#-formatsarif)).

To remove the registry label from `origin`, and only from `origin`, clear both keys. The document
stays valid, since both can be null:

```bash
jq '.findings |= map(if .origin then .origin.registry = null | .origin.package_url = null else . end)' lockrot.json > lockrot.public.json
```

## Validating in CI

Any draft-04 validator works. With [check-jsonschema](https://check-jsonschema.readthedocs.io/):

```bash
composer lockrot --fail-on=high --target-php=8.4 --format=json > lockrot.json || test $? -eq 1
check-jsonschema --schemafile https://lockrot.dev/schema/report-1.json lockrot.json
jq -e '.gate.fails | not' lockrot.json
```

`|| test $? -eq 1` lets validation run after exit `1`. Exit `2` still fails the step
([exit codes](ci.md#exit-codes)). The `jq` line keeps the gate. It fails the step when `gate.fails`
is true.

To keep the check independent of lockrot.dev, pin the schema next to the workflow. When you upgrade
lockrot, refresh the copy, so that it lists new fields and types a new signal's `data`:

```bash
curl -fsSL -o ci/lockrot-report.schema.json https://lockrot.dev/schema/report-1.json
check-jsonschema --schemafile ci/lockrot-report.schema.json lockrot.json
```

[Ajv](https://ajv.js.org/) needs three things for these files:

- `ajv-draft-04`, for the dialect.

- `ajv-formats`, for `date-time`.

- `x-known-values` declared, since strict mode refuses a keyword that it does not know. Use
  `ajv.addKeyword({keyword: "x-known-values", schemaType: "array"})`, or `strict: false`.

## Related

- [ci.md](ci.md) — every output format, and what each exit code means
- [verdicts.md](verdicts.md) — what the verdicts, signals and priorities in a report mean
- [notes.md](notes.md) — each run note code, its `data` and what to do about it
- [baseline.md](baseline.md) — writing and updating the file `baseline-1.json` describes
- [configuration.md](configuration.md) — the `extra.lockrot` keys `config-1.json` types, and `--explain`
- [compatibility.md](compatibility.md) — what 1.0 freezes beyond the schemas
- [changelog](changelog.md) — the release each schema change shipped in
