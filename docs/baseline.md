---
title: Baseline — fail CI only on new dependency rot
description: Record the findings you accept in lockrot-baseline.json with --generate-baseline, commit it, and CI fails only on findings that are new or have got worse.
---

# Baseline {#baseline}

Record the findings you have decided to live with, commit the file, and CI fails only on what is
**new** or has got **worse**:

```bash
composer lockrot --target-php=8.4 --generate-baseline
git add lockrot-baseline.json
git commit -m "chore: accept current dependency rot"
```

Generate it with the options that your CI step uses, or the step can fail on accepted findings
([the options to match](#generate-it-with-the-same-dev-setting-your-ci-run-uses)).

Every later run (plugin or PHAR) reads `lockrot-baseline.json`, or the file `--baseline` or the
`baseline` key names, and sorts each finding into one bucket:

| Bucket | The finding | Fails the build |
|---|---|---|
| `new` | is graded and not in the baseline | When it reaches `--fail-on`, as without a baseline |
| `worsened` | is in the baseline, and a fact is new: a flag that the entry does not cover, an advisory that it does not hold, an advisory rated higher, or a fix lost | When the new facts reach `--fail-on` ([the gate score](#the-gate-score)) |
| `known` | is in the baseline, and every fact is covered | Never |
| `stale` (not the `stale` flag) | is a baseline entry for a package that is not in `composer.lock` | Never. The output reports it as a note (in `json`: `baseline.stale`) |

From the run of a recorded project with a baseline, abridged:

```text
  javibravo/simpleue 2.1.0  direct  ·  silent (baseline) · old-promise (baseline)
      36 = silent 32 + old-promise 4 [¼ of 16] (baseline: known)
      gate 0 = min(36, 2 × 0) (the new facts bound it); new: 0
  mnapoli/piwik-twig-extension 3.0.0  direct  ·  silent · old-promise (baseline)
      36 = silent 32 + old-promise 4 [¼ of 16] (baseline: silent new)
      gate 36 = min(36, 2 × 32) (the score is lower); new: 32 = silent 32
…
baseline: 45 known · 25 new · 2 worsened · 9 stale (lockrot-baseline.json)
gate: fails · --fail-on=high · 11 findings fail it; 30 reach it and are known to the baseline ·
target php 8.4 (--target-php)
```

## <a name="what-generate-baseline-does"></a>What `--generate-baseline` does

- Writes `lockrot-baseline.json` next to `composer.json`, or the path in `--baseline` or
  [`extra.lockrot.baseline`](configuration.md#extralockrot-keys), relative to `composer.json` or
  absolute. Under `COMPOSER=app/alt.json` that is next to `alt.json`, in `app/`
  ([environment overrides](configuration.md#environment-overrides)).
- Records every graded finding of the run: its counted maintenance flags and its counted
  advisories, each with the date it became known. A score-0 finding gets no entry, except the entry
  that an incomplete lookup carries forward. An accepted flag is never recorded.
- Replaces the file: a package this run does not grade loses its entry, unless an incomplete lookup
  carries the entry forward. A package that stays keeps its `first_seen`, and each fact keeps its
  `known_since`. Each advisory is written with its current severity and fix kind, so a regenerated
  file accepts a rating or a fix that changed.
- Over a baseline-1 file, writes a new baseline-2 file from this run. It reads nothing from the old
  file, so every date starts again on the day of the run.
- Never leaves a truncated baseline, even when interrupted.
- Prints nothing on stdout. On stderr, it ends with
  `lockrot: baseline written to lockrot-baseline.json (<count> findings)`.
- Exits `0` whatever `--fail-on` says. With `--strict-network`, it still exits `1`, after it writes
  the file, when a [run note](notes.md) counts as a network failure
  ([exit codes](ci.md#exit-codes)).
- With `--output` on the same run, writes the reports first and the baseline last. When the run
  reads a baseline-2 file, the reports compare with it before lockrot rewrites it, as a check run
  does. A report that lockrot cannot write (exit `2`) leaves the baseline unchanged.

```json
{
    "$schema": "https://lockrot.dev/schema/baseline-2.json",
    "lockrot": {
        "version": "x.y.z",
        "schema": 2
    },
    "generated_at": "2026-09-14T00:00:00+00:00",
    "target_php": "8.4",
    "target_php_source": "option",
    "score_model_id": 1,
    "fix_model": 1,
    "findings": {
        "behat/transliterator": {
            "version": "v1.5.0",
            "first_seen": "2026-09-14",
            "flags": {
                "abandoned": {
                    "known_since": "2026-09-14"
                }
            }
        }
    }
}
```

| Field | Meaning |
|---|---|
| `$schema` | The file's published [JSON schema](schema.md). lockrot validates every baseline it reads against it |
| `lockrot.version` | The lockrot release that wrote the file |
| `lockrot.schema` | The file's schema number |
| `generated_at` | The timestamp of the run that wrote it |
| `target_php`, `target_php_source` | The target PHP the file was written for. lockrot compares fixes only when the run targets the same PHP |
| `score_model_id`, `fix_model` | The score model and the fix model of the run that wrote it |
| `findings` | One entry per package, sorted by name so the diff stays reviewable |
| `findings.<package>.version` | The installed version when lockrot wrote the entry. Matching ignores it |
| `findings.<package>.flags` | The counted maintenance flags, each with `known_since`. The first one in flag order covers every flag after it |
| `findings.<package>.advisories` | The counted advisories by id, each with `known_since`, `severity` and `fix_kind` |
| `findings.<package>.first_seen` | The date the package first entered the baseline, kept across regenerations |

## Accept a new finding or drop a fixed one {#accept-a-new-finding-or-drop-a-fixed-one}

Regenerating accepts every current finding at once, so review what it changes:

1. Rerun the [generate command](#baseline).

2. Review `git diff lockrot-baseline.json`. An added entry, flag or advisory is a fact you accept,
   and a changed `severity` or `fix_kind` is a rating or a fix you accept. A removed entry is a
   package this run does not grade: fixed, allowlisted, gone from `composer.lock`, or `unknown`
   because a lookup failed (the run's [notes](notes.md) say so).

3. Commit the file with the change that needs it.

On a merge conflict in the file, take either side and regenerate on the merged branch. Entries on
the side you took keep their `first_seen` dates.

## Reading a baseline

A run with a baseline adds one line to the `table` and `markdown` output:

```text
baseline: <known> known · <new> new · <worsened> worsened · <stale> stale (lockrot-baseline.json)
```

Matching rules:

- **By package name only.** A bump of `vendor/pkg` from `1.2.3` to `1.3.0` keeps it `known` while
  its facts stay covered.
- **A recorded flag covers itself and every flag after it in flag order.** So a flag that ageing
  adds below the recorded ones stays `known`: `old-promise` recorded, then `stale`, or `stale`
  recorded, then `capped`. A flag above
  them is new: `old-promise` recorded, then `abandoned`. A move between `warn` and `high` within
  one flag is not new.
- **An advisory is known** while the entry holds its id, its severity is not higher (by gate rank,
  so `unrated` and `medium` are equal), and its fix is not lost (from `update`, `upgrade`,
  `raise-php` or `unknown` to `blocked` or `none`).
- **Fixes are compared only under the same target PHP and fix model.** Otherwise lockrot skips
  the fix comparison and says so in a note
  ([`baseline_target_differs`](notes.md#baseline_target_differs),
  [`baseline_fix_model_differs`](notes.md#baseline_fix_model_differs)).
- **Whichever value.** A `known` finding fails no `--fail-on` value, `unchecked` included. A
  `worsened` finding fails `unchecked` on whatever S10 it carries.
- **Graded findings only.** A baseline never covers an `ok`, `finished` or `unknown` finding, so
  one that carries [S10](verdicts.md#what-was-not-checked) still fails `--fail-on=unchecked`
  however often you regenerate.
- **Stale entries stay.** A normal run never edits the file. Regenerate the file to drop them.

### The gate score

A baseline does not lower a finding's score: its verdict stays the full grade. The grade values of
`--fail-on` read a gate score instead:

```text
gate score = min(score, 2 × new)
```

`new` is what the facts that the baseline does not cover add, by the rules of
[the score](verdicts.md#score). So a new fact sets the level: a new medium advisory on a package
that scores 36 gives a gate score of 16, and fails `--fail-on=high`. A `known` finding has a gate
score of 0. The table prints the gate line under the score line, and the json report writes
`gate.basis` ([Score model 1](verdicts/model-1.md#score-gate-bounded-new)).

`flag` and `vulnerable` values read the facts directly: an uncovered flag at or above a flag value,
or an advisory that the baseline does not know at or above a `vulnerable` value.

How each format shows the comparison:

| Format | `known` | `worsened` |
|---|---|---|
| `table`, `markdown` | `(baseline)` after each covered flag, `(baseline: known)` after the score line, and the gate line | `(baseline: silent new)` or `(baseline: A re-rated medium → high)`: the new facts |
| `github`, `sarif`, `gitlab` | Each record of a covered fact is marked known ([How each format marks a finding](ci.md#how-each-format-marks-a-finding)). SARIF adds `suppressions` | A record of a new fact is marked as without a baseline |
| `json` | `baseline.status` is `known`. When the finding reaches `--fail-on`, `gate.exempt_by` is `baseline` ([schema.md](schema.md#what-the-report-schema-types)) | `baseline.status` is `worsened`, `baseline.worsened_by[]` names each new fact and why, and `baseline.recorded` holds what the entry recorded (`recorded.lead` for its first flag) |

## Generate it with the options your CI step uses {#generate-it-with-the-same-dev-setting-your-ci-run-uses}

Any option that changes verdicts must match between the generate run and the CI step, or the step
reports accepted findings as `new` or `worsened`:

- **`--dev`.** If you generate the baseline without `--dev` and the CI step passes it, the baseline
  holds no development findings. The `--dev` run reports each of them as `new` and can fail. The
  reverse is safe: lockrot measures `stale` entries against the whole `composer.lock`,
  `packages-dev` included.

- **The target PHP.** Pass the same `--target-php` or `LOCKROT_TARGET_PHP`, or set the `target-php`
  key so both runs read it. Without any of them, lockrot uses `config.platform.php`, else the PHP
  that runs it, and that PHP can differ between your machine and CI
  ([`target-php`](configuration.md#extralockrot-keys)).

- **Credentials and network.** Generate with the token the CI step has (`GITHUB_TOKEN`, …) and
  not `--offline`. Without it, checks that the flags rest on do not run, the baseline records fewer
  facts, and CI reports those packages as `new` or `worsened`
  ([repository hosts](internals.md#repository-hosts-and-credentials)). A `--generate-baseline`
  run whose advisory lookup is incomplete carries each recorded entry forward, and says so on
  stderr.

- **The manifest.** Run with the same `COMPOSER` value, so both runs read the same lock.

A change to a threshold key in `extra.lockrot` changes flags too: regenerate the baseline in the
same change.

## When lockrot cannot read the file

A baseline that lockrot cannot read is a configuration error, never an absent baseline: the run
exits `2` and does not run ungated. A default `lockrot-baseline.json` that does not exist is not an
error. That run has no baseline.

| Symptom on stderr | Cause | Fix |
|---|---|---|
| `lockrot: <path> not found` | `--baseline` or `extra.lockrot.baseline` names a file that does not exist | Fix the path, or create the file with `--generate-baseline` |
| `lockrot: <path> is not valid JSON: …` or `… must contain a JSON object` | The file is damaged, for example by a merge conflict | Restore it from version control, or delete it and generate a new one |
| `lockrot: baseline file is invalid:` and one `  - <field>: <message>` line per problem | The file does not match the [schema](schema.md): a field of the wrong type or a missing key | Fix the named field, or delete the file and generate a new one |
| `lockrot: Cannot read <path>: …` | The file exists, but lockrot cannot read it | Fix its permissions |
| `lockrot: lockrot <major>.<minor> reads baseline-2 only. Review the findings, then run --generate-baseline.`, such as `lockrot: lockrot 0.14 reads …` | The file is a baseline-1 file, written by lockrot 0.13 or earlier | [Move to baseline-2](#moving-from-a-baseline-1-file) |

`--generate-baseline` reads an existing file to keep its `first_seen` dates, so it stops on a
damaged file too. Delete the file first. A baseline-1 file is the exception: `--generate-baseline`
overwrites it.

<a id="a-baseline-1-file"></a>

### Moving from a baseline-1 file

lockrot reads the default baseline file on every run, and no option skips it, so every check run
exits `2` until the file is replaced. Review the findings with the file moved aside, then write a
new one:

```bash
git mv lockrot-baseline.json lockrot-baseline.0.13.json
composer lockrot --target-php=8.4
composer lockrot --target-php=8.4 --generate-baseline
git add lockrot-baseline.json
git rm lockrot-baseline.0.13.json
git commit -m "chore: regenerate the lockrot baseline as baseline-2"
```

If `extra.lockrot.baseline` names the file, move the file at that path. A run without the file that
the key names exits `2` with `lockrot: <path> not found`, so remove the key for the review run. Put
the key back before `--generate-baseline`, which writes the path that the key names. The same
commands work with the PHAR (`php lockrot.phar`). The new file starts every `first_seen` and `known_since` again on the day
of the run. [Upgrading to 0.14](changes/0.14.md) has the steps for the action and the plugin.

## Install time

`install-time-strict` uses the same comparison: a `known` finding does not stop a
`composer require`, and the compact block still lists it ([install-time.md](install-time.md)).

At install time, a baseline problem that `composer lockrot` reports as exit `2` skips the check
and does not fail the install
([When the install continues and when it stops](install-time.md#never-fails-the-install)). A
baseline-1 file is such a problem, under `install-time-strict` too, until you run
`--generate-baseline`.

## Related

- [configuration.md](configuration.md#extralockrot-keys) — the `baseline` key and `--baseline=<path>`
- [ci.md](ci.md#exit-codes) — the exit codes a gated run returns
- [ci.md](ci.md#how-each-format-marks-a-finding) — how each format marks a `known` finding
- [schema.md](schema.md) — the published baseline schema and the report's `baseline` fields
- [install-time.md](install-time.md) — the compact block and `install-time-strict`
