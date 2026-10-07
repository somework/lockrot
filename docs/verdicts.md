---
title: What it reports — verdicts, signals and priority
description: What each lockrot verdict means, the rule and signals behind it, what clears it, and how direct, transitive and dev dependencies set a finding's priority.
---

# What it reports {#verdicts-and-priority}

Every package in `composer.lock` gets a verdict (what lockrot observed about it) and a priority
(how much that matters to your project). [The verdicts](#the-nine-verdicts) table gives each
verdict's rule, its signals, and what you and the package's maintainer can do to clear it.

## The verdicts {#the-nine-verdicts}

| Verdict | Rule | Signals | What you can do | What the maintainer can do |
|---|---|---|---|---|
| [`abandoned`](#abandoned-and-where-to) | The package's Composer repository marks it abandoned (without repository metadata, the lock's own mark), or its repository is archived on its host | S1 or S3 | Move to the [named replacement](#abandoned-and-where-to) or another package | Lift the mark, or unarchive the repository |
| `silent` | No release for `release-high-years` (default 5) **and** no push for `push-high-years` (default 5) | S2 high and S4 high | Replace it, or accept it with an [`ignore` entry](configuration.md#the-allowlist) or the [baseline](baseline.md) | Release and push. Either alone lowers it to `stale` |
| [`pinned`](#what-s6-carries) | The installed version is a branch snapshot (`dev-main`, any other `dev-*` branch, `2.x-dev`), or the package has no tagged release at all | S6 | Require a tagged release | Tag a release, for a package that has none |
| [`left-behind`](#left-behind) | No stable release on the installed version's release branch for `release-warn-years` (default 3), while a higher branch keeps releasing | S8 | Require the branch the evidence names (`require ^3.0 to follow`) | Release on the old branch |
| [`old-promise`](#old-promise) | The installed release predates the target PHP's major, and its `require.php` admits the target only because it has no upper bound | S5 | Update to a release cut after that major's GA, or to one whose `require.php` names the major | Release after that major's GA, or with a `require.php` that names it |
| `stale` | No release for `release-warn-years` (default 3), or no push for `push-warn-years` (default 3), short of `silent` | S2 or S4 | Replace it, or accept it with an [`ignore` entry](configuration.md#the-allowlist) or the [baseline](baseline.md) | A release inside `release-warn-years` and a push inside `push-warn-years` |
| `unknown` | No verdict signal fired, and lockrot has no repository metadata for the package: it is not from a Composer repository, the repository does not list it, or the lookup failed | none | Make its metadata load. The run's [notes](notes.md) say what failed. For a package not from a Composer repository, add an [`ignore` entry](configuration.md#the-allowlist) if you vouch for it | Publish it to a Composer repository |
| `finished` | The package matches the built-in allowlist or the project's `ignore` list | allowlist | Nothing: not flagged. An `ignore` entry past its `expires` date falls back to the normal verdict | Nothing |
| `ok` | None of the rules above applies | none | Nothing: not flagged | Nothing |

Severity order, used by [`--fail-on`](ci.md), the baseline and the report's sort order:
`abandoned > silent > pinned > left-behind > old-promise > stale > unknown > finished = ok`.

- A *finding* is any entry in the report's `findings`: one per analysed package, `ok` included. A
  *flagged* finding has a verdict from `abandoned` through `stale`. `unknown`, `finished` and `ok`
  findings have priority `none` and no verdict or priority threshold reaches them. `--format=json`
  and `--format=html` carry every finding. The other formats list these three only with `--all`.

- A package gets the first verdict, top to bottom, whose rule holds. lockrot checks the allowlist
  before any signal, and a match always wins: an allowlisted package is `finished` whatever its
  signals say ([the allowlist](configuration.md#the-allowlist)).

- To clear a transitive finding (`via …`), update or replace the direct requirement that pulls it
  in. See [Transitive exposure](#transitive-exposure).

- `--fail-on=unchecked` reads S10, not the verdict, so it can fail a finding of any verdict but
  `finished`. See [What was not checked](#what-was-not-checked).

- Where lockrot lists verdicts (`counts`, `run.flagged_verdicts`, the schema enums, this page),
  `finished` comes before `ok`. Which orders are frozen is in
  [compatibility.md](compatibility.md#closed-sets-and-their-order).

## The signals

| Signal | What it observes | Reads | Decides |
|---|---|---|---|
| S1 | The Composer repository marks the package abandoned, and sometimes names a replacement. Without repository metadata, the lock's own `abandoned` mark | Composer repository, else the lock | `abandoned` |
| S2 | Time since the package's newest release (pre-releases count, branches do not), against `release-warn-years` / `release-high-years` | Composer repository | `silent`, `stale` |
| S3 | The repository is archived on its host | Repository host ([which hosts, which credentials](internals.md#repository-hosts-and-credentials)) | `abandoned` |
| S4 | Time since the repository's last activity on any branch, against `push-warn-years` / `push-high-years` ([what each host reports](internals.md#repository-hosts-and-credentials)) | Repository host | `silent`, `stale` |
| S5 | An open-ended `require.php` on a release older than the target PHP's major ([Old promise](#old-promise)) | Lock and target PHP | `old-promise` |
| S6 | A branch snapshot, or no tagged release ([What S6 carries](#what-s6-carries)) | Lock (snapshot), Composer repository (tags) | `pinned` |
| S7 | The flagged transitive packages a direct requirement pulls in | Lock and `composer.json` | nothing ([Transitive exposure](#transitive-exposure)) |
| S8 | Time since the newest stable release on the installed release branch, against `release-warn-years` / `release-high-years`, while a higher branch releases ([Left behind](#left-behind)) | Composer repository | `left-behind` |
| S9 | Security advisories that affect the installed version | Composer repository | nothing. It can raise the priority ([Security advisories](#security-advisories)) |
| S10 | A check the verdict rests on did not run, and the signals it blocked | The other checks | nothing ([What was not checked](#what-was-not-checked)) |

- S2, S4 and S8 are `warn` from the warn threshold and `high` from the high one. Only `silent`
  reads the level. The thresholds are [`extra.lockrot` keys](configuration.md#extralockrot-keys).

- S2's `data` is the release it measures from (`last_version`, `last_release`), the `years` since
  it, and `dated_by`: the [monorepo parent](#dates-from-the-monorepo) that dated it, else null.
  `report-1.json` types the `data` of every signal ([Signal data](schema.md#signal-data)). The
  S6, S8 and S9 sections of this page give their fields.

## Abandoned, and where to

`abandoned` covers two cases: a package whose repository names a successor, and one that names
nothing. The report tells them apart here:

| Where | What it says |
|---|---|
| Evidence | The repository's replacement text as written: `replacement: symfony/mailer`, or free text such as `replacement: Symfony` |
| JSON | `replacement`, `replacement_url` and the root `abandoned` counts ([schema.md](schema.md#what-the-report-schema-types)) |
| Summary line | `abandoned N (M with a replacement)`, the parenthesis only when M is not zero |
| S9 clause | `no fix expected; migrate to symfony/mailer` on an advisory no release fixes |

If the successor is already in the lock, remove the old package.

### Composer's abandoned ignore list {#abandoned}

lockrot reads Composer's abandoned ignore list as `composer audit` reads it: a listed package raises
no S1.

## Pinned: what S6 carries {#what-s6-carries}

S6's evidence reads the same whether or not the package ever released. Its `data` in
`--format=json` tells the cases apart:

| Key | Value |
|---|---|
| `version` | The installed version, as the lock writes it |
| `reason` | `branch_snapshot` when the installed version is a branch (checked first), `no_stable_release` when it is not and the repository lists no tagged version. An [open set](schema.md#open-sets) |
| `has_stable_release` | Whether the repository lists any tagged version. A pre-release counts, a branch does not. Null when lockrot loaded no repository metadata for the package (a `vcs` or `path` entry, a package the repository does not list, metadata that did not load) |
| `last_stable_release`, `last_stable_version` | The newest *dated* tagged release, which is not always the highest tag. Null with no tagged release, with no metadata, or when the highest tag has no date lockrot trusts and no [monorepo parent](#dates-from-the-monorepo) dates it |
| `last_stable_dated_by` | The monorepo parent that dated that release, else null |
| `snapshot_time` | For a snapshot, the lock's `time`: the date of the commit the branch pointed at, not a release. Null for `no_stable_release`, and when the lock entry has no `time` |

`no_stable_release` is not libyears' `no_stable_release_date`, which counts a package lockrot
cannot date at one end ([Libyears](#libyears)).
[`--explain`](configuration.md#explaining-one-package) shows the same facts under `metadata`, and
the snapshot's date as `lock.released`. Its text omits the null keys that `--format=json` carries.

## Left behind

`composer outdated --major-only` says a newer major exists, and S2 does not fire while the package's
newest release is recent. S8 says the branch you are on gets no releases.

A version's *release branch* is the range a caret constraint on it stays inside:

| Installed version | Release branch | As in |
|---|---|---|
| `1.0` and above | The major: `1.2` and `1.9` are both `1.x`, `2.0` is not | `^1.2` |
| `0.1` to below `1.0` | The major and minor: `0.3.x` | `^0.3` |
| Below `0.1` | The patch alone: `0.0.3` | `^0.0.3`, which is `>=0.0.3 <0.0.4` |

S8 fires when all of these hold:

1. The newest stable release on the installed branch is at least `release-warn-years` old. A
   backport on a lower minor counts. A pre-release does not.

2. A higher branch has released after it.

3. That higher branch's newest release is less than `release-warn-years` old.

If the last condition does not hold, every branch is quiet, which is S2's case (`stale` or
`silent`). The verdict is `left-behind` at either level, and the signal keeps the level. Abridged
from the [example run](example-run.md):

```text
  left-behind  smalot/pdfparser v1.1.0  via j0k3r/graby
               branch 1.x last released 2021-08-03 (5.2 years ago); 2.x released v2.12.5 (2026-04-17)
```

- The second clause names the higher branch with the most recent release, which can be an LTS
  below the newest major.

- For a direct requirement, the evidence adds the line to write, `require ^3.0 to follow`, in the
  form that `composer require` writes (`^0.4.3` below 1.0). A transitive package's parent owns
  that line, so the evidence omits the clause. The signal carries the constraint as
  `suggested_constraint` in both cases.

- S8's `data` names the installed `branch`, its newest stable release (`branch_last_version`,
  `branch_last_release`) and the `years` since it, the higher branch the second clause names
  (`newest_branch`, `newest_version`, `newest_release`) and `dated_by`.

S8 is not measured for:

- A branch snapshot, which is on no branch and is `pinned` instead.

- A branch the repository lists no stable release on.

- An installed version above the highest tag the repository lists on its branch: a private fork,
  or a lock written against a tag since deleted.

- A branch whose highest tag has no [trusted date](#dates-from-the-monorepo).

The same rule applies to S2: if the package's highest tag has no trusted date, lockrot does not
measure S2, and [S10](#what-was-not-checked) says so.

### Within reach

S8 tells you to follow the newest higher branch that releases and whose php requirement admits
both of these floors:

- the project's own `require.php` as `composer.json` writes it, read as the lowest version it
  names.

- the target PHP ([`target-php`](configuration.md#extralockrot-keys) or its option and environment
  override, else `config.platform.php`, else the running PHP), as a whole minor: a branch outside
  it will not install.

When that is not the newest branch, the evidence says which floor blocks the newest branch, then
names the branch within reach. Abridged from the [example run](example-run.md):

```text
  left-behind  scheb/2fa-bundle v5.13.2  direct
               branch 5.x last released 2022-04-16 (4.5 years ago); 8.x released v8.6.1 (2026-07-10), needs php ~8.4.0
               || ~8.5.0 above the project's php >=8.2; 7.x released v7.14.0 (2026-06-12); require ^7.14 to follow;
               pulls in 1 flagged package: symfony/security-guard (abandoned)
```

If no branch that releases is within reach, S8's clause ends `no releasing branch within reach`
and suggests nothing: the way forward is a PHP upgrade. The verdict stays `left-behind`.
`report-1.json` types S8's `data`, `suggested_constraint` included
([Signal data](schema.md#signal-data)). The report's `run` carries
the target PHP as `target_php`, and the project's `require.php` constraint as `project_php`.

#### The PHP test in `--explain` {#the-php-test-in-explain}

[`--explain`](configuration.md#explaining-one-package) applies this test to every branch, the
installed one and those below it included. A row's php is the requirement of its branch's newest
dated release. On the installed row, that release can be newer than the version you have, whose
own requirement is `lock.php`. Each branch row in the JSON carries:

| Field | Value |
|---|---|
| `admits_target_php`, `admits_project_php` | Whether the branch's php admits that floor. Null where there is nothing to test: the branch requires no PHP, its requirement cannot be parsed, or the floor is missing. Null never means admitted |
| `php_blocked_by` | `project` when the branch misses the project's floor, whether or not it also misses the target. Else `target` when it misses the target. Else null: the branch is within reach. A null `admits_*` blocks nothing. An [open set](schema.md#open-sets) |
| `misses_target_php`, `misses_project_php` | Which side of that floor the branch's php lies on. Null exactly where the matching `admits_*` is not false. An [open set](schema.md#open-sets) |
| `highest_commit_date` | The highest tag's commit date when other tags share that commit, not a release date. Null when the tag has a release date or none |

| `misses_*` | The branch's php admits | Example against the floor |
|---|---|---|
| `needs_newer` | Only PHP above the floor: upgrade PHP | `>=8.4.1` against `>=8.2` |
| `stops_before` | Only PHP below the floor: move to another branch | `>=7.2 <8.4` against 8.4 |
| `skips` | PHP on both sides, not the floor itself | `^7.4 || ~8.2.0` against 8.1 |
| `unsatisfiable` | No PHP at all | `>=9 <8` |

## Old promise

S5 fires when all of these hold:

1. The installed release was published before the GA of the target PHP's major: 8.0 for any 8.x
   target, not the target minor.

2. Its `require.php` has no upper bound.

3. Its lower bound is on an older major (`>=7.2` against PHP 8.4), or it has none (`*`).

A constraint that names the target's major (`^7.2 || ^8.0`) has an upper bound and is never S5. A
`>=7.2` released after PHP 8.0's GA is not S5 either. `composer check-platform-reqs` asks a
different question: whether the platform satisfies each constraint, which `>=7.2` does on PHP 8.4.

Abridged from the [example run](example-run.md):

```text
  old-promise  mgargano/simplehtmldom 1.5  direct
               released 2014-01-05 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
               untested; last release 2014-01-05 (12.7 years ago); last push 2022-08-04 (4.2 years ago)
```

## Dates from the monorepo

A tag has a *trusted date* unless it has no date, or its commit carries three or more stable tags.
A subtree split (`illuminate/*`, `symfony/*`) puts its tags on one commit, which has the date of
the last change to that directory. A re-tag, two tags on one commit, stays trusted.

The split's monorepo tags the same version with the release date, and its
`replace: {child: self.version}` says the two tags are one release. So where a branch of the split
carries no trusted date, the parent's branch of the same name dates it, and the evidence says whose
date it is: `dated by laravel/framework`.

lockrot finds the parent in this order:

1. In the lock: a Laravel application already has `laravel/framework`, and lockrot fetches
   nothing.

2. Otherwise with one request to the configured repositories. lockrot ships a list of monorepos
   (such as `laravel/framework` and `symfony/symfony`) and the packages each carries. It fetches a
   parent only when that list names a package that the lock needs dates for.

That list decides only which parents lockrot fetches. A parent dates only what its own `replace`
list names. lockrot cannot date a split that no package replaces as `self.version`, and spends no
request on it.

- The parent dates the installed version too. Where it lists the same version, its date replaces
  the lock's shared-commit `time` for [libyears](#libyears). `--explain` prints it as `installed
  release` (`installed_release_dated_by` in JSON). Where it does not, the package is unmeasured.

- A branch the parent does not have, or does not date either, stays unmeasured, as does every
  branch in an install-time run that has spent its [budget](install-time.md#time-budget).

- S2 and S8 carry the parent as `dated_by`, and `--explain` marks the branch rows it supplied.

## Security advisories

`composer audit` reports the vulnerability. S9 carries the same advisories on the finding and says
whether a fix is coming.

S9 lists every advisory whose affected range matches the installed version. lockrot fetches them
from the configured Composer repositories the way `composer audit` does. Then it holds each
advisory against two releases that the repository lists, each only when it is above the installed
version:

| Release | When that release is outside the advisory's affected range | Evidence |
|---|---|---|
| The highest stable tag on the installed branch | A `composer update` inside the constraint gets the fix (`fixed_on_branch: true`) | `fixed by <version>` |
| The package's highest stable tag | The fix needs a higher branch | `fixed by <version>` |

When one release does not fix every advisory, the clause counts each: `1 fixed by v3.4.47, 3 fixed
by v8.1.7`.

On `abandoned`, `silent` and `left-behind` findings, an advisory that no reachable release fixes
gets `no fix expected`, and the priority rises one step, `critical` at most:

| Verdict | A fix counts when it is | Clause |
|---|---|---|
| `abandoned`, `silent` | In either release that S9 checks | `no fix expected`, or `no fix expected; migrate to <successor>` when the repository [names one](#abandoned-and-where-to) |
| `left-behind` | On the installed branch | `no fix expected on <branch>` when a higher branch has the fix, else `no fix expected` |
| Any other verdict | lockrot makes no fix prediction and never raises the priority | none |

Abridged from the [example run](example-run.md):

```text
  left-behind  spomky-labs/otphp v10.0.3  via scheb/2fa-google-authenticator
               branch 10.x last released 2022-03-17 (4.5 years ago); 11.x released 11.5.0 (2026-06-06); 2 security
               advisories affect v10.0.3 (PKSA-kbc7-dq62-pt7d, PKSA-qv5y-crcz-9nxw); fixed by 11.5.0; no fix expected on
               10.x
```

If a release that the package can reach fixes every advisory, the priority does not rise, and the
evidence names that release. [Priority](#priority) shows where the raise falls among the rules.

An advisory also counts as unfixed when lockrot could not read the package's releases (no
metadata, or an installed version it cannot compare) or the advisory gives no affected range. Each
finding lists the advisories behind the raise as `no_fix_expected`, each `{id, reason}`.
[schema.md](schema.md#advisories-with-no-fix-expected) gives the `reason` values and each state of
the field. A non-empty list, the `no fix expected` clause and the `no_fix_expected` step of
`priority_basis` always appear together.

`--format=json` carries every advisory under S9's `data.advisories`, next to `releases_read`
([schema.md](schema.md#signal-data)):

| Field | Holds |
|---|---|
| `id` | The repository's advisory id, such as `PKSA-kbc7-dq62-pt7d` |
| `cve`, `title`, `link`, `severity`, `reported_at` | The advisory's CVE, title, page, severity and report date. Each null where the repository gives none |
| `affected_versions` | The affected range, as Composer prints it. Null when the advisory gives none |
| `fixed_by` | Of the two releases that S9 checks, the first that is outside the range. Null when neither is |
| `fixed_on_branch` | True when `fixed_by` is the highest stable tag on the installed branch |

- **Naming.** The evidence names each advisory by its CVE, else its repository id, worst severity
  first. Past the first few, it counts the rest.

- **Unflagged packages.** Advisories on `unknown`, `finished` and `ok` packages do not appear on
  the rows and never raise a priority. The footer counts them: `N security advisories on M packages
  the report does not flag; see composer audit`.

- **Scope.** The count covers the packages the run checked. When `packages-dev` was not in the
  run, the footer adds `(it counts packages-dev too, which this run skipped; pass --dev to include
  them)`. The JSON document records the scope as `include_dev`.

- **Accepted advisories.** Silence an advisory where `composer audit` silences it:
  `config.policy.advisories` (`ignore-id`, `ignore`, `ignore-severity`) on Composer 2.10 and later,
  `config.audit.ignore` from 2.6 and `config.audit.ignore-severity` from 2.9. What audit drops on
  the Composer that runs lockrot, lockrot drops. When Composer rejects the policy section, lockrot ignores no advisory and says so in a
  [note](notes.md#advisory_ignore_unreadable).

- **Baseline.** The [baseline](baseline.md) stays keyed on the verdict, so a baselined finding is
  `known` whatever S9 adds to its priority.

lockrot does not check advisories (S9) in these cases, and a [note](notes.md#advisories_not_checked)
says so:

- Composer older than 2.4.

- `--offline`.

- At install time, for the repositories that remain once the budget is spent.

If lockrot cannot reach a repository for advisories, the run gets a
[note](notes.md#advisories_unavailable) and, under `--strict-network`, exit `1`
([exit codes](ci.md#exit-codes)).

### Which advisories count {#which-advisories-count}

lockrot asks for the advisories of every package that the run checks, whatever its origin, unless
`extra.lockrot.advisory-lookup` is `composer-repositories`.

## Priority

The priority says how much a finding applies to your project. lockrot sets it by these rules, in
order:

1. A package the report does not flag (`unknown`, `finished`, `ok`) has priority `none`.

2. The verdict sets the base. `abandoned` and `silent` start at `critical`. `pinned`,
   `left-behind` and `old-promise` start at `high`. `stale` starts at `medium`.

3. The base drops one step when the package is transitive and one more when it is a development
   dependency, never below `low`. A package nothing in `require` or `require-dev` reaches counts
   as transitive.

4. An advisory with [no fix expected](#security-advisories) raises the result one step, never
   above `critical`.

Rules 1 to 3 give:

| Verdict | direct, prod | transitive, prod | direct, dev | transitive, dev |
|---|---|---|---|---|
| `abandoned`, `silent` | `critical` | `high` | `high` | `medium` |
| `pinned`, `left-behind`, `old-promise` | `high` | `medium` | `medium` | `low` |
| `stale` | `medium` | `low` | `low` | `low` |
| `unknown`, `finished`, `ok` | `none` | `none` | `none` | `none` |

Development packages are in the run only with `--dev` or `include-dev`.

`--format=json` writes the walk on every finding as `priority_basis`: the `base`, then each step in
rule order as `{reason, from, to}`. The `reason` is `transitive`, `unreached` (nothing reaches the
package), `dev` or `no_fix_expected`. The JSON records a step whenever its rule applies, also when
the priority cannot move. Take a transitive `left-behind` finding with an advisory that no reachable
release fixes. Its base is `high`, a `transitive` step goes from `high` to `medium`, and a
`no_fix_expected` step goes from `medium` to `high`.

lockrot sorts the report by priority, highest first, then by the severity order of the verdicts,
then direct requirements ahead of transitive packages, then by package name.

`--fail-on` fails the run on a finding at or above a verdict, a priority or `unchecked`, except one
the baseline carries. [ci.md](ci.md) says which to choose.

## Priority in each format {#priority-in-each-format}

Every output format carries the priority: the `table` groups findings under it, the JSON gives
each finding `priority` and `priority_basis`, and SARIF ranks by it. The GitLab fingerprint and the
SARIF `ruleId` read the verdict alone. Each format's mark (GitHub level, GitLab `severity`, SARIF
`level`) follows `--fail-on` ([marks](ci.md#how-each-format-marks-a-finding)). Each format's
section in [ci.md](ci.md#choosing-a-format) shows where the priority appears.

## Transitive exposure

You can act only on what `composer.json` names. For a transitive finding, the report says which
direct requirements pull it in. For a direct requirement, it says which flagged packages it pulls
in.

### Every direct requirement that reaches a package

The `via` chain is the shortest path from one direct requirement. `also via` names the other
direct requirements that reach the package, the first few by name and the rest counted
(`and N more`).

If you remove only the `via` requirement, every `also via` one still installs the package.
`--format=json` carries the full list as `direct_dependents` on every finding, the package itself
included when it is direct. A direct requirement's own row reads `direct`, whoever else reaches
it.

### S7 on the direct requirement

Each direct requirement whose subtree holds attributed flagged packages gets S7. S7 lists them in
report order, with the shortest chain to each. A flagged package that the project requires directly
counts under no requirement, its own included, whoever else reaches it. Abridged from the [example
run](example-run.md):

```text
  pinned       wallabag/rulerz-bundle dev-master  direct
               pinned to branch snapshot dev-master; pulls in 15 flagged packages: hoa/compiler (abandoned),
               hoa/consistency (abandoned), hoa/event (abandoned), hoa/exception (abandoned), hoa/file (abandoned) and
               10 more
```

The evidence names the first few. `--format=json` carries them all under the signal's
`data.packages`, each with its `verdict` and `chain`. A direct requirement whose own verdict is
`ok` or `finished` carries S7 too: its row prints only under `--all`, and the `pulled in by:` line
counts it either way.

### Shared packages and `unattributed` {#shared-packages-and-unattributed}

A flagged transitive package reached from more direct requirements than the run's
`exposure_rule.max_fan_in` is shared infrastructure, such as a framework's own contracts reached
from every bundle, and nobody's to remove. The JSON document records the value the run used. Such
a package:

- Is not in S7 or in the `pulled in by:` line.

- Keeps its own row, with `also via … and N more`, and `direct_dependents` still names every
  parent.

- Is listed in the JSON document under `unattributed`, with its `verdict` and `fan_in` (how many
  direct requirements reach it), in report order.

Fan-in counts the run's direct requirements, so under `--dev` `require-dev` counts too: a package
can be attributed without `--dev` and shared with it. A flagged transitive package no direct
requirement reaches is in neither list: every one in a run without `composer.json`, and one the
project reaches only through a name it provides or replaces.

### The `pulled in by:` line

The summary block counts, per direct requirement, the attributed flagged packages it pulls in, most
first. The line names the first few requirements and counts the rest. The JSON document carries
the whole list as `exposure`. A direct requirement appears only when it pulls in an attributed
package, and lockrot prints the line only when some flagged package is attributed.

Limits:

- The graph is the lock's `require` edges. A requirement met through `replace` or `provide` (a
  virtual package such as `psr/log-implementation`) adds no edge, so a provider can list fewer
  parents than pull it in. The `via` column reads `?` when nothing reaches the package.

- At install time, lockrot analyses only the packages that the transaction touches. A direct
  requirement gets S7 only when it is in the transaction, and the [compact block](install-time.md)
  prints neither S7 nor the other parents. `composer lockrot` on the full lock has the whole
  picture.

## What was not checked

`ok` means no rule fired: every check ran and found nothing, or a check never ran. S10 marks the
second case on the finding itself.

Without a token or Composer credentials for a host that caps anonymous requests
([credentials](internals.md#repository-hosts-and-credentials)), lockrot asks the host only about
packages that already look stale on release age
([anonymous cap](notes.md#repository_activity_anonymous_cap)). A package with
a recent release and an archived repository then never gets S3, and reads `ok` instead of
`abandoned`.

| Check | `reason` | Cause | Blocks |
|---|---|---|---|
| `repository_activity` | `no_token` | No credentials for the host, and the package was not among those asked about | S3, S4 |
| `repository_activity` | `anonymous_budget` | A candidate the anonymous request budget could not fit | S3, S4 |
| `repository_activity` | `install_time_budget` | The install-time budget ran out | S3, S4 |
| `repository_activity` | `rate_limit` | The host answered "too many requests" | S3, S4 |
| `repository_activity` | `fetch_failed` | A timeout, a transport error or an unreachable host | S3, S4 |
| `repository_activity` | `offline` | `--offline` | S3, S4 |
| `release_dates` | `undated_releases` | The package's highest tag has no [trusted date](#dates-from-the-monorepo), and no monorepo parent dates it | S2 and S8. S2 alone on a branch snapshot, which has no release branch |

S10's `data` lists each missing check as `unchecked` (`{check, reason, blocks}`) and every blocked
signal under `blocks`.

- lockrot raises S10 only where the missing check could have changed the verdict. So it never
  raises S10 on an allowlisted package, or for repository activity or release dates on a package
  that S1 marks abandoned.

- Credentials for every host remove `no_token` and `anonymous_budget`. `rate_limit`, `fetch_failed`,
  `offline` and `install_time_budget` can still occur, and `release_dates` asks no host, so a fully
  credentialed run can still carry S10.

- `--fail-on=unchecked` fails the run on any finding that carries S10. It catches a workflow that
  does not pass `GITHUB_TOKEN` to lockrot ([ci.md](ci.md)).

## Libyears

Libyears give one number for how far behind the whole lock is. The number decides no verdict.
Abridged from the [example run](example-run.md):

```text
libyears: 181.7 behind across 194 of 200 packages · 113.6 from direct requirements ·
furthest behind phpdocumentor/reflection-common 2.2.0 at 5.4
```

For each package, lockrot takes the years from the installed version's release to the package's
newest release (the date S2 reads). It counts years of 365.25 days, never below zero, and sums them
over the packages the run analysed. The installed version's date is the lock's `time`, or its
[monorepo parent's](#dates-from-the-monorepo). The number does not read the clock. The package
furthest behind is the one with the highest value. A tie goes to the package whose name sorts first
in byte order.

The unit is the *libyear* of [libyear.com](https://libyear.com/), after [Cox, Bouwers, van Eekelen
and Visser, *Measuring Dependency Freshness in Software Systems*, ICSE
2015](https://ericbouwers.github.io/papers/icse15.pdf).

Each finding carries `libyears` and `libyears_unmeasured`, and the document carries a root
`libyears` block ([schema.md](schema.md#libyears)).

lockrot checks the keys in this order and counts an unmeasured package under the first that
applies. So a `path` entry on `dev-main` counts as `not_from_composer_repository`:

| `unmeasured` key | When |
|---|---|
| `not_from_composer_repository` | lockrot asked for no metadata: a `path`, `vcs`, `artifact` or inline `package` entry, or a `type: composer` repository that advertises no notify URL. The finding's `from_composer_repository` is false |
| `metadata_unavailable` | lockrot asked for metadata and did not get it: not listed, offline, budget, transport |
| `branch_snapshot` | The installed version is a branch (`dev-main`, `2.x-dev`): it has a commit date, not a release date, and `pinned` already says what there is to say |
| `no_stable_release_date` | One end has no [trusted date](#dates-from-the-monorepo). The key does not say which end |

`no_stable_release_date` covers:

- A package with no tagged release.

- A newest tag with no trusted date, and nothing dated above the installed version.

- An installed version that only a shared commit dates, with no monorepo parent to date it.

- A lock entry with no `time`.

The keys are an [open set](schema.md#open-sets): read one you do not know as another way a package
went unmeasured ([compatibility.md](compatibility.md#open-sets)).

- **Lower bounds.** When the newest tag has no trusted date, lockrot measures the package to the
  newest release above the installed version that has a trusted date. That release can be on a
  higher branch or on the installed branch. A tag's commit is never younger than the release it
  names, so the value is a lower bound. The HTML report marks it "at least".

- **Ahead of the newest release.** A package locked on a pre-release above it, or on a tag that
  the repository does not list, counts as zero.

What the number is not:

- **A measure of rot or risk.** It counts every drift, healthy patches included, and no advisory
  ([S9](#security-advisories)). An `abandoned` package whose installed release is its last adds
  zero.

- **Another tool's number.** The nearest lockrot number to a tool that sums `composer.json`'s
  direct requirements, such as [php-libyear](https://github.com/ecoAPM/php-libyear), is
  `direct_requirements` from a `--dev` run. lockrot leaves an undated package unmeasured.

## Related

- [example-run.md](example-run.md) — a full recorded run with these verdicts, the priority groups
  and the libyears line
- [configuration.md](configuration.md#extralockrot-keys) — the thresholds, `target-php` and
  `fail-on` keys with their defaults
- [configuration.md](configuration.md#the-allowlist) — marking a package `finished`
- [configuration.md](configuration.md#explaining-one-package) — every fact behind one finding,
  with `--explain`
- [baseline.md](baseline.md) — accepting findings you have decided to live with
- [ci.md](ci.md) — choosing `--fail-on`, exit codes, and what each output format shows
- [notes.md](notes.md) — what each run note means, including the checks S10 reports
- [internals.md](internals.md) — which host needs which token, so fewer findings carry S10
- [schema.md](schema.md) — every JSON field named here, and the release it appeared in
- [compatibility.md](compatibility.md) — which names and orders are frozen, and how a verdict can
  change between releases
