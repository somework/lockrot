---
title: JSON schemas — lockrot report, explanation, baseline and configuration
description: The published JSON Schema of every lockrot document. What a schema number allows, which value sets grow, moving from report-1, CI validation and the report's fields.
---

# JSON schemas

Validate lockrot's machine-readable documents against these schemas and build on the fields they
type. Under one schema number a document only gains fields, the sets in [Open sets](#open-sets)
only gain values and a field's type only widens.

| Document | Schema | Written by / read from |
|---|---|---|
| The report | [`https://lockrot.dev/schema/report-2.json`](https://lockrot.dev/schema/report-2.json) | `composer lockrot --format=json` |
| The explanation | [`https://lockrot.dev/schema/explain-2.json`](https://lockrot.dev/schema/explain-2.json) | `composer lockrot --explain=vendor/package --format=json` |
| The baseline file | [`https://lockrot.dev/schema/baseline-2.json`](https://lockrot.dev/schema/baseline-2.json) | `--generate-baseline` writes `lockrot-baseline.json`. Every later run reads it |
| The configuration | [`https://lockrot.dev/schema/config-2.json`](https://lockrot.dev/schema/config-2.json) | `extra.lockrot` in `composer.json` |

- **Dialect.** [JSON Schema draft-04](https://json-schema.org/specification-links#draft-4).

- **What lockrot validates.** `composer lockrot` exits `2` on a baseline file or an `extra.lockrot`
  that fails its schema ([exit codes](ci.md#exit-codes)). At install time lockrot skips its
  install-time check and prints one `lockrot: install-time check skipped: …` line
  ([install-time.md](install-time.md#never-fails-the-install)).

- **Copies.** The PHAR and each [release tag](https://github.com/somework/lockrot/tags) ship the
  same files as `resources/lockrot-<name>-<number>.schema.json`. The copy in your PHAR, or at your
  release's tag, describes exactly that release. The URL describes the newest release under the
  same number.

- **The `-1` files** (report-1, explain-1, baseline-1 and config-1) stay published at their URLs
  and do not change. lockrot 0.13 and earlier wrote them.

## The documents say which schema they follow

The report, the explanation and the baseline file open with a `$schema` key that names their URL:

```json
{
    "$schema": "https://lockrot.dev/schema/report-2.json",
    "lockrot": {
        "version": "0.14.0",
        "schema": 2
    },
    …
}
```

An editor that reads `$schema` completes and checks these documents as you edit them. Check
`$schema` before you read a field: `lockrot.schema` is `2` in a report, an explanation and a
baseline file alike.

`extra.lockrot` sits inside `composer.json`, which has a schema of its own, so it carries no
`$schema`. Map `config-2.json` to the `extra.lockrot` path in the JSON schema settings of your
editor.

## The number, and what can change under it {#the-number-and-what-may-change-under-it}

The `2` in `report-2.json` is the `lockrot.schema` value the document carries.

- **Fields are only added.** No object with named fields sets `additionalProperties: false`, so a
  field your code does not know is not an error. A map keyed by a closed set (a baseline entry's
  `flags`) or by a pattern (`run.score_rules_used`) rejects other keys.

- **Types can widen.** A field can accept a further type (for example `null`) under the same
  number. A document that uses the wider type fails an older copy. When you upgrade, refresh a
  pinned copy.

- **The number moves** only when a field is removed, renamed, made required or narrowed, or a
  closed set changes. The old file stays published at its old URL.

- **The file at a URL widens in place.** Every document that a lockrot release of the same number
  wrote validates against it.

[compatibility.md](compatibility.md) lists what else 1.0 freezes.

## Open sets

Objects are open. Every set of values that the schema marks with `x-known-values` is open too, and
grows in minor releases. These are the ones that a reader meets most:

- signal ids: a signal's `id` and S10's `blocks`
- S10's `check` and `reason`
- S6's `reason`, and S8's `floor_source`
- a finding's `libyears_unmeasured`, and the keys of the report's `libyears.unmeasured`
- in report-1, a `priority_basis` step's `reason`, a `no_fix_expected` item's `reason`, and
  `run.fail_on_kind`
- `run.mode` and `gate.tripped_by`, and a finding's `gate.exempt_by`
- a finding's `origin.kind` and `origin.registry`
- a run note's `code`, and the `forge_id` and `reason` in its `data`
- the explanation's `php_blocked_by`, `misses_target_php` and `misses_project_php`
- in report-2, `run.gates[].kind` and a finding's `gate.by[].kind`
- in report-2, a flag's `role` and its `headline.value`, a score term's `role`, a modifier's
  `reason` and `applies_to`, and the `status` of each score part
- in report-2, `security.check` and `security.unchecked_reason`, an S9 fix's `reason`, and an
  ignored advisory's `by` and `matched`
- in report-2, the `check` and `reason` of `checks_missing[]` and `checks_skipped[]`, S6's
  `tag_relation`, and an allowlist entry's `reason_id`
- in report-2, a finding's `activity.reason`, `activity.forge` and `activity.event`, S4's `event`,
  and the `listed_in` of a `repository_activity_host_not_supported` note's host
- in report-2, each `*_unmeasured` key of a finding and of its `age`, and the keys of the report's
  `libyears.unmeasured`
- in report-2, an S9 fix's `kind`, `next_step.kind` and `next_step.reason`, the `kind` of a
  `gate.by[].facts[]` entry (`flag`, `advisory`, `signal`), and the `check` and `reason` of a
  `gate.not_applied[]` entry
- in report-2, `baseline.worsened_by[].why`, `if_applied.assumes[]`, `latest.relation`,
  `replacement.named_by`, the `basis` of `clears[]` and `adds[]`, and `unverified[].reason`
- in report-2, `abandoned_ignored.by`, and the `by` and `policy_key` of the abandoned ignore list's
  notes
- in report-2, the `registry` and `reason` of `security.feeds[]` and of
  `run.advisory_lookup.repositories[]`
- in report-2, the `reason` of a move's `check`, of `run.move_check` and of
  `run.php_window.target_fix`
- in report-2, the words of `run.score_model`
- the configuration's `format`, and format names wherever lockrot writes one.

Repository hosts are an open set in a run note's `forge_id` and in report-2's `forge` of S3, S4 and
`activity`. Composer registries are an open set only in a finding's `origin.registry`. S3 and S4's
`host` and a finding's `activity.host` are plain strings.

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

## Migrating from report-1

A report-2 document names `https://lockrot.dev/schema/report-2.json` in `$schema`. Assert that URL
before you read a field: a report-1 filter run on report-2 matches nothing and exits `0`. This table
names what to read for each report-1 field that report-2 removes or redefines.

| report-1 | report-2 |
|---|---|
| no guard | `."$schema" == "https://lockrot.dev/schema/report-2.json"` first. `.lockrot.schema == 2` also passes on a baseline-2 file, whose `findings` is an object |
| `.verdict == "abandoned"` | `.lead == "abandoned"`: [`lead`](verdicts.md#flags) is report-1's word for the same findings |
| `.verdict` at or above `left-behind` | `.lead as $l \| ["abandoned","silent","pinned","left-behind"] \| index($l) != null` |
| a flag anywhere on the finding | `any(.score.terms[]?; .flag == "old-promise")`: a flag counts exactly when a score term names it |
| `.priority == "critical"` | unchanged (an alias), or `.verdict == "critical"` |
| `.verdict == "ok"` | `.verdict == "ok"`. A package with a counted advisory and no maintenance flag is graded, with `lead` null |
| `.counts.abandoned`, `.counts["left-behind"]` | `.flags.abandoned.leading`, `.flags["left-behind"].leading`: the `leading` of the same flag. `counts` holds the seven verdicts, so `.counts["left-behind"]` is null |
| `.counts.ok`, `.counts.finished`, `.counts.unknown` | the same keys. A package with a counted advisory is graded, not `ok`, `finished` or `unknown` |
| "has an advisory": `any(.signals[]; .id == "S9")` | unchanged, or `.security.status == "vulnerable"` |
| `.run.fail_on_kind` | `(.run.gates // [])[].kind`: `flag` is report-1's `verdict`, and `grade` is report-1's `priority`. `.run.fail_on` stays a string |
| `.run.flagged_verdicts` | `.run.graded_verdicts` for the grades, `.run.flag_ids` for the flags |
| `.baseline.previous_verdict` | `.baseline.recorded.lead` (null for a package new to the baseline) |
| S7's `.data.packages[].verdict`, `.unattributed[].verdict` | `.lead` on the same objects. `.verdict` there is the grade |
| gating on `.priority` and `.baseline.status` | `.gate.fails`. Under a baseline, `.priority` is the full grade, while the gate reads `min(score, 2 × new)` |
| `.evidence` (every fact) | `.evidence` holds the summaries of the counted flags only. Read `[.flags[].summary]` for every flag that fired, accepted ones included, and `[.signals[].summary]` for the rest |
| `.priority_basis` | `.score`: its `terms`, its `modifiers` and its `text` |
| `.no_fix_expected`, S9's `fixed_by` and `fixed_on_branch` | `.security` and the `fix` of each S9 row |
| `.libyears.total` | the same key. It is the rounded sum of the findings' `libyears` |
| `.libyears_unmeasured == "metadata_unavailable"` | `.libyears_unmeasured == "unavailable" or .libyears_unmeasured == "not_found"` |
| `.libyears_unmeasured == "no_stable_release_date"` | `shared_commit`, `undated`, `no_stable_release` or `undated_releases`, the ids that `age` uses for the same cause |
| S9's `.severity == null` | `.severity == "unrated"`. `moderate` reads `medium`, and `.severity_published` keeps Composer's word |
| S4's `.data` (`last_push`, `repo`, `host`, `years`) | the same keys, plus `forge` and `event` (`push` or `commit`) |
| explain-1's `.activity`, `.target_php`, `.project_php`, `.thresholds` | explain-2's `.finding.activity`, `.run.target_php`, `.run.project_php`, `.run.thresholds`. `.finding.activity` is an object on every finding: read its `status` where explain-1's `.activity` was null |
| explain-1's `.activity.pushed_at`, `.activity.repository`, `.activity.from_cache` | `.finding.age.push.at`, `.finding.activity.host` and `.finding.activity.repo`, `.finding.activity.cached_at != null` |
| the html page's `details.<package>.activity` | the finding's `activity`. `repository_link` stays in `details` |
| a baseline entry's `.verdict` | the first key of the entry's `flags`, in flag order, or the report's `.baseline.recorded.lead` |
| every flag id a finding mentions: `[.. \| .flag_ids? \| arrays]` | `[.score.terms[]?.flag]` for the counted flags, and `.allowlist.flag_ids` for the accepted ones (null: every maintenance flag) |

Recipes for the new fields:

```bash
# why each graded finding scores what it does
jq -r '.findings[] | select(.score.total > 0) | "\(.package) \(.score.total) " + ([.score.terms[] | "\(.flag) \(.contribution)"] | join(" + "))' report.json
# what each move would leave, the also[] moves included
jq -r '.findings[] | .package as $p | .score.total as $t | (.next_step | ., .also[]?) | select(.if_applied) | "\($p) \(.kind): \($t) -> \(if .if_applied.at_most then "at most " else "" end)\(.if_applied.total) \(.if_applied.verdict)"' report.json
# which facts fail the gate
jq -r '.findings[] | select(.gate.fails) | "\(.package): " + ([.gate.by[].facts[] | .id] | unique | join(", "))' report.json
# the gate: line
jq -r '.gate.text' report.json
# what the repository activity lookup did for each package
jq -r '.findings[] | "\(.package) \(.activity.status) \(.activity.reason // "")"' report.json
# graded findings by depth below composer.json
jq -c '.rot_depth.by_depth' report.json
# what each advisory repository did in this run
jq -r '.run.advisory_lookup.repositories[] | "\(.composer_repository) \(.outcome)"' report.json
# libyears values that are lower bounds
jq -r '.findings[] | select(.libyears_at_least) | .package' report.json
```

`score.text`, `next_step.text`, `gate.text` and every `summary` are prose. Key a script on the
fields, never on the sentences. JSON writes `÷`, `¼` and `×` as `\u` escapes: `jq -r` decodes
them, and `grep` on the raw file does not.

## What the report schema types

`report-2.json` holds every nested key, with a description for each. Every report-2 document
carries every field that this page names: a nullable field is written as null, never left out. Two
shapes leave out the parts that do not apply. A score of 0 carries only `model`, `total`, `exact`,
`accepted` and `text`. A `security` block that is `clear` or `unchecked` has no `worst`, `counts`,
`fix_kind`, `installed_branch_fixes`, `move_in`, `gets` or `partial`.

### The document

| Field | Type | Holds |
|---|---|---|
| `$schema` | string | The URL of `report-2.json` |
| `lockrot` | object | `version`, the lockrot release that wrote the document, and `schema`, the schema number |
| `generated_at` | date-time | When the run ran: every years field is measured against it |
| `run` | object | What the run was told to do: see [What the run was told](#what-the-run-was-told) |
| `activity_cache_oldest_at`, `activity_cache_age_hours` | date-time, number, or null | Fetch time of the oldest repository-activity answer served from lockrot's cache, and its age in hours. Null when lockrot fetched every answer in this run |
| `packages_checked` | integer | Findings in the document |
| `packages_flagged` | integer | Graded findings |
| `packages_multi_flag` | integer | Graded findings with two or more counted flags |
| `include_dev` | boolean | Whether lockrot analysed `packages-dev` |
| `not_from_composer_repository` | integer | Findings whose `from_composer_repository` is false |
| `packages_unchecked_accepted` | integer | Findings with a check that `accept-unchecked` accepted |
| `network_failures` | boolean | True exactly when a `note_details` entry has `sets_network_failures: true`. `--strict-network` fails on it ([ci.md](ci.md#exit-codes)) |
| `counts` | object | Findings per verdict, the seven verdicts, zero included |
| `priorities` | object | Findings per `priority`, the alias of the grade. Deprecated: read the grades in `counts` |
| `abandoned` | object | `total`, the findings whose `abandoned` counts, `with_replacement`, those whose `replacement` names a package, and `with_suggestion`, those whose registry suggests something that names no package |
| `flags` | object | Per flag: `carrying` (findings where it counts), `leading` (findings it leads, report-1's count of that verdict), `accepted` and `by_verdict`. `vulnerable` has null `leading` and `accepted` |
| `exposure`, `exposure_rule`, `unattributed` | | See [Transitive exposure](#transitive-exposure) |
| `rot_depth` | object | Graded findings by reach, by depth below `composer.json` and by section ([How deep the rot sits](verdicts.md#rot-depth)) |
| `libyears` | object | Totals over the findings' `libyears`: see [Libyears](#libyears) |
| `baseline` | object or null | `path`, the counts `known`, `new` and `worsened`, and `stale`, the baselined names absent from the lock. Null when the run read no baseline |
| `gate` | object | Why the run exits as it does: see [The gate](#the-gate) |
| `security` | object | The run's advisory totals: the lookup's `check`, packages and advisories by status and severity, vulnerable packages per fix kind, `update_now` and its command |
| `data_date` | date-time or null | The oldest finding `data_date` |
| `notes` | array of strings | What the run could not see, as sentences: text, not contract |
| `note_details` | array | The same notes, typed: see [Run notes](#run-notes) |
| `findings` | array | One per analysed package, in `rank` order ([sort](verdicts.md#score)) |

### Each finding

| Field | Type | Holds |
|---|---|---|
| `package`, `version` | string | The package name and the installed version, as the lock records it |
| `branch` | string or null | The installed release branch (`2.x`, `0.15.x`). Null for a branch snapshot |
| `installed_php` | object | The installed release's own `require.php`, and whether the target PHP and the project's `require.php` admit it |
| `verdict` | enum | A [verdict](verdicts.md#the-nine-verdicts): a grade, or `unknown`, `finished` or `ok` |
| `priority` | enum | The grade, else `none`. Deprecated: read `verdict` |
| `lead` | string or null | The first counted maintenance flag ([Flags](verdicts.md#flags)) |
| `rank` | integer | The finding's place in the report's order, 1 first |
| `flags` | array | Every flag that fired, in flag order: `{id, role, baseline, signal_ids, degree, headline, summary}`. `role` is `accepted` on an accepted flag. A flag counts exactly when `score.terms[].flag` names it |
| `score` | object | The sum: see [The score](#how-a-priority-was-reached) |
| `next_step` | object or null | The move: see [The move](#the-move). Null at score 0 |
| `security` | object | What the advisory lookup found: see [Security](#advisories-with-no-fix-expected) |
| `checks_missing`, `checks_skipped`, `unchecked_accepted` | array | The checks that did not run, as S10 or as skips, and the ones that `accept-unchecked` accepted ([What was not checked](verdicts.md#what-was-not-checked)) |
| `maintenance_judged` | boolean | True exactly when lockrot read the package's release metadata |
| `metadata` | object | `status` (`read`, `not_from_composer_repository`, `unavailable`, `not_found`) and why it was not read |
| `activity` | object | What the repository activity lookup did: `status`, `reason`, `forge`, `host`, `repo`, `event`, `archived`, `fetched_at` and `cached_at` |
| `age` | object | Four readings on the run clock (`installed`, `release`, `branch_release`, `push`), each with `years`, and a `<reading>_unmeasured` reason beside each null one |
| `allowlist` | object or null | Who accepted the package's flags (`by`: `builtin`, `project`, `type`), the `pattern`, `reason`, `reason_id`, `reason_by`, `expires` and `flag_ids` (null: every maintenance flag) |
| `allowlist_reason` | string or null | The reason of an entry that accepts the whole package |
| `abandoned_ignored` | object or null | The mark that [Composer's abandoned ignore list](verdicts.md#abandoned) removed: `by`, `rules` and `marking` |
| `from_composer_repository`, `origin` | | Where the lock entry came from: see [Where a package came from](#where-a-package-came-from) |
| `replacement`, `replacement_url` | string or null | On a finding whose `abandoned` counts, the package its registry names as the replacement, and its page ([Abandoned](verdicts.md#abandoned-and-where-to)) |
| `note` | string or null | Why lockrot has no repository metadata for the package, as a sentence |
| `libyears`, `libyears_unmeasured`, `libyears_at_least` | | Years behind the newest stable release, the reason when unmeasured, and whether the value is a lower bound ([Libyears](verdicts.md#libyears)) |
| `direct`, `dev`, `reach` | | Whether the project requires it directly, whether the lock lists it under `packages-dev`, and `direct`, `transitive` or `unreached` |
| `chain`, `direct_dependents` | array | The shortest path from a direct requirement, and every direct requirement that reaches it |
| `depth`, `depth_unmeasured`, `named_in` | | Hops below `composer.json`, why there are none, and the `composer.json` sections that name it ([How deep the rot sits](verdicts.md#rot-depth)) |
| `signals` | array | `{id, level, summary, data}` per [signal](verdicts.md#the-signals). `data` is typed per id, `summary` is text |
| `evidence` | string | The summaries of the counted flags: text, not contract |
| `data_date` | date-time or null | When lockrot read the repository data behind the finding |
| `baseline` | object or null | `status` (`known`, `new` or `worsened`), `recorded`, `advisories_now` and `worsened_by`. Null when the run read no baseline, or the finding scores 0 and has no entry |
| `gate` | object | Where the finding stands against `run.gates`: see [The gate](#the-gate) |

### What the run was told

`run` holds what lockrot decided every finding against. The command writes it on every run.

| Key | Type | Holds |
|---|---|---|
| `project` | string or null | What the report calls the project: composer.json's `name`, or `extra.lockrot.project` instead. A display name, not an identifier |
| `root_package` | string or null | The `name` of the manifest Composer reads, exactly as written: the key to match a report to its repository |
| `target_php`, `target_php_source` | string | The target PHP minor, and where it came from: `option`, `env`, `config`, `platform` or `runtime` ([`target-php`](configuration.md#extralockrot-keys)) |
| `project_php` | string or null | The manifest's `require.php` exactly as written (`>=8.2`): a constraint, not a version |
| `project_php_lowest` | string or null | The lowest PHP that `require.php` admits, as `MAJOR.MINOR.PATCH` (`>=8.2` is `8.2.0`, `>7.1` is `7.1.1`) |
| `lock_file` | string or null | The lock's file name, never its path |
| `fail_on`, `fail_on_source` | string | The `--fail-on` list in kind order (`"left-behind,vulnerable-high"`), and where it came from: `option`, `env`, `config` or `default` |
| `gates` | array | `{value, kind, threshold}` per value of the list. `kind` is `grade`, `flag`, `vulnerable` or `unchecked`, an open set. `[]` for `none` |
| `strict_network` | boolean | Whether `--strict-network` was on |
| `include_dev` | boolean | Whether the run walked `require-dev` |
| `mode` | string | `check`, or `generate_baseline` for `--generate-baseline`, which records the findings and judges none. An open set |
| `thresholds` | object or null | `release-warn-years`, `release-high-years`, `push-warn-years` and `push-high-years` ([keys](configuration.md#extralockrot-keys)) |
| `flag_ids`, `verdicts`, `graded_verdicts`, `signal_ids` | array | The flags in flag order, the seven verdicts, the four grades, and the signal ids |
| `fix_model`, `text_grammar` | integer | The id of the fix classification, and the version of the sentence grammars |
| `score_model` | object | The whole score model: weights, severities, fix kinds, bands, sort and rules ([Score model 1](verdicts/model-1.md)) |
| `score_rules_used` | object | Rule id → the number of findings on which the rule changed or decided a number. Null for `sort` |
| `advisory_lookup` | object | `scope` and `scope_source`, the packages asked and not asked, and each Composer repository that can publish advisories, with its `outcome` ([Which advisories count](verdicts.md#which-advisories-count)) |
| `move_check` | object or null | Which Composer checked the moves (`composer`), and whether it blocked the versions that an advisory affects. `baseline` says whether Composer's solver resolves or refuses the lock itself, with `reason` when lockrot did not check it. Null when the run checked no move ([The move check](verdicts.md#the-move-check)) |
| `php_window` | object or null | The PHP minors on which the lock installs, with require-dev (`with_dev`) and without (`without_dev`), and the links that set each edge. It also holds the lock's `platform-overrides.php` and the host features that a requirement names. `target` says whether the window admits the target PHP. `target_fix` names the update of the packages that exclude the target, and the answer of Composer's solver to it. Null when the window is unknowable ([The PHP window](verdicts.md#the-php-window)) |

### The gate

The root `gate` is the decision behind the exit code. lockrot takes it over the document's own
findings, by what `run` says.

| Key | Type | Holds |
|---|---|---|
| `fails` | boolean | True: the run exits `1`. False: it exits `0`. A later write error overrides both with `2` ([exit codes](ci.md#exit-codes)) |
| `tripped_by` | array | Each cause once: `strict_network` (it was on and `network_failures` is true) and `fail_on` (some finding's `gate.fails` is true). Empty exactly when `fails` is false. An open set |
| `fail_on_applied` | boolean | True in a `check` run. False in a `generate_baseline` run, which only `--strict-network` can fail |
| `reaching`, `failing`, `exempt` | | How many findings reach a value, fail it, and are exempt by the baseline (`exempt.baseline`) |
| `standing` | object | `{known, new, worsened, none}`: the findings that reach a value, by their `baseline.status`. `none` counts a null `baseline`. The four counts add up to `reaching` |
| `failing_packages` | array | The `package` of each finding whose `gate.fails` is true, in `findings` order. It holds as many package names as `failing` counts, so it is empty in a `generate_baseline` run |
| `failing_by` | array | `{value, failing}` per value of `run.gates`, zero counts included |
| `not_applied` | array | `{check, packages, reason}` per gap that a grade or `vulnerable` value could not read: advisories that lockrot did not check |
| `text` | string | The [`gate:` line](ci.md#reading-the-gate-line): prose, not contract |

Each finding's `gate`:

| Key | Type | Holds |
|---|---|---|
| `reaches_fail_on` | boolean | Whether the finding reaches some value of `run.gates`, read from the score and the flags before the baseline. Always false under `none` |
| `exempt_by` | string or null | `baseline` when the finding reaches a value and the baseline is why it does not fail: a `known` finding, or a `worsened` one whose gate score stays below the value. Otherwise null. An open set |
| `fails` | boolean | True exactly when `reaches_fail_on` is true, `exempt_by` is null and the root `fail_on_applied` is true |
| `by` | array | `{value, kind, facts}` per value that the finding fails, naming the facts whose records are marked `error` |
| `basis` | object or null | The baseline arithmetic: the score, what the new facts add, and the gate score `min(score, 2 × new)`. Null without a baseline entry |

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

### The score {#how-a-priority-was-reached}

A graded finding's `score` holds every number of the sum, so a reader never repeats a rule:

| Key | Holds |
|---|---|
| `model` | The score model id. Scores compare only under one model |
| `total`, `exact`, `rounded_down` | The score, the value before the one floor (in half points), and whether the floor changed it |
| `band` | `floor`, the floor of the grade, `next`, the next grade up, and `to_next`, the points short of it |
| `decided_by` | `maintenance`, `security`, `combination` (the sum decides the band) or `either`. An open set |
| `parts` | Per part, `maintenance` and `security`: its `status`, its `contribution` and the grade it reaches alone. `security` adds `of`, the counted advisories, and `tied` |
| `terms` | One per counted fact, the lead first and the security term last: `weight`, `divisor` or `multiplier`, `points` and `contribution`. The contributions add up to `exact` |
| `modifiers` | Each halving in order: `reason` (`transitive`, `unreached`, `dev`), `applies_to`, `divide_by`, `before` and `after` |
| `accepted` | Each accepted flag, with `if_counted`: the score if it counted |
| `without` | The score without each counted flag, and without the deciding advisory: new runs of the rules |
| `text` | The [score line](verdicts.md#score): prose |

A score-0 finding has `{model, total: 0, exact: 0, accepted, text}`. `terms` is present exactly on
the graded shape.

### The move

`next_step` is the [move](verdicts.md#move) of a graded finding: its `kind`, the `constraint` or
command, `clears`, `unverified` and `leaves` (the facts it settles, cannot check and does not
touch), `held_by`, `php_check`, `if_applied` (the score after it), `through` for a package that
comes in through others, `commands` (argv lists) and `text`. `also` holds the other part's move.
`kind` is an open set: show `text` for a kind you do not know.

Each argument of `commands` and of `security.update_now_command` matches
`^[A-Za-z0-9/._:@+=,^~*<>| -]+$` and holds more than spaces. Only a Composer option starts
with `-`: `--no-update` in a `require` step, and `--with-dependencies` or `--with-all-dependencies`
in an `update` or `require` step. An option comes after the package arguments. The first argument is `composer`,
and the second is `update`, `require` or `why-not`. An argument comes from the lock or a
repository, so lockrot leaves out a step that breaks this rule. The move keeps its `constraint`,
`text` and other steps. `text` shows a command only as the POSIX rendering of a step in `commands`.
Render each argv with one of these renderings:

| Shell | Rendering | Example |
|---|---|---|
| POSIX | Single-quote an argument with a character outside `[A-Za-z0-9/._:@%+=,-]`, or one that starts with `=` | `composer require 'php:>=8.1' --no-update` |
| cmd.exe | Double-quote an argument with a space, a caret, a vertical bar, `<` or `>` | `composer require "php:>=8.1" --no-update` |
| PowerShell | Write the first argument, `--%`, then the cmd.exe rendering of the others | `composer --% require "php:>=8.1" --no-update` |

The PowerShell target is often `composer.bat`, and cmd.exe then parses the arguments again.
PowerShell can also read a bare version such as `1.10` as a number. After `--%`, PowerShell passes
the rest of the line unchanged.

!!! warning
    If an argv of a move breaks this rule, render none of that move's steps, on any shell.
    A report file can be edited, and no quoting is safe for such an argument on cmd.exe or PowerShell.

### Security {#advisories-with-no-fix-expected}

`security` is one of three shapes, told apart by `status`:

| `status` | When | Holds |
|---|---|---|
| `vulnerable` | At least one advisory counts | `worst`, `counts` by severity, `fix_kind`, `installed_branch_fixes`, `gets` (what `composer update` installs), `partial`, `move_in` (where the move is) |
| `clear` | The lookup was complete and found nothing that counts | |
| `unchecked` | The lookup did not run, or ran in part, and found nothing that counts | `unchecked_reason` |

Every shape carries `check` (`complete`, `partial` or `not_run`), `records`, `feeds[]` and
`ignored[]`, the advisories that Composer's audit policy ignores. Each S9 row carries the
advisory's `fix`: `{kind, to_branch, version, newest, on_installed_branch, php, admits_target_php,
admits_project_php, held_by, reason}`
([Fix](verdicts.md#fix)).

The root `security` holds `update_now`, the packages whose advisories `composer update` fixes, and
`update_now_command`, their argv `["composer", "update", …]`. `update_now_command` is null when
`update_now` is empty, and the `update now:` line is then hidden. In `jq`, read it as
`.security.update_now_command // [] | join(" ")`, which gives an empty string when there is no
command.

### Libyears

lockrot derives the root `libyears` block from the findings' `libyears`:

| Key | Type | Holds |
|---|---|---|
| `total` | number or null | The findings' `libyears`, summed and rounded to two decimals. Null when `measured` is 0 |
| `direct_requirements` | number or null | The same sum over `direct: true`. Null when `measured` is 0 |
| `measured` | integer | Findings whose `libyears` is a number |
| `at_least_count` | integer | Findings whose `libyears` is a lower bound. When it is above 0, `total` is a lower bound |
| `unmeasured` | object | Findings whose `libyears` is null, counted per reason. Every key that this release writes is present |
| `furthest_behind` | object or null | `{package, version, libyears, at_least}` of the finding furthest behind. Null when nothing measured is behind |
| `packages` | integer | Findings: `measured` plus every `unmeasured` count |

Counting findings by `libyears_unmeasured` gives `unmeasured` key for key.
[Libyears](verdicts.md#libyears) defines the number and says what it is not.

### Transitive exposure

| Key | Type | Holds |
|---|---|---|
| `exposure` | array | `{package, flagged}` per direct requirement that pulls in attributed graded packages, most first, then by name. `flagged` is the count S7 carries on that requirement's finding |
| `exposure_rule.max_fan_in` | integer | The most direct requirements that can reach a graded transitive package while it still counts under each. The value that this run used: read it, and do not hard-code it |
| `unattributed` | array | `{package, verdict, lead, flag_ids, fan_in}` per graded transitive package above that cap, in report order. `fan_in` is how many of the run's direct requirements reach it. Such a package counts in no `exposure` entry and no S7 |

See [Transitive exposure](verdicts.md#transitive-exposure).

### Signal data

`report-2.json` types every signal's `data` (`S9` in `definitions.s9`), and [The
signals](verdicts.md#the-signals) says when each fires. A signal whose id the schema does not list
validates with any object as its `data`.

| Signal | Field | Holds |
|---|---|---|
| S4 | `event` | What the date is: `push` or `commit`. An open set |
| S6 | `reason`, `has_stable_release`, `last_stable_release`, `last_stable_version`, `last_stable_dated_by`, `snapshot_time`, `tag_relation` | See [What S6 carries](verdicts.md#what-s6-carries) |
| S8 | `suggested_constraint`, `reachable_branch`, `reachable_version`, `reachable_release` | The newest higher branch that releases and is within reach ([Within reach](verdicts.md#within-reach)) |
| S9 | `advisories`, `releases_read`, `complete` | One row per counted advisory, the deciding one first: `id`, `cve`, `title`, `link`, `reported_at`, `severity`, `severity_published`, `affected_versions`, `counted`, `points`, `deciding`, `fix` and `baseline` |
| S10 | `unchecked`, `blocks` | See [What was not checked](verdicts.md#what-was-not-checked) |
| S11 | `newest_php`, `newest_php_source`, `requires`, `stops_at`, `excludes_target`, `admits_newest` | See [Capped](verdicts.md#capped) |

### Dates

Dates are RFC 3339 strings (`format: date-time`). These dates are plain `YYYY-MM-DD`: S5's
`ga_date`, a baseline entry's `first_seen` and `known_since`, the `baseline.since` of a flag and of
an S9 row, `allowlist.expires` and `unchecked_accepted[].accepted_by.expires`. Every years field
holds one decimal at most.

### The explanation

The explanation's `finding` has a report finding's fields. `run` holds the report's `run` keys that
the explanation reads, `target_php`, `project_php` and `thresholds` among them. Its `php_window`
holds only `platform_override` and `target`, which the move's text reads, and is null when the
report has no PHP window. `explain-2.json`
types its `lock` and its `metadata`, whose `branches[]` carry the PHP test of each branch and its
`fixes` ([The PHP test](verdicts.md#the-php-test-in-explain)).
[Explaining one package](configuration.md#explaining-one-package) says what each shows.

### TypeScript

Each lockrot release attaches the TypeScript types of report-2 to its
[GitHub release](https://github.com/somework/lockrot/releases) as two assets:
`lockrot-report-2.d.ts` (the object shapes, with `Report2` at the root) and
`lockrot-report-2.known.d.ts` (each set of values, generated from the schema). Download the pair
of the release whose reports you read. lockrot.dev does not publish them.

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

- the hosts and repositories in S3, S4, a finding's `activity` and the repository activity notes.

- on the HTML page, each package's `details` (`lock.repository`, `metadata.repository`,
  `repository_link`).

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
check-jsonschema --schemafile "$(jq -r '."$schema"' lockrot.json)" lockrot.json
jq -e '.gate.fails | not' lockrot.json
```

`|| test $? -eq 1` lets validation run after exit `1`. Exit `2` still fails the step
([exit codes](ci.md#exit-codes)). The schema comes from the document's own `$schema`, so the step
validates a report-1 and a report-2 document alike. The `jq` line keeps the gate. It fails the step
when `gate.fails` is true.

To keep the check independent of lockrot.dev, pin the schema next to the workflow. When you upgrade
lockrot, refresh the copy, so that it lists new fields and types a new signal's `data`:

```bash
curl -fsSL -o ci/lockrot-report.schema.json https://lockrot.dev/schema/report-2.json
check-jsonschema --schemafile ci/lockrot-report.schema.json lockrot.json
```

[Ajv](https://ajv.js.org/) needs three things for these files:

- `ajv-draft-04`, for the dialect.

- `ajv-formats`, for `date-time`.

- `x-known-values` declared, since strict mode refuses a keyword that it does not know. Use
  `ajv.addKeyword({keyword: "x-known-values", schemaType: "array"})`, or `strict: false`.

## Related

- [ci.md](ci.md) — every output format, and what each exit code means
- [verdicts.md](verdicts.md) — what the flags, the score, the verdicts and the signals in a report mean
- [notes.md](notes.md) — each run note code, its `data` and what to do about it
- [baseline.md](baseline.md) — writing and updating the file `baseline-2.json` describes
- [configuration.md](configuration.md) — the `extra.lockrot` keys `config-2.json` types, and `--explain`
- [compatibility.md](compatibility.md) — what 1.0 freezes beyond the schemas
- [changelog](changelog.md) — the release each schema change shipped in
