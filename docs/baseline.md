---
title: lockrot baseline — fail CI only on new dependency rot
description: Record today's findings in lockrot-baseline.json with --generate-baseline, commit it, and CI fails only on findings that are new or have got worse since.
---

# Baseline

Record the findings you have already seen and decided to live with, so CI fails only on what is
**new** or has got **worse** since:

```bash
composer lockrot --target-php=8.4 --generate-baseline
git add lockrot-baseline.json && git commit -m "chore: accept current dependency rot"
```

A large project rarely starts clean, and turning `--fail-on` off loses the check entirely. The
baseline is the middle ground.

## What `--generate-baseline` does

It writes `lockrot-baseline.json` next to `composer.json`, prints one line on stderr
(`lockrot: baseline written to lockrot-baseline.json (74 findings)`), nothing on stdout, and exits
`0` whatever `--fail-on` says — the run records findings, it does not judge them.

**Commit the file.** It is a statement about the project, and it is worth reviewing in a pull request
like any other change. It is also the only file lockrot ever writes, and only on this explicit flag.

`--strict-network` is the one exception to that exit `0`: if a configured repository or a repository host could
not be reached, the run still exits `1` after writing the file. A baseline generated from metadata
that never arrived would accept findings lockrot was not actually able to check.

```json
{
    "$schema": "https://lockrot.dev/schema/baseline-1.json",
    "lockrot": {
        "version": "0.11.0",
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

`$schema` names the file's published [JSON schema](schema.md), which an editor uses to complete and
check the file; lockrot itself validates every baseline it reads against the same schema.

Only flagged verdicts are recorded (`ok`, `finished` and `unknown` are not findings), entries are
sorted by package name so the diff stays reviewable, and `first_seen` is carried over when you
regenerate — the file keeps saying how long each finding has been tolerated.

## Reading a baseline

Once the file exists, every normal run compares against it and says so:

```text
200 packages checked · abandoned 19 · silent 8 · pinned 4 ·
left-behind 11 · old-promise 38 · stale 3 · unknown 0 · finished 18 · ok 99
priority: critical 3 · high 64 · medium 14 · low 2
baseline: 81 known · 1 new · 1 worsened · 0 stale (lockrot-baseline.json)
```

| Bucket | Meaning | Effect on the exit code |
|---|---|---|
| `known` | The baseline holds this package at this verdict or a worse one | Never fails the build. The table shows `abandoned (baseline)`, annotations drop to notice/note |
| `new` | Flagged now, absent from the baseline | Compared against `--fail-on` as usual |
| `worsened` | In the baseline, but at a lower verdict than today's | Compared against `--fail-on`. The table shows `abandoned (was stale)` |
| `stale` | In the baseline, no longer in `composer.lock` | Never fails the build. Reported as a note so you know the entry can go |

Matching is **by package name only**. The recorded version is informational, so bumping `vendor/pkg`
from `1.2.3` to `1.3.0` while it stays abandoned keeps it accepted; a package that gets *worse*
(`stale` → `abandoned`) is reported as worsened and fails the build again. Stale entries are never
cleaned up behind your back — regenerate the baseline when you want them gone.

## Generate it with the same `--dev` setting your CI run uses

`--dev` widens what is *analysed*, not what counts as present: staleness is measured against the
whole `composer.lock`, `packages-dev` included, so a baseline generated with `--dev` never reports
its development entries as stale on a run without it.

The other direction does matter. A baseline generated *without* `--dev` contains no development
findings, so a `--dev` run reports every one of them as new and fails.

## When lockrot cannot read the file

A baseline lockrot cannot read is a configuration error, not an absent baseline: a malformed or
schema-invalid file, or a `--baseline`/`extra.lockrot.baseline` path that does not exist, exits `2`
rather than silently running ungated. The default path simply not existing is not an error — that is
every project before its first `--generate-baseline`. To start over from a file that has been
damaged, delete it and generate a new one.

## Install time

`install-time-strict` uses the same comparison: a finding the baseline already carries does not stop
a `composer require`. The compact block still lists it.

Install time never fails on a configuration problem, so it handles an unreadable baseline differently
from `composer lockrot`. An `extra.lockrot.baseline` pointing at a missing or unreadable file becomes
the usual single `lockrot: install-time check skipped: …` line, and because the check was skipped the
`install-time-strict` gate does not run for that install either. Fix the path or remove the key —
`composer lockrot` reports the same problem as exit `2` and is the quicker way to see it.

## Related

- [configuration.md](configuration.md) — `baseline` and `--baseline=<path>`
- [ci.md](ci.md) — exit codes and how each format renders a baseline-known finding
- [install-time.md](install-time.md) — the compact block and `install-time-strict`
