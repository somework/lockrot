---
title: lockrot baseline — fail CI only on new dependency rot
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

Run it with the options your CI step uses (`--dev`, `--target-php`, any `LOCKROT_*` override, the
same `COMPOSER` manifest, the same token), or the step can fail on findings the baseline was meant
to accept ([below](#generate-it-with-the-same-dev-setting-your-ci-run-uses)).

Every later run (plugin or PHAR) reads the baseline file (`lockrot-baseline.json`, or the
`baseline` key's path) without a flag and sorts each finding into one bucket:

| Bucket | The finding | Fails the build |
|---|---|---|
| `new` | is flagged and not in the baseline | When it reaches `--fail-on`, as without a baseline |
| `worsened` | is in the baseline at a less severe verdict than its current one | When it reaches `--fail-on` |
| `known` | is in the baseline at its current verdict or a more severe one | Never |
| `stale` (not the `stale` verdict) | is a baseline entry for a package that is not in `composer.lock` | Never; reported as a note |

## What `--generate-baseline` does

- Writes `lockrot-baseline.json` next to `composer.json`, or the path in `--baseline` or
  [`extra.lockrot.baseline`](configuration.md#extralockrot-keys), relative to `composer.json` or
  absolute. Under `COMPOSER=app/alt.json` that is next to `alt.json`, in `app/`
  ([environment overrides](configuration.md#environment-overrides)).
- Records every flagged finding of the run. `ok`, `finished` and `unknown` findings are not
  recorded.
- Replaces the file: a package this run does not flag loses its entry, and a package that stays
  keeps its `first_seen`.
- Never leaves a truncated baseline, even when interrupted.
- Prints nothing on stdout; on stderr it ends with
  `lockrot: baseline written to lockrot-baseline.json (<count> findings)`.
- Exits `0` whatever `--fail-on` says. With `--strict-network`, it still exits `1`, after the file
  is written, when a [run note](notes.md) counts as a network failure
  ([exit codes](ci.md#exit-codes)).
- With `--output` on the same run, writes the reports first and the baseline last. The reports carry
  no baseline comparison, and a report that cannot be written (exit `2`) leaves the baseline as it
  was.

```json
{
    "$schema": "https://lockrot.dev/schema/baseline-1.json",
    "lockrot": {
        "version": "x.y.z",
        "schema": 1
    },
    "generated_at": "2026-09-14T00:00:00+00:00",
    "findings": {
        "behat/transliterator": {
            "version": "v1.5.0",
            "verdict": "abandoned",
            "first_seen": "2026-09-14"
        }
    }
}
```

| Field | Meaning |
|---|---|
| `$schema` | The file's published [JSON schema](schema.md); lockrot validates every baseline it reads against it |
| `lockrot.version` | The lockrot release that wrote the file |
| `lockrot.schema` | The file's schema number |
| `generated_at` | The timestamp of the run that wrote it |
| `findings` | One entry per package, sorted by name so the diff stays reviewable |
| `findings.<package>.version` | The version installed when the entry was written; informational, not matched |
| `findings.<package>.verdict` | The verdict accepted |
| `findings.<package>.first_seen` | The date the package first entered the baseline, kept across regenerations |

## Accept a new finding or drop a fixed one {#accept-a-new-finding-or-drop-a-fixed-one}

Regenerating accepts every current finding at once, so review what it changes:

1. Rerun the [generate command](#baseline).

2. Review `git diff lockrot-baseline.json`. An added entry is a finding you accept, a changed
   `verdict` is the verdict you accept. A removed entry is a package this run does not flag: fixed,
   allowlisted, gone from `composer.lock`, or `unknown` because a lookup failed (the run's
   [notes](notes.md) say so).

3. Commit the file with the change that needs it.

On a merge conflict in the file, take either side and regenerate on the merged branch. Entries on
the side you took keep their `first_seen` dates.

## Reading a baseline

A run with a baseline adds one line to the `table` and `markdown` output:

```text
baseline: <known> known · <new> new · <worsened> worsened · <stale> stale (lockrot-baseline.json)
```

Matching rules:

- **By package name only.** Bumping `vendor/pkg` from `1.2.3` to `1.3.0` while it stays `abandoned`
  keeps it `known`.
- **Compared by verdict, not priority.** A finding that moves up the severity ladder (from the
  `stale` verdict to `abandoned`) is `worsened` and can fail the build again. A change of
  [priority](verdicts.md#priority) alone, such as a package moving from `require-dev` to `require`,
  keeps it `known`.
- **Whichever threshold.** A `known` finding does not fail the run under a verdict, a priority or
  `unchecked` threshold.
- **Flagged findings only.** A baseline never covers an `ok`, `finished` or `unknown` finding, so
  one that carries [S10](verdicts.md#what-was-not-checked) still fails `--fail-on=unchecked`
  however often you regenerate.
- **Stale entries stay.** A normal run never edits the file; regenerate it to drop them.

How each format shows the comparison:

| Format | `known` | `worsened` |
|---|---|---|
| `table` | `abandoned (baseline)` | `abandoned (was stale)`, naming the accepted verdict |
| `github`, `sarif`, `gitlab`, `markdown` | Marked by row 1 of [How each format marks a finding](ci.md#how-each-format-marks-a-finding) | Marked like a new finding |
| `json` | `baseline.status` is `known`; when the finding reaches `--fail-on`, `gate.exempt_by` is `baseline` ([schema.md](schema.md#what-the-report-schema-types)) | `baseline.status` is `worsened` and `baseline.previous_verdict` names the accepted verdict; `gate.exempt_by` is null |

## Generate it with the options your CI step uses {#generate-it-with-the-same-dev-setting-your-ci-run-uses}

Any option that changes verdicts must match between the generate run and the CI step, or the step
reports accepted findings as `new` or `worsened`:

- **`--dev`.** Generated without it and checked with it, the baseline holds no development findings,
  so the `--dev` run reports every one of them as `new` and can fail. The reverse is safe: `stale`
  entries are measured against the whole `composer.lock`, `packages-dev` included.

- **The target PHP.** Pass the same `--target-php` or `LOCKROT_TARGET_PHP`, or set the `target-php`
  key so both runs read it. Without any of them, lockrot uses `config.platform.php`, else the PHP
  that runs it, which can differ between your machine and CI
  ([`target-php`](configuration.md#extralockrot-keys)).

- **Credentials and network.** Generate with the token the CI step has (`GITHUB_TOKEN`, …) and
  not `--offline`. Without it, checks a verdict rests on do not run, the baseline records weaker
  verdicts, and CI reports those packages as `new` or `worsened`
  ([repository hosts](internals.md#repository-hosts-and-credentials)).

- **The manifest.** Run with the same `COMPOSER` value, so both runs read the same lock.

A change to a threshold key in `extra.lockrot` changes verdicts too: regenerate the baseline in the
same change.

## When lockrot cannot read the file

A baseline lockrot cannot read is a configuration error, never an absent baseline: the run exits
`2` instead of running ungated. A default `lockrot-baseline.json` that does not exist is not an
error; the run has no baseline.

| Symptom on stderr | Cause | Fix |
|---|---|---|
| `lockrot: <path> not found` | `--baseline` or `extra.lockrot.baseline` names a file that does not exist | Fix the path, or create the file with `--generate-baseline` |
| `lockrot: <path> is not valid JSON: …` or `… must contain a JSON object` | The file is damaged, for example by a merge conflict | Restore it from version control, or delete it and generate a new one |
| `lockrot: baseline file is invalid:` and one `  - <field>: <message>` line per problem | The file does not match the [schema](schema.md): a field of the wrong type or a missing key | Fix the named field, or delete the file and generate a new one |
| `lockrot: Cannot read <path>: …` | The file exists but cannot be read | Fix its permissions |

`--generate-baseline` reads an existing file to keep its `first_seen` dates, so it stops on a
damaged file too. Delete the file first.

## Install time

`install-time-strict` uses the same comparison: a `known` finding (in the baseline at its current
verdict or a more severe one) does not stop a `composer require`, and the compact block still
lists it ([install-time.md](install-time.md)).

At install time an unreadable baseline, or an `extra.lockrot.baseline` naming a missing file, does
not fail the install. The check is skipped with one `lockrot: install-time check skipped: …` line,
and the `install-time-strict` gate does not run for that install. `composer lockrot` reports the
same problem as exit `2`.

## Related

- [configuration.md](configuration.md#extralockrot-keys) — the `baseline` key and `--baseline=<path>`
- [ci.md](ci.md#exit-codes) — the exit codes a gated run returns
- [ci.md](ci.md#how-each-format-marks-a-finding) — how each format marks a `known` finding
- [schema.md](schema.md) — the published baseline schema and the report's `baseline` fields
- [install-time.md](install-time.md) — the compact block and `install-time-strict`
