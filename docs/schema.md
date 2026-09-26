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

Objects are open, and so are the sets of values that grow in minor releases: signal ids (a
signal's `id` and S10's `blocks`), S10's `check` and `reason`, S8's `floor_source`, and the
configuration schema's `format`. Each is a string with a `pattern`, plus an `x-known-values` list of
the values this release writes. A validator ignores a keyword draft-04 does not define, so a copy of
the schema taken from 0.13.0 on accepts a signal, a reason, a floor or a format that a later release
adds, and a signal or format named `<vendor>:<name>`, the form reserved for those that do not come
from lockrot ([compatibility.md](compatibility.md#names-reserved-for-extensions)). The vendor and
the name are each lower-case letters, digits, `_`, `.` and `-`, starting with a letter or a digit;
`acme:licence` validates, `Acme:Licence` does not.

- A signal whose id the schema does not list validates with any object as its `data`. A listed id
  still has its `data` typed: S2's id with S4's data fails, and so does S2's id with no data at all.
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
- Each finding carries `baseline`, where it stands against the baseline file (`known`, `new` or
  `worsened`, with the verdict the baseline accepted), or null when the run read none. The report's
  own `baseline` block still carries the totals; this is the same judgement per finding, which is
  what a reader filtering for what is new actually needs.
- A finding can carry `S10`, the signal that says a check did not run: its `data` names each
  missing check, why, and the signals it blocked. Added in 0.11.0; documents written before it
  simply have no such signal, and the id is part of the same schema number.
- Each finding carries `libyears` — years behind the package's newest stable release, at least 0,
  or null when not measured — and the document a `libyears` block derived from them: `total`,
  `direct_requirements` (both null when `measured` is 0, since nothing could be measured), `measured`,
  `unmeasured` (four reasons, every key present) and `furthest_behind`. Added in 0.11.0 under the same schema number, and optional in the schema like
  `run`, so reports written before 0.11.0 still validate; a document from 0.11.0 on always carries
  both. See [verdicts.md](verdicts.md#libyears) for the definition and what the number is not.
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
