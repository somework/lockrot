---
title: Score model 1 — the rules that grade a finding
description: The sixteen rules of lockrot's score model 1, in the order they apply, each with its parameters and an example. Every report-2 links its rules here.
---

# Score model 1

A report-2 document names its model in `run.score_model.id` and links each rule as
`run.score_model.docs` + `#` + the rule's `doc`. This page holds the rules of model 1, in `stage`
order. A later model gets a page of its own, and this page keeps its rules.

`run.score_model` carries every number on this page, and `run.score_rules_used` counts the findings
of the report that each rule changed or decided. [The score](../verdicts.md#score) explains how to
read a score line.

## Counted flags {#score-counted}

`kind: select`, `accept_group: liveness`, `accept_covers: listed_and_below`.

A flag counts unless an allowlist entry accepts it. An accepted flag stays in `flags[]` with the
role `accepted`, weighs 0 and reaches no gate. The score lists it in `score.accepted[]`, with
`if_counted`: the score that the finding would get if the flag counted.

The liveness words `abandoned`, `silent` and `stale` are one reading. An `ignore[]` entry that
accepts one of them also accepts the words after it in that list: `abandoned` covers `silent` and
`stale`, and `silent` covers `stale`. So a package that grows quieter never scores lower under the
same entry.

`vulnerable` counts when at least one advisory counts
([Which advisories count](../verdicts.md#which-advisories-count)).

## The lead counts in full {#score-lead-first}

`kind: select`, `order: flag_order`, `share: 1/1`.

The first counted maintenance flag in flag order is the `lead`, and it adds all of its points. The
points never increase along the flag order, so the lead has the most points of the counted
maintenance flags.

| Flag | Points |
|---|---|
| `abandoned`, `silent` | 32 |
| `pinned`, `left-behind`, `old-promise` | 16 |
| `stale`, `capped` | 8 |

## Each other maintenance flag adds a quarter {#score-corroborating-share}

`kind: share`, `share: 1/4`.

Every counted maintenance flag after the lead adds a quarter of its points: 4 for a 16-point flag,
2 for an 8-point flag. The seven maintenance flags describe one fact from seven sides, so a second
flag confirms the first and does not count again in full. Maintenance never leaves the band of its
lead: the most a lead of 32 reaches is 40, the most a lead of 16 reaches is 22, and the most a lead
of 8 reaches is 10. These maxima follow `run.score_model.exclusive_groups`, the flags that never
fire together, such as `old-promise` and `capped`.

Example: `left-behind` leads, and `old-promise` adds 4 of its 16 points:
`20 = left-behind 16 + old-promise 4 [¼ of 16]`.

## Advisory points by severity {#score-advisory-points}

`kind: lookup`, `table: severities`.

Each counted advisory takes the points of its severity.

| Severity | Points | With no reachable fix |
|---|---|---|
| `critical` | 32 | 64 |
| `high` | 16 | 32 |
| `medium` | 8 | 16 |
| `unrated` | 8 | 16 |
| `low` | 2 | 4 |

An `unrated` advisory counts as `medium`. A `low` advisory takes a quarter of a medium one.

## No reachable fix doubles an advisory {#score-no-reachable-fix-multiplier}

`kind: multiply`, `factor: 2`, `when_fix: [none, blocked]`.

An advisory whose fix kind is `none` (no release fixes it) or `blocked` (no fixing release runs on
the target PHP) counts twice, which moves it one band up. A fix that needs a higher `require.php`
(`raise-php`) is reachable and does not double.

Example: a high advisory with no reachable fix scores 32, critical, not 16, high.

## The security part is the highest advisory {#score-security-max}

`kind: max`, `tie_break`: points (highest first), then severity in the order of the table above,
then advisory id in byte order.

The security part is the points of one advisory, the one that scores highest. More advisories do
not add up. Advisories that tie with it are listed in `score.parts.security.tied[]`. The tie break
makes the deciding advisory the same on every run of an unchanged lock.

## Reach halves maintenance {#score-divide-reach}

`kind: divide`, `by: 2`, `when: [transitive, unreached]`.

The maintenance part of a package that `composer.json` does not require directly is halved: the
change goes through the package that requires it. A package that nothing reaches (`unreached`) is
halved too.

Example: `abandoned` alone on a transitive package: `16 = abandoned 32 ÷ 2 transitive`, high, not
critical.

## Reach never halves security {#score-security-exempt-from-reach}

`kind: exempt`, `of: divide-reach`.

An advisory can be exploited through any package that loads the vulnerable code, so the security
part keeps its points on a transitive package.

Example: a critical advisory on a transitive package scores 32, critical.

## The parts add {#score-sum}

`kind: combine`, `op: sum`, `ratio_from: medium`.

The score is the maintenance part plus the security part. Two risks of one band make the next band
(`sum` and the [band floors](#score-band-floors) agree from `medium` up), and a weaker second risk
adds without a jump.

Examples: `left-behind` 16 and a high advisory 16 give 32, critical. A transitive `stale` 4 and a
low advisory with no reachable fix 4 give 8, medium.

## packages-dev halves the whole score {#score-divide-dev}

`kind: divide`, `by: 2`, `when: [dev]`.

A package that only `packages-dev` installs has its whole score halved, the security part included.
Development packages are in the run only with `--dev` or `include-dev`.

Example: a critical advisory on a development package scores 16, high.

## One floor at the end {#score-floor-once}

`kind: floor`.

lockrot computes the score in half points and rounds it down once, at the end. `score.exact` holds
the value before the floor, and `score.rounded_down` says whether the floor changed it.

Example: `left-behind` with a corroborating `stale` on a transitive development package is exactly
4.5, and the score is 4.

## Bands {#score-band-floors}

`kind: band`, `ratio: 2`, `ratio_from: medium`.

| Grade | From |
|---|---|
| `critical` | 32 |
| `high` | 16 |
| `medium` | 8 |
| `low` | 1 |

From `medium` up, each floor is twice the one below. `low` starts at the first point.

## Score 0 says why {#score-zero-verdicts}

`kind: zero`, `table: zero_verdicts`.

A finding with no counted flag scores 0. The first row that holds gives its verdict:

| Verdict | When |
|---|---|
| `finished` | an allowlist entry applies to the package |
| `unknown` | lockrot read no release metadata for the package |
| `ok` | otherwise |

## Sort order {#score-sort}

`kind: order`.

`findings[]` is sorted by these keys, in this order:

1. The verdict: `critical`, `high`, `medium`, `low`, `unknown`, then `finished` and `ok` as one
   group.
2. The security part, highest first.
3. `score.exact`, highest first.
4. Direct requirements first.
5. The package name, in byte order.

So inside a band, a
package with a serious advisory comes first. `run.score_model.sort` lists the keys with their JSON
paths, and each finding's `rank` is its place.

## A new fact sets the gate {#score-gate-bounded-new}

`kind: gate`, `cap_multiplier: 2`.

When the baseline records a package, `--fail-on` reads a gate score of `min(score, 2 × new)`.
`new` is what the facts that the baseline does not cover add. A known package with no new fact has
a gate score of 0.

Example: a package that scores 36, with a new high advisory worth 16, has a gate score of 32.

## The recorded lead covers the flags after it {#score-baseline-cover}

`kind: cover`, `order: flag_order`, `covers: listed_and_below`.

A flag that the baseline entry records covers itself and every flag after it in flag order. So a
flag above the recorded ones is new, and a flag below them is not.

Example: an entry that records `left-behind` covers a later `old-promise`, `stale` and `capped`,
and not `pinned`.

## Related

- [The score](../verdicts.md#score) — how to read a score line, and the fields of `score`
- [Flags](../verdicts.md#flags) — what each flag claims and which signals raise it
- [Baseline](../baseline.md) — what the baseline records, and how the gate reads it
- [JSON schemas](../schema.md) — `run.score_model`, `run.score_rules_used` and `score`
