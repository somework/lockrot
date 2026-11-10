# tools/schema

These scripts write the schemas of the schema number 2 documents and their negative fixtures. Every
`resources/lockrot-*-2.schema.json` file is generated: edit its builder, rebuild, and commit the
builder and its output together. CI runs the commands under "Commands" and fails when the result
differs from the committed files (`.github/workflows/ci.yml`, the step "The generated schemas and
negatives match tools/schema"). The `-1` files are not generated. `CONTRIBUTING.md`, "Changing a schema", holds
their checklist.

The Python scripts use the standard library only. `hydrated-divergences` is a PHP script that reads the vendor autoloader.

## Commands

Run each command from the repository root, in this order:

```sh
python3 tools/schema/build_report2_schema.py
python3 tools/schema/build_other_schemas.py
python3 tools/schema/make_negatives.py
python3 tools/schema/make_negatives_other.py explain-2
python3 tools/schema/make_negatives_other.py baseline-2
python3 tools/schema/make_negatives_other.py config-2
python3 tools/schema/build_sarif_properties_schema.py
python3 tools/schema/make_negatives_sarif.py
python3 tools/schema/ts/emit_dts.py
tools/report/record
(cd tools/schema/node && npm ci --ignore-scripts && node emit_bundle.js)
```

The last three commands rebuild what the schemas and the types feed. `ts/emit_dts.py` writes
`types/lockrot-report-2.known.d.ts`, `tools/schema/ts/known-map.json` and
`tools/schema/ts/probes.generated.ts`. `tools/report/record` rewrites `tests/fixtures/contract/`,
including `contract-files.json`, which holds the sha256 of the schemas and the types, so run it
after `emit_dts.py`. `node emit_bundle.js` reads the recorded `tests/fixtures/contract/report-2/` and
writes `tests/fixtures/bundle.generated.ts`, so run it after `tools/report/record`. The `typescript`
job of `.github/workflows/ci.yml` runs `emit_dts.py` and `emit_bundle.js` and fails on a diff, and
`ContractBundleIsCurrentTest` fails on a stale `contract-files.json`.

| Script | Reads | Writes |
|---|---|---|
| `build_report2_schema.py` | `inputs/` | `resources/lockrot-report-2.schema.json` |
| `build_other_schemas.py` | `inputs/`, `resources/lockrot-report-2.schema.json` | `resources/lockrot-explain-2.schema.json`, `lockrot-baseline-2.schema.json`, `lockrot-config-2.schema.json` |
| `make_negatives.py` | `tests/fixtures/schema/documents/cases/` | `tests/fixtures/schema/negative/report-2/` |
| `make_negatives_other.py explain-2` | `tests/fixtures/schema/documents/explain/B.json` | `tests/fixtures/schema/negative/explain-2/` |
| `make_negatives_other.py baseline-2` | `tests/fixtures/schema/documents/baseline/corpus/matomo-v4.json` | `tests/fixtures/schema/negative/baseline-2/` |
| `make_negatives_other.py config-2` | none: each fixture is written whole | `tests/fixtures/schema/negative/config-2/` |
| `build_sarif_properties_schema.py` | `resources/lockrot-report-2.schema.json` | `tests/fixtures/sarif/lockrot-sarif-properties-2.schema.json` |
| `make_negatives_sarif.py` | `tests/fixtures/sarif/documents/B.sarif.json` | `tests/fixtures/sarif/negative/` |
| `ts/emit_dts.py` | `resources/lockrot-report-2.schema.json` | `types/lockrot-report-2.known.d.ts`, `ts/known-map.json`, `ts/probes.generated.ts` |
| `node/emit_bundle.js` | `tests/fixtures/contract/report-2/` | `tests/fixtures/bundle.generated.ts` |
| `project_fixtures.py` | the reference model's `cases.json`, `docs/` and `page-goldens.json` | `tests/fixtures/flags/`, `tests/fixtures/schema/documents/`, `tests/fixtures/contract/page-goldens.json` |
| `hydrated-divergences` | `tests/fixtures/flags/cases.json`, the recorded `tests/fixtures/contract/report-2/` and `explain-2/`; with `--cases <file>`, the cases of that file, hydrated in the run at the `generated_at` of the case documents of that tree. A case without a document goes to standard error | standard output: each field that `HydratedCasesMatchTheModelTest` compares, where a recording differs from the model and `DIFFERENCES` does not list it |

`ship_text.py` is the shipping pass that the builders apply to every description: it removes the
references a published description must not hold. A unit test applies its expression `SHIP_BANNED`
to every generated file (`CONTRIBUTING.md`, "Changing a schema").

`inherited-descriptions.json` lists each description that a builder copies from `inputs/` and that
holds for schema number 2. A test fails on a copy that the list does not name. If it fails, read
the copied text against the number 2 document. Then list its pointer, or give the node a new
description.

## The pinned inputs

`inputs/` holds the lockrot 0.13.0 report, explain and config schema files. `inputs/SHA256SUMS.json`
holds their sha256, and the builders refuse an input whose hash differs. The builders copy the
definitions that schema number 2 keeps unchanged from these files, never from `resources/`, so a
change of a `-1` file cannot change a `-2` file.

## The negative fixtures

Each negative fixture is a valid document with one planted defect. A report-2 fixture carries its
expected error in a root `$expect` object. An explain-2 fixture has its entry in `EXPECT.json`, and a
file name that ends in `.strict.json` is read against the strict twin. The conformance test
validates each fixture and needs the error that it names.

## Relations that draft-04 cannot state

The activity relations below compare values across objects or across the document. The schema holds
their shape only. The strict run of report-2 (I21), which pull request 6b of the 0.14 train adds,
checks them.

| Id | Relation |
|---|---|
| A5 | An activity that was not read has exactly one `repository_activity` entry, with the activity's `reason` and the blocks S3 and S4 |
| A7 | When S3 fired, its `forge`, `host` and `repo` equal the activity's |
| A8 | When S4 fired, its `forge` and `event` equal the activity's |
| A9 | The root `activity_cache_oldest_at` is the oldest `findings[].activity.cached_at`, or null |
| A10 | The note `repository_activity_host_not_supported` exists exactly when a finding has that reason, and its counts are the findings' counts |

## The projected fixtures

`project_fixtures.py` takes the pull request id of the 0.14 train and the directory of the reference
model:

```sh
python3 tools/schema/project_fixtures.py --pr 4b --source <model directory> --run-date 2026-10-08 --suite '<result>'
```

The model writes every field of 0.14. The script drops each key that a later pull request writes. It
reverts each value that a later pull request changes. So the fixtures carry what the draft of that
pull request holds. `tests/fixtures/flags/provenance.json` lists the source hash, the model run date,
the suite result, every dropped key and every reverted value. The base documents of the negative
fixtures come from the same projection. At the last pull request of the train, the script writes
every model document unprojected. It corrects a model defect that a relation refuses, and
`tests/fixtures/schema/documents/provenance.json` lists each correction by document. CI does not run this script: its input is not in the
repository.

## A second validator

`JsonSchemaConformanceTest` validates with justinrainbow/json-schema, the validator lockrot ships. A
second validator catches a keyword that one library reads otherwise, such as a `pattern` dialect.
For example, with `check-jsonschema`:

```sh
check-jsonschema --schemafile resources/lockrot-report-2.schema.json tests/fixtures/schema/documents/cases/*.json
```
