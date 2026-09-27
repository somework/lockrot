---
title: lockrot JSON schemas — the report, the explanation, the baseline and the configuration
description: Published JSON schemas for every document lockrot reads or writes, the compatibility rule behind their numbers, and how to validate a report in CI.
---

# JSON schemas

Every document lockrot writes for a machine, and the one it reads from `composer.json`, has a
published schema:

| Document | Schema | Written by / read from |
|---|---|---|
| The report | [`https://lockrot.dev/schema/report-1.json`](https://lockrot.dev/schema/report-1.json) | `composer lockrot --format=json` |
| The explanation | [`https://lockrot.dev/schema/explain-1.json`](https://lockrot.dev/schema/explain-1.json) | `composer lockrot --explain=vendor/package --format=json` |
| The baseline file | [`https://lockrot.dev/schema/baseline-1.json`](https://lockrot.dev/schema/baseline-1.json) | `--generate-baseline` writes `lockrot-baseline.json`; every later run reads it |
| The configuration | [`https://lockrot.dev/schema/config-1.json`](https://lockrot.dev/schema/config-1.json) | `extra.lockrot` in `composer.json` |

The same files ship in the repository and the PHAR under
[`resources/`](https://github.com/somework/lockrot/tree/main/resources), as
`lockrot-<name>.schema.json`. They are [JSON Schema draft-04](https://json-schema.org/specification-links#draft-4),
the dialect Composer's own bundled validator speaks, which is what lockrot validates the baseline
and the configuration with at runtime.

## The documents say which schema they follow

The report, the explanation and the baseline file open with a `$schema` key naming the URL above:

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

An editor that reads `$schema` — VS Code and PhpStorm do — completes and checks a baseline file as
you edit it. `extra.lockrot` lives inside `composer.json`, which has a schema of its own, so it
carries no `$schema`; point your editor's JSON schema mapping at `config-1.json` for the
`extra.lockrot` path if you want the same there.

## The number, and what may change under it

The `1` in `report-1.json` is the `lockrot.schema` number the document carries. Under one number,
a document only ever **gains** fields: every object in every schema is open (no
`additionalProperties: false`), so a field a newer lockrot adds validates against the copy of the
schema you fetched or vendored earlier, and a field your CI step does not know about is not an
error. The number moves only when a field is removed or renamed, and then the old file stays
published at its old URL.

Objects are open, and so are the sets of values that grow in minor releases: see
[Open sets](#open-sets).

What else 1.0 will freeze — the closed sets and their order, the identity fields of the other
formats, the command line — is drafted in [compatibility.md](compatibility.md).

The schema files themselves are edited in place when a field is added, so the copy at the URL always
describes the newest release under that number. The version that added a field is in the
[changelog](changelog.md).

## Open sets

Objects are open, and so are the sets of values that grow in minor releases: signal ids (a signal's
`id` and S10's `blocks`), S10's `check` and `reason`, S6's `reason`, S8's `floor_source` and the
explanation's `php_blocked_by`, which holds the same values, the explanation's `misses_target_php`
and `misses_project_php`, a finding's `libyears_unmeasured`, a `priority_basis` step's `reason`, a
`no_fix_expected` item's `reason`, `run.mode`, `run.fail_on_kind`, `gate.tripped_by`, a finding's
`gate.exempt_by`, a run note's `code`, and the `forge_id` and `reason` in its `data`, and the
configuration's `format`. Each is a string with a
`pattern`, plus an `x-known-values` list of the values this release writes. A validator ignores a
keyword draft-04 does not define, so a copy of the schema taken from 0.13.0 on accepts a signal, a
reason, a floor, a way a branch misses a floor, a priority step, a kind of run or of threshold, a
cause that failed a run, an exemption, a run note, a repository host or a format that a later release adds, and a signal, note code or format named `<vendor>:<name>`, the form reserved for those that do not come
from lockrot ([compatibility.md](compatibility.md#names-reserved-for-extensions)). The vendor and
the name are each lower-case letters, digits, `_`, `.` and `-`, starting with a letter or a digit;
`acme:licence` validates, `Acme:Licence` does not.

- A signal whose id the schema does not list validates with any object as its `data`. A listed id
  still has its `data` typed: S2's id with S4's data fails, and so does S2's id with no data at all.
  A run note's `data` is typed per `code` the same way.
- Read a value you do not know as "other": show it as written, and do not fail on it.
- `x-known-values` only grows under one number, and every value in it matches the `pattern`. To hold
  a document to the values you know, read `x-known-values` as an `enum`. lockrot's own tests do that,
  and so does lockrot when it validates `extra.lockrot`, which is why a mistyped `format` is still a
  configuration error.
- A copy taken before 0.13.0 still spells these values out as enums, and rejects a new one until you
  refresh it.

The closed sets stay enums: verdicts, priorities, signal levels, a finding's standing against the
baseline, a baseline entry's verdict and the schema number. A new value there needs a new number.

## Validating in CI

Any draft-04 validator does. With [check-jsonschema](https://check-jsonschema.readthedocs.io/):

```bash
composer lockrot --target-php=8.4 --format=json > lockrot.json
check-jsonschema --schemafile https://lockrot.dev/schema/report-1.json lockrot.json
```

Or with the schema pinned next to the workflow, so the check does not depend on lockrot.dev being
up — refresh the copy when you upgrade lockrot, to get new fields listed and a new signal's `data`
typed; a copy from before 0.13.0 also rejects a new signal id, S10 reason or format (see
[Open sets](#open-sets)):

```bash
curl -fsSL -o ci/lockrot-report.schema.json https://lockrot.dev/schema/report-1.json
check-jsonschema --schemafile ci/lockrot-report.schema.json lockrot.json
```

[Ajv](https://ajv.js.org/) needs three things for these files: `ajv-draft-04` for the dialect,
`ajv-formats` for `date-time`, and a word about `x-known-values`, since its default strict mode
refuses a keyword it does not know. Declare it — `ajv.addKeyword({keyword: "x-known-values",
schemaType: "array"})` — or turn strict mode off with `strict: false`.

lockrot's own test suite validates every document its formatters write against these files, with a
strict copy that rejects any field the schema does not list and any value outside `x-known-values`,
and the JSON samples in these docs too — so the published schema, the code and the docs cannot drift
apart.

It also holds the current files to the older ones, in the backward direction: whatever an older
lockrot wrote keeps validating against the newest schema of the same number. Every schema a release
from 0.9.0 on published is kept, and a change that would make a field required, lose a type, an
enum value or a value from `x-known-values`, tighten a bound, stop listing a field or close an object
fails the build against each of them. The baseline files, reports and explanations 0.9.0, 0.10.0
and 0.11.0 wrote, recorded from their signed PHARs and never edited, are validated against the
current files in the same two ways.
A document a newer lockrot writes, checked against a copy of the schema an older release published,
is not what these checks test.

## What the report schema types

Beyond the field list [ci.md](ci.md#-formatjson) gives, the report schema pins down the parts a
consumer usually keys on:

- `verdict`, `priority` and a signal's `level` are enums — the nine verdicts, five priorities and
  three levels from [verdicts.md](verdicts.md).
- `counts` and `priorities` always carry every key, zero included; `abandoned` splits that count
  into `total` and `with_replacement`, and an abandoned finding's `replacement` is a package name or
  null (see [verdicts.md](verdicts.md#abandoned-and-where-to)).
- `run` says what the report is about and what it was decided against — what the project is called
  (composer.json's own `name`, or `extra.lockrot.project` instead; a display name, not an
  identifier, because a monorepo package or a private project is routinely called something that is
  not a `vendor/name`), the target PHP, the thresholds, the `fail-on`, the name of the lock — plus
  `flagged_verdicts`, the verdicts this run counted as findings. It is
  optional in the schema so that documents written before 0.10.0 still validate, and null only
  where nothing filled it in.
- `run.root_package` is what Composer calls the project: the `name` of the manifest it reads,
  exactly as written, whatever `extra.lockrot.project` says — the key to match a report to its
  repository or to join several projects' reports on, where `project` is only a label. Null where
  the manifest has no `name`, or where the run read a lock without its composer.json.
- `run.project_php` is the project's own `require.php`, exactly as that same manifest writes it
  (`>=8.2`, `^7.4 || ^8.0`): a constraint, not a version. It is the second floor S8 holds a higher
  branch against, beside `target_php` (see [verdicts.md](verdicts.md#within-reach)), and the same
  value `--explain` writes as `project_php`. Null where the manifest has no `require.php`, or where
  the run read a lock without its composer.json.
- `run.fail_on_kind` says which kind of threshold `fail_on` is — `none`, `verdict`, `priority` or
  `unchecked` — so a reader need not know which words are verdicts and which are priorities; null
  only where `fail_on` is. `run.strict_network` says whether `--strict-network` was on, and
  `run.mode` what the run was asked to do: `check`, or `generate_baseline` for
  `--generate-baseline`, which records the findings and judges none. `fail_on_kind` and `mode` are
  open strings.
- `root_package`, `project_php`, `fail_on_kind`, `strict_network` and `mode` are the keys in `run`
  a document may omit, because reports written before 0.13.0 do not carry them; from 0.13.0 on
  lockrot always writes all five.
- `gate`, from 0.13.0, is the decision behind the exit code, taken over the document's own findings
  by what `run` says: `fails`, `tripped_by` and `fail_on_applied`. `fails` true means the run exits
  `1`, unless it then fails to write a file or the baseline, in which case it exits `2` and says so
  on stderr. `tripped_by` lists each cause once, `strict_network` (it was on and a lookup failed)
  and `fail_on` (a finding fails), both when both hold, and is empty exactly when `fails` is false;
  it is an open set, and its order is not contract. `fail_on_applied` is false in a
  `generate_baseline` run, which only `--strict-network` can fail. Null where `run` is null or
  carries no `fail_on`, which outside a test is nowhere.
- Each finding carries `gate` beside `baseline`, from 0.13.0: `reaches_fail_on` (at or above
  `run.fail_on`, decided in every mode), `exempt_by` (`baseline` when the baseline accepted a finding
  that reaches, as `known`; a `worsened` one is not exempt; otherwise null; an open set) and `fails`,
  which is exactly `reaches_fail_on`, no exemption and the root `fail_on_applied`; the root's
  `tripped_by` holds `fail_on` exactly when some finding's `fails` is true. Both `gate` keys are
  optional in the schema, so documents written before 0.13.0 validate. See
  [ci.md](ci.md#exit-codes).
- `note_details`, from 0.13.0, is the run's notes typed: one entry per `notes` string, at the same
  index and with the same `text`, each with a `code`, a `data` object typed per code (`{}` for a code
  with no parameters), a `docs_url` to the code's section of [notes.md](notes.md) (null means no
  page), and `sets_network_failures`. The root `network_failures` is true exactly when an entry's
  `sets_network_failures` is. A code can repeat, so an entry is identified by its index. It is
  optional in the schema, so documents written before 0.13.0 validate; from 0.13.0 on it is always
  written, `[]` when `notes` is. The `--explain` document carries the same list beside its `notes`.
  `notes` is unchanged: key on `note_details`, not on the sentences.
- Each finding carries `baseline`, where it stands against the baseline file (`known`, `new` or
  `worsened`, with the verdict the baseline accepted), or null when the run read none. The report's
  own `baseline` block still carries the totals; this is the same judgement per finding, which is
  what a reader filtering for what is new actually needs.
- Each finding carries `from_composer_repository`, true when its lock entry has a Composer
  `notification-url`. Composer writes one only for a package served by a `type: composer`
  repository that advertises a notify URL (`notify-batch` or `notify` in its packages.json), as
  packagist.org and Private Packagist do, and lockrot asks a repository for metadata, advisories and
  repository activity only about such an entry. False is a `path`, `vcs`, `artifact` or inline
  `package` entry, and also a package from a `type: composer` repository that advertises no notify
  URL (asset-packagist.org, a Satis build without `notify-batch`): nothing was asked, so the
  finding has no metadata, advisories, activity or libyears. True does not mean packagist.org. The
  value is the one `--explain` writes as `lock.from_composer_repository`, and the findings where it
  is false are the ones the root `not_from_composer_repository` counts. Added in 0.13.0, optional in
  the schema so earlier documents validate; from 0.13.0 on every finding carries it, true or false,
  never null. It stays a boolean in 1.x: a finer account of where an entry came from would be a
  separate optional field with an open set of values, never a new type for this key.
- A finding can carry `S10`, the signal that says a check did not run: its `data` names each
  missing check, why, and the signals it blocked. Added in 0.11.0; documents written before it
  simply have no such signal, and the id is part of the same schema number.
- S6's `data` says why it fired and whether the package has ever released: `reason`
  (`branch_snapshot` or `no_stable_release`; an open string, so a later reason validates too),
  `has_stable_release` (null, not false, when lockrot loaded no repository metadata for the
  package), `last_stable_release`, `last_stable_version` and `last_stable_dated_by` (the newest
  dated tagged release and the monorepo that dated it, if one did), and `snapshot_time`, the lock's
  commit date for a snapshot. Added in 0.13.0 under the same schema number and optional in the
  schema, so reports written before it still validate. See
  [verdicts.md](verdicts.md#the-signals) for when each is null.
- Each finding carries `libyears` — years behind the package's newest stable release, at least 0,
  or null when not measured — and the document a `libyears` block derived from them: `total`,
  `direct_requirements` (both null when `measured` is 0, since nothing could be measured), `measured`,
  `unmeasured` (four reasons, every key present) and `furthest_behind`. Added in 0.11.0 under the same schema number, and optional in the schema like
  `run`, so reports written before 0.11.0 still validate; a document from 0.11.0 on always carries
  both. See [verdicts.md](verdicts.md#libyears) for the definition and what the number is not.
- From 0.13.0 each finding also carries `libyears_unmeasured`: null when `libyears` is a number, 0
  included, and otherwise the `unmeasured` key the finding is counted under, so counting findings by
  it gives that block key for key. It is an open string, optional in the schema so earlier reports
  validate, and always present from 0.13.0 on; a document without it predates the field, and its
  absence never means "measured". The `unmeasured` block's keys grow the same way: a key a later
  release adds is typed as a count and never joins `required`.
- From 0.13.0 each finding carries `priority_basis`, how its `priority` was reached: `base`, the
  level the verdict starts at (`none` for `unknown`, `finished` and `ok`), and `steps`, each
  `{reason, from, to}` in the order applied — `transitive`, or `unreached` when `chain` is empty,
  exactly when `direct` is false; then `dev`; then `no_fix_expected`. A step is recorded whenever
  its fact holds, also when it cannot move the level (`from` equals `to`: a development package
  already at `low`, a raise at `critical`). The first step starts at `base`, each later one where
  the last ended, and the last ends at `priority`; with no steps `priority` is `base`. `from` and
  `to` are the priority order without `none`, and a step's `reason` is an open string, so a step a
  later release adds still reads as a move from one level to another. See
  [verdicts.md](verdicts.md#priority).
- From 0.13.0 each finding also carries `no_fix_expected`, the advisories lockrot expects no fix
  for, each `{id, reason}` in S9's order. It has four states: absent, a document written before
  0.13.0; null, the verdict makes no fix prediction (every verdict but `abandoned`, `silent` and
  `left-behind`); `[]`, one of those three with every advisory fixed by a release it lets the project
  reach, or no advisory at all; and a non-empty list, the advisories that raise the priority and put
  `no fix expected` in the evidence. `id` is the `id` of one of the finding's S9 rows. `reason` is
  the first that applies, an open string: `not_on_installed_branch` (left-behind, fixed only on a
  higher branch), `releases_unknown` (S9's `releases_read` is false, so no fix was looked for),
  `affected_range_unknown` (the advisory gives no affected range) or `no_release_fixes`. S9's `data`
  gains `releases_read`: true when the releases were read and the installed version compared with
  them, so a null `fixed_by` means none fixes it; false when there was no metadata or the installed
  version is not one lockrot can compare. Both are optional in the schema, so earlier documents
  validate. See [verdicts.md](verdicts.md#security-advisories).
- `exposure` lists a direct requirement only when it pulls in an attributable flagged package; a
  flagged direct requirement that pulls in none is not there, and its own verdict is on its finding.
  `exposure_rule` states the cap the report attributes by (`max_fan_in`, the most direct
  requirements a flagged transitive package may be reached from and still count under each), and
  `unattributed` lists the flagged transitive packages above it — `package`, `verdict` (the same
  enum) and `fan_in`, the number of the run's direct requirements that reach it, `require-dev`
  included under `--dev` — in report order: they count in no `exposure` entry and no S7. A flagged
  package no direct requirement reaches (an empty `chain`, as in a run without `composer.json`) is
  in neither. Added in 0.13.0 and optional in the schema like `run`, so documents written before
  0.13.0 still validate; a document from 0.13.0 on always carries both. See
  [verdicts.md](verdicts.md#transitive-exposure).
- Each signal's `data` is typed per signal id (`S1` … `S10`): a signal claiming `S2` with `S4`'s
  fields does not validate. A signal whose id the schema does not list carries any object (see
  [Open sets](#open-sets)).
- Dates are RFC 3339 strings (`format: date-time`); `ga_date` in S5 and `first_seen` in the
  baseline are plain `YYYY-MM-DD`.

## Related

- [ci.md](ci.md) — the seven output formats
- [baseline.md](baseline.md) — the baseline file
- [configuration.md](configuration.md) — `extra.lockrot`
- [compatibility.md](compatibility.md) — what 1.0 freezes beyond the schemas (draft)
