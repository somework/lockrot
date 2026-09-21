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
        "version": "0.10.0",
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
`additionalProperties: false`), so a report from a newer lockrot validates against the copy of the
schema you fetched or vendored earlier, and a field your CI step does not know about is not an
error. The number moves only when a field is removed or renamed, and then the old file stays
published at its old URL.

The schema files themselves are edited in place when a field is added, so the copy at the URL always
describes the newest release under that number. The version that added a field is in the
[changelog](changelog.md).

## Validating in CI

Any draft-04 validator does. With [check-jsonschema](https://check-jsonschema.readthedocs.io/):

```bash
composer lockrot --target-php=8.4 --format=json > lockrot.json
check-jsonschema --schemafile https://lockrot.dev/schema/report-1.json lockrot.json
```

Or with the schema pinned next to the workflow, so the check does not depend on lockrot.dev being
up:

```bash
curl -fsSL -o ci/lockrot-report.schema.json https://lockrot.dev/schema/report-1.json
check-jsonschema --schemafile ci/lockrot-report.schema.json lockrot.json
```

lockrot's own test suite validates every document its formatters write against these files, with a
strict copy that rejects any field the schema does not list, and the JSON samples in these docs
too — so the published schema, the code and the docs cannot drift apart.

## What the report schema types

Beyond the field list [ci.md](ci.md#-formatjson) gives, the report schema pins down the parts a
consumer usually keys on:

- `verdict`, `priority` and a signal's `level` are enums — the nine verdicts, five priorities and
  three levels from [verdicts.md](verdicts.md).
- `counts` and `priorities` always carry every key, zero included.
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
- Each signal's `data` is typed per signal id (`S1` … `S9`): a signal claiming `S2` with `S4`'s
  fields does not validate.
- Dates are RFC 3339 strings (`format: date-time`); `ga_date` in S5 and `first_seen` in the
  baseline are plain `YYYY-MM-DD`.

## Related

- [ci.md](ci.md) — the six output formats
- [baseline.md](baseline.md) — the baseline file
- [configuration.md](configuration.md) — `extra.lockrot`
