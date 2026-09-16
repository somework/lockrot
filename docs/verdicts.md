# Verdicts and priority

Every package in `composer.lock` gets exactly one verdict and one priority. The verdict says what
was observed about the package. The priority says how much that applies to *your* project.

## The eight verdicts

| Verdict | Meaning | Signals |
|---|---|---|
| `abandoned` | The package's Composer repository marks it abandoned (Packagist by default), or its GitHub repository is archived | S1 or S3 |
| `silent` | No stable release for at least `release-high-years` (default 5y) **and** no repository push for at least `push-high-years` (default 5y); an archived repository is reported as `abandoned` instead | S2 high AND S4 high, NOT S1, NOT S3 |
| `pinned` | Installed version is a branch snapshot (`dev-*` or `#hash`), or the package has no stable release at all | S6 |
| `old-promise` | The installed version was released before the target PHP's GA date, and its `require.php` constraint is open-ended (`>=N`, `*`) for that target | S5 |
| `stale` | Old release or old push, but not old enough (or not on both fronts) for `silent` | one of S2/S4 |
| `unknown` | No data could be obtained (not found in any configured Composer repository, or all lookups failed) | — |
| `finished` | Matched the built-in or project allowlist — the package is complete by design, not neglected | allowlist match |
| `ok` | None of the above | — |

Severity order among the signal-derived verdicts, used by `--fail-on` and the baseline:
`abandoned > silent > pinned > old-promise > stale > unknown > ok`.

`finished` and `ok` sit equal and lowest in that ordering, and neither is ever a finding.

An allowlist match never competes in the order at all. It is checked before any signal is read and
always wins, so an allowlisted package reports `finished` whatever its signals say — see
[configuration.md](configuration.md).

## The signals

| Signal | What it observes |
|---|---|
| S1 | The Composer repository marks the package abandoned, sometimes naming a replacement |
| S2 | Time since the last stable release, against `release-warn-years` / `release-high-years` |
| S3 | The GitHub repository is archived |
| S4 | Time since the last repository push, against `push-warn-years` / `push-high-years` |
| S5 | The installed release predates the target PHP's GA date and the `require.php` constraint has no upper bound |
| S6 | The installed version is a branch snapshot (`dev-*`, `#hash`), or the package has no stable release |
| S7 | A direct requirement pulls in flagged transitive packages — informational, never a verdict; see [Transitive exposure](#transitive-exposure) |

S3 and S4 come from GitHub and need network access; see [internals.md](internals.md) for how that
data is fetched and cached, and [configuration.md](configuration.md) for the thresholds.

Every finding's evidence line states the concrete fact — release date, push date, constraint string
— and the report footer states the data date. There are no severity words beyond the verdict names
above.

## Priority

A package reported the same way matters less when nothing in the project requires it directly, and
less again when it is only ever installed for development.

Three rules, in order:

1. A package the report does not flag (`unknown`, `finished`, `ok`) has priority `none`.
2. Otherwise the verdict sets the base level: `abandoned` and `silent` start at **critical**,
   `pinned` and `old-promise` at **high**, `stale` at **medium**.
3. The base drops one step when the package is transitive (nothing you require names it) and one
   more step when it is a development dependency. It never drops below **low**.

| Verdict | direct, prod | transitive, prod | direct, dev | transitive, dev |
|---|---|---|---|---|
| `abandoned`, `silent` | `critical` | `high` | `high` | `medium` |
| `pinned`, `old-promise` | `high` | `medium` | `medium` | `low` |
| `stale` | `medium` | `low` | `low` | `low` |
| `unknown`, `finished`, `ok` | `none` | `none` | `none` | `none` |

A package nothing in your `require`/`require-dev` can reach counts as transitive.

The priority orders the report — highest first, then by verdict severity, then direct dependencies
ahead of transitive ones, then by package name — and it is carried in every format.

> **The exit code and `--fail-on` stay on the verdict.** Priority is there to tell you what to read
> first, not to decide whether the build fails.

`--dev` is what brings development packages into the run at all. Once they are in, each of them sits
one step below the same finding on a production package.

## Priority in each format

| Format | How the priority appears |
|---|---|
| `table` | Findings are grouped under the priority level, highest group first |
| `json` | Each finding carries `priority`, `direct` and `dev`; the document carries a `priorities` object with all five counts next to `counts` |
| `github` | In each annotation's title: `lockrot: abandoned (critical)` |
| `gitlab` | Opens each issue's description: `… — abandoned (critical): …` |
| `markdown` | A first `Priority` column |
| `sarif` | The result's `rank` (`critical` `100.0`, `high` `75.0`, `medium` `50.0`, `low` `25.0`, `none` `0.0`) plus `properties.priority`, `properties.direct` and `properties.dev` |

The JSON `schema` number stays `1` — these are additions, so anything already reading the document
keeps working.

Nothing that decides an outcome moved. The GitHub annotation level, the GitLab severity and
fingerprint, and the SARIF `ruleId` and `level` all still read the verdict alone.

## Transitive exposure

You can only act on what `composer.json` names. So for a transitive finding the question is *which
of my direct requirements pull this in*, and for a direct requirement *what does it drag in*. The
report answers both.

**Every direct requirement a package is reachable from.** The `via` chain names the shortest path
from one direct requirement. When others reach the package too, the row says so:

```text
  abandoned    hoa/ruler 2.17.05.16  via wallabag/rulerz, also via wallabag/rulerz-bundle
```

Dropping `wallabag/rulerz` alone would leave `hoa/ruler` installed through `wallabag/rulerz-bundle`.
Up to three other requirements are named, then counted (`and 12 more`); `--format=json` carries the
full list as `direct_dependents` on every finding, the package itself included when it is direct.
A direct requirement's own row stays `direct`, even when other requirements reach it as well — in
a framework application every bundle reaches the framework's own packages, and naming them there
would say nothing.

**Signal S7 on the direct requirement.** After every verdict is known, each direct requirement
whose subtree holds flagged transitive packages gets an informational signal listing them, in
report order, with the shortest chain from that requirement to each:

```text
  pinned       wallabag/rulerz-bundle dev-master  direct
               released 2023-12-24, before PHP 8.4 GA (2024-11-21); php constraint ">=7.4" has no
               upper bound; pinned to branch snapshot dev-master; pulls in 18 flagged packages:
               hoa/compiler (abandoned), hoa/consistency (abandoned), hoa/event (abandoned),
               hoa/exception (abandoned), hoa/file (abandoned) and 13 more
```

Five are named in the evidence; `--format=json` carries them all under the signal's `data`, each
with its `verdict` and `chain`. A requirement whose own verdict is `ok` carries S7 too, so `--all`
shows what a clean-looking requirement is responsible for.

**The `pulled in by:` line.** The summary block sums the same thing up per direct requirement,
most first:

```text
pulled in by: wallabag/rulerz-bundle 18 · wallabag/rulerz 15 · friendsofsymfony/oauth-server-bundle 5 ·
wallabag/phpepub 5 · craue/config-bundle 4 · … and 46 more
```

Five requirements are named, then the rest counted; the JSON document carries the whole list as
`exposure`. The line is printed only when some flagged package is transitive.

> **S7 decides nothing.** A package is never flagged for what it depends on. The verdict, the
> priority, `--fail-on`, the exit code and the baseline all ignore S7; it describes, the same way
> the priority does. A flagged package the project requires directly is its own row's business
> and counts under nobody, whoever else reaches it.

At install time only the packages the transaction touches are analysed, so a direct requirement
gets S7 only when it is itself part of the transaction; `composer lockrot` on the full lock always
has the whole picture.

## Related

- [example-run.md](example-run.md) — a full run with every verdict in it
- [configuration.md](configuration.md) — the thresholds behind S2 and S4, and the allowlist
- [baseline.md](baseline.md) — accepting findings you have already decided to live with
- [ci.md](ci.md) — exit codes and the six output formats, and where each carries the exposure
