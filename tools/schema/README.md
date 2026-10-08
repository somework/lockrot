# tools/schema

These scripts write the schemas of the schema number 2 documents and their negative fixtures. Every
`resources/lockrot-*-2.schema.json` file is generated: edit its builder, rebuild, and commit the
builder and its output together. CI runs the commands under "Commands" and fails when the result
differs from the committed files (`.github/workflows/ci.yml`, the step "The generated schemas and
negatives match tools/schema"). The `-1` files are not generated. `CONTRIBUTING.md`, "Changing a schema", holds
their checklist.

The scripts use the Python standard library only.

## Commands

Run each command from the repository root, in this order:

```sh
python3 tools/schema/build_report2_schema.py
python3 tools/schema/build_other_schemas.py
python3 tools/schema/make_negatives.py
python3 tools/schema/make_negatives_other.py explain-2
```

| Script | Reads | Writes |
|---|---|---|
| `build_report2_schema.py` | `inputs/` | `resources/lockrot-report-2.schema.json` |
| `build_other_schemas.py` | `inputs/`, `resources/lockrot-report-2.schema.json` | `resources/lockrot-explain-2.schema.json` |
| `make_negatives.py` | `tests/fixtures/schema/documents/cases/` | `tests/fixtures/schema/negative/report-2/` |
| `make_negatives_other.py explain-2` | `tests/fixtures/schema/documents/explain/B.json` | `tests/fixtures/schema/negative/explain-2/` |
| `ts/emit_dts.py` | `resources/lockrot-report-2.schema.json` | `types/` |
| `project_fixtures.py` | the reference model's `cases.json` and `docs/` | `tests/fixtures/flags/`, `tests/fixtures/schema/documents/` |

`ship_text.py` is the shipping pass that both builders apply to every description: it removes the
references a published description must not hold. `tests/Unit/Json/SchemaDescriptionsTest.php`
runs its expression `SHIP_BANNED` over every generated file.

## The pinned inputs

`inputs/` holds the lockrot 0.13.0 report, explain and config schema files. `inputs/SHA256SUMS.json`
holds their sha256, and the builders refuse an input whose hash differs. The builders copy the
definitions that schema number 2 keeps unchanged from these files, never from `resources/`, so a
change of a `-1` file cannot change a `-2` file.

## The negative fixtures

Each negative fixture is a valid document with one planted defect. A report-2 fixture carries its
expected error in a root `$expect` object. An explain-2 fixture has its entry in `EXPECT.json`, and a
file name that ends in `.strict.json` is read against the strict twin.
`JsonSchemaConformanceTest::testEveryNegativeFixtureIsRejectedWithTheErrorItNames` validates each one
and needs the error that it names.

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
fixtures come from the same projection. CI does not run this script: its input is not in the
repository.

## A second validator

`JsonSchemaConformanceTest` validates with justinrainbow/json-schema, the validator lockrot ships. A
second validator catches a keyword that one library reads otherwise, such as a `pattern` dialect.
For example, with `check-jsonschema`:

```sh
check-jsonschema --schemafile resources/lockrot-report-2.schema.json tests/fixtures/schema/documents/cases/*.json
```
