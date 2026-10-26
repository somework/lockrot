---
title: What it reports — flags, the score and the verdicts
description: What each lockrot flag claims, how the flags add up to a score and a grade, which advisories count, what lockrot did not check, and what each fact asks you to do.
---

# What it reports {#verdicts-and-priority}

Every package in `composer.lock` gets the flags that fired on it and a score that adds them up. Its
verdict is the score's grade, or a word that says why the score is 0. The [Flags](#flags) table gives
each flag's rule, its signals, and what you and the package's maintainer can do to clear it.

## The verdicts {#the-nine-verdicts}

| Verdict | When | What it says |
|---|---|---|
| `critical` | The score is 32 or more | How much the finding matters to this project. [The score](#score) shows the sum |
| `high` | The score is 16 to 31 | |
| `medium` | The score is 8 to 15 | |
| `low` | The score is 1 to 7 | |
| `unknown` | The score is 0, no allowlist entry applies, and lockrot read no release metadata for the package | Nothing counts, but lockrot did not judge its release age (S2) or its release branch (S8). The run's [notes](notes.md) say what failed |
| `finished` | The score is 0, and an allowlist entry applies to the package | Nothing counts, and the [allowlist](configuration.md#the-allowlist) says why: the [built-in list](configuration.md#the-built-in-list), a package type or your own entry |
| `ok` | The score is 0 | No flag counts |

Verdict order, used by the grade values of [`--fail-on`](ci.md) and the report's sort order:
`critical > high > medium > low > unknown > finished = ok`.

- A *finding* is any entry in the report's `findings`: one per analysed package, `ok` included. A
  *graded* finding has a grade, `critical` to `low`. `--format=json` and `--format=html` carry
  every finding. The other formats list the score-0 findings only with `--all`.

- A finding's `lead` is its first counted maintenance flag, in flag order, or null. It names the
  cause that heads the score line. No gate or sort reads it.

- `priority` stays in report-2 as an alias of the grade, `none` at score 0
  ([The `priority` alias](#priority)).

- To clear a transitive finding (`via …`), update or replace the direct requirement that pulls it
  in. See [Transitive exposure](#transitive-exposure).

- `--fail-on=unchecked` reads S10, not the verdict, so it can fail a finding of any verdict. See
  [What was not checked](#what-was-not-checked).

- Where lockrot lists verdicts (`counts`, `run.verdicts`, the schema enums, this page),
  `finished` comes before `ok`. Which orders are frozen is in
  [compatibility.md](compatibility.md#closed-sets-and-their-order).

## Flags

A finding lists each flag that fired in `flags`, in flag order: `abandoned`, `silent`, `pinned`,
`left-behind`, `old-promise`, `stale`, `capped`, `vulnerable`. Each flag is one claim that is true whenever it
shows.

| Flag | Rule | Raised by | What you can do | What the maintainer can do |
|---|---|---|---|---|
| [`abandoned`](#abandoned-and-where-to) | The package's Composer repository marks it abandoned (without repository metadata, the lock's own mark), or its repository is archived on its host | S1 or S3 | Move to the [named replacement](#abandoned-and-where-to) or another package | Lift the mark, or unarchive the repository |
| `silent` | No release for `release-high-years` (default 5) **and** no push for `push-high-years` (default 5) | S2 high and S4 high | Replace it, or accept it with an [`ignore` entry](configuration.md#the-allowlist) or the [baseline](baseline.md) | Release and push. Either alone lowers it to `stale` |
| [`pinned`](#what-s6-carries) | The installed version is a branch snapshot (`dev-main`, any other `dev-*` branch, `2.x-dev`), or the package has no tagged release at all | S6 | Require a tagged release | Tag a release, for a package that has none |
| [`left-behind`](#left-behind) | No stable release on the installed version's release branch for `release-warn-years` (default 3), while a higher branch keeps releasing | S8 | Require the branch that the `do:` line names | Release on the old branch |
| [`old-promise`](#old-promise) | The installed release predates the target PHP's major, and its `require.php` admits the target only because it has no upper bound | S5 | Update to a release cut after that major's GA, or to one whose `require.php` names the major | Release after that major's GA, or with a `require.php` that names it |
| `stale` | No release for `release-warn-years` (default 3), or no push for `push-warn-years` (default 3), short of `silent` | S2 or S4 | Replace it, or accept it with an [`ignore` entry](configuration.md#the-allowlist) or the [baseline](baseline.md) | A release inside `release-warn-years` and a push inside `push-warn-years` |
| [`capped`](#capped) | The installed release's `require.php` stops below the newest PHP minor that the root php allows | S11 | Update to the release that S11's `admits_newest` names, or require its branch | Release with a `require.php` that admits the newest PHP |
| `vulnerable` | A counted [security advisory](#security-advisories) affects the installed version | S9 | Follow the `do:` line ([The move](#move)) | Release a fix |

- `abandoned`, `silent` and `stale` are one reading of whether anyone still ships the package, so
  a finding shows at most one of them. `abandoned` says which source fired in `degree.reasons`
  (`marked`, `archived`).

- `pinned` and `left-behind` never fire together: a branch snapshot is on no release branch.

- `old-promise` and `capped` never fire together: S5 needs a `require.php` with no upper bound,
  and S11 needs one with an upper bound.

- Each flag carries `signal_ids`, the signals whose `data` it is written from. Its `headline` holds
  one value for a pill: the years of a quiet package, the PHP major of an old promise, the PHP minor where a cap stops, the reason
  of a pin, or the number of counted advisories.

- **Accepted flags stay listed.** A flag that the built-in allowlist or an
  [`ignore` entry](configuration.md#the-allowlist) accepts keeps its row in `flags`, with the role
  `accepted`. It weighs 0 and reaches no gate. The text formats print it after the score line:
  `0 (left-behind accepted)`. `finding.allowlist` says who accepted it and why.

- **Years never add points.** The thresholds decide whether a flag fires and which liveness word
  applies. A package left behind for 6.9 years scores the same as one left behind for 3.1 years.

## The signals

| Signal | What it observes | Reads | Raises |
|---|---|---|---|
| S1 | The Composer repository marks the package abandoned, and sometimes names a replacement. Without repository metadata, the lock's own `abandoned` mark | Composer repository, else the lock | `abandoned` |
| S2 | Time since the package's newest release (pre-releases count, branches do not), against `release-warn-years` / `release-high-years` | Composer repository | `silent`, `stale` |
| S3 | The repository is archived on its host | Repository host ([which hosts, which credentials](internals.md#repository-hosts-and-credentials)) | `abandoned` |
| S4 | Time since the repository's last activity on any branch, against `push-warn-years` / `push-high-years` ([what each host reports](internals.md#repository-hosts-and-credentials)) | Repository host | `silent`, `stale` |
| S5 | An open-ended `require.php` on a release older than the target PHP's major ([Old promise](#old-promise)) | Lock and target PHP | `old-promise` |
| S6 | A branch snapshot, or no tagged release ([What S6 carries](#what-s6-carries)) | Lock (snapshot), Composer repository (tags) | `pinned` |
| S7 | The graded transitive packages a direct requirement pulls in | Lock and `composer.json` | nothing ([Transitive exposure](#transitive-exposure)) |
| S8 | Time since the newest stable release on the installed release branch, against `release-warn-years` / `release-high-years`, while a higher branch releases ([Left behind](#left-behind)) | Composer repository | `left-behind` |
| S9 | The counted security advisories that affect the installed version | Composer repositories that publish advisories | `vulnerable` ([Security advisories](#security-advisories)) |
| S10 | A check that a flag rests on did not run, and the signals it blocked | The other checks | nothing ([What was not checked](#what-was-not-checked)) |
| S11 | The installed release's `require.php` stops below the newest PHP minor that the root php allows ([Capped](#capped)) | Lock, root php and PHP GA dates. Release metadata for `admits_newest` only | `capped` |

- S2, S4 and S8 are `warn` from the warn threshold and `high` from the high one. Only `silent`
  reads the level. The thresholds are [`extra.lockrot` keys](configuration.md#extralockrot-keys).

- S2's `data` is the release it measures from (`last_version`, `last_release`), the `years` since
  it, and `dated_by`: the [monorepo parent](#dates-from-the-monorepo) that dated it, else null.
  `report-2.json` types the `data` of every signal ([Signal data](schema.md#signal-data)). The
  S6, S8 and S9 sections of this page give their fields.

!!! note "Signals and age"
    A finding's `age` holds four dated readings on the run clock. They date the installed release,
    the newest release, the newest release on the installed branch and the last push. S2, S4 and S8
    quote the reading they judged. `age` is a fact: no score, gate, sort or baseline reads it, and
    its years never add points. The text formats print an `age:` line on a graded row that no S2,
    S4 or S8 dates.

## Abandoned, and where to

`abandoned` covers two cases: a package whose repository names a successor, and one that names
nothing. The report tells them apart here:

| Where | What it says |
|---|---|
| Evidence | The registry's replacement text as written: `replacement: symfony/mailer`, or free text such as `replacement: Symfony` |
| `do:` line | `replace with symfony/mailer (named by its registry)`, `replace it: its registry suggests "Symfony"`, or `find a replacement (none named)` |
| JSON | `replacement`, `replacement_url` and the root `abandoned` counts ([schema.md](schema.md#what-the-report-schema-types)) |

If the successor is already in the lock, remove the old package. An `abandoned` that only S3 raised
(an archived repository with no mark) moves to `find a replacement`.

### Composer's abandoned ignore list {#abandoned}

lockrot reads Composer's abandoned ignore list as `composer audit` reads it: a listed package raises
no S1. The list is `config.policy.abandoned.ignore` on Composer 2.10 and later, and
`config.audit.ignore-abandoned` on Composer 2.9. Older Composer releases have no such list.

- Only the mark goes. An archived repository (S3) still raises `abandoned`, and the release and
  push dates still give `silent` or `stale`. A package whose only fact was the mark reads `ok`, not
  `finished`.

- The finding carries `abandoned_ignored`: the setting that matched (`by`), the matching rules, and
  the mark itself. The text formats print
  `abandoned marking ignored (policy.abandoned: doctrine/cache): <reason>`. The note
  [`abandoned_markings_ignored`](notes.md#abandoned_markings_ignored) lists the packages.

- A rule's version constraint is not applied, as `composer audit` does not apply it.

- Composer's settings that stop `composer audit` from reporting abandoned packages
  (`policy.abandoned.audit: ignore`, `audit.abandoned: ignore` and the others) turn no lockrot check
  off. The note [`abandoned_not_audited_by_composer`](notes.md#abandoned_not_audited_by_composer)
  says so.

- An `ignore[]` entry with `flags: [abandoned]` is not this list. It accepts the liveness reading
  of the package (`abandoned`, `silent` and `stale`), and the flags stay listed as accepted.

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
| `tag_relation` | How the newest tag compares with the snapshot: `older`, `newer` or `same`, else null. An [open set](schema.md#open-sets) |

For a branch alias such as `5.4.x-dev`, the `do:` line names the newest tag on the snapshot's own
line first. [`--explain`](configuration.md#explaining-one-package) shows the same facts under
`metadata`, and the snapshot's date as `lock.released`.

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
`silent`). The flag is `left-behind` at either level, and the signal keeps the level. Abridged
from the [example run](example-run.md):

```text
  smalot/pdfparser v1.1.0  via j0k3r/graby  ·  left-behind
      8 = left-behind 16 ÷ 2 transitive
      left-behind  branch 1.x last released 2021-08-03 (5.2 years ago); 2.x released v2.13.1 (2026-10-13)
```

- The second clause names the higher branch with the most recent release, which can be an LTS
  below the newest major.

- The signal carries the constraint to write as `suggested_constraint`, in the form that
  `composer require` writes (`^0.4.3` below 1.0). The `do:` line names it
  ([The move](#move)).

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

- the project's own `require.php` as `composer.json` writes it, read as the lowest stable version
  it admits: `>=8.2` is `8.2.0`, `>7.1` is `7.1.1`. `run.project_php_lowest` writes that version.

- the target PHP ([`target-php`](configuration.md#extralockrot-keys) or its option and environment
  override, else `config.platform.php`, else the running PHP), at its minor's newest patch
  (`8.4.99`): a branch that does not admit it will not install. The move check solves there
  too, unless `config.platform.php` is in that minor ([limits of the check](#the-move-check)).

When that is not the newest branch, the evidence says which floor blocks the newest branch, then
names the branch within reach. Abridged from the [example run](example-run.md):

```text
  scheb/2fa-bundle v5.13.2  direct  ·  left-behind
      16 = left-behind 16
      left-behind  branch 5.x last released 2022-04-16 (4.5 years ago); 8.x released v8.6.1 (2026-07-10), needs php
                   ~8.4.0 || ~8.5.0 above the project's php >=8.2; 7.x released v7.14.0 (2026-06-12)
```

If no branch that releases is within reach, S8's clause ends `no releasing branch within reach`
and suggests nothing: the way forward is a PHP upgrade, and the `do:` line says so. The flag stays
`left-behind`. `report-2.json` types S8's `data`, `suggested_constraint` included
([Signal data](schema.md#signal-data)). The report's `run` carries the target PHP as
`target_php`, and the project's `require.php` constraint as `project_php`.

#### The PHP test in `--explain` {#the-php-test-in-explain}

[`--explain`](configuration.md#explaining-one-package) applies this test to every branch, the
installed one and those below it included. A row's php is the requirement of its branch's newest
dated release, or of its highest tag when no release on the branch has a date. On the installed
row, that release can be newer than the version you have, whose own requirement is `lock.php`.
Each branch row in the JSON carries:

| Field | Value |
|---|---|
| `admits_target_php`, `admits_project_php` | Whether the branch's php admits that floor. Null where there is nothing to test: the branch requires no PHP, its requirement cannot be parsed, or the floor is missing. Null never means admitted |
| `php_blocked_by` | `project` when the branch misses the project's floor, whether or not it also misses the target. Else `target` when it misses the target. Else null: the branch is within reach. A null `admits_*` blocks nothing. An [open set](schema.md#open-sets) |
| `misses_target_php`, `misses_project_php` | Which side of that floor the branch's php lies on. Null exactly where the matching `admits_*` is not false. An [open set](schema.md#open-sets) |
| `highest_commit_date` | The highest tag's commit date when other tags share that commit, not a release date. Null when the tag has a release date or none |
| `highest_php` | The requirement of the branch's highest tag, which the explain table and the page print beside that tag. It differs from `php` when the newest dated release is not the highest tag. Null when the tag declares no PHP |
| `highest_admits_target_php`, `highest_admits_project_php`, `highest_misses_project_php` | The same answers as `admits_*` and `misses_project_php`, for `highest_php`. The page reads them beside the tag it prints. The other floor fields test `php` |
| `fixes` | For a package with a counted advisory, how many advisories the branch fixes, the release to move to, what holds it and the score after the move ([Fix](#fix)). Null for a package with none |

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

A release whose own `require.php` excludes the target cannot install there at all: Composer refuses
it. That is not an old promise. `finding.installed_php` states the fact, and the text says that
the target does not run the version you have.

Abridged from the [example run](example-run.md):

```text
  mgargano/simplehtmldom 1.5  direct  ·  old-promise · stale
      18 = old-promise 16 + stale 2 [¼ of 8]
      do: test it on PHP 8.4 — 1.5 is its newest release
      old-promise  released 2014-01-05 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
                   untested
```

## Capped

S11 compares the installed release's `require.php` with R, the newest PHP minor that the project
allows. R is the newest minor in `resources/php-ga-dates.json` with a GA on or before the run clock
whose newest patch (`8.5.99` for 8.5) the root php admits. The root php is composer.json's `require.php`, else the lock's
`platform.php`. Without a root php, R is the newest of those minors.

S11 fires when all of these hold:

1. The installed `require.php` has an upper bound.

2. It admits nothing at or above R's newest patch (`8.5.99` for R 8.5).

3. It admits the newest patch of at least one known minor.

A floor above R (`>=8.4.1` under a root that allows up to 8.3) and a disjunction with an open end
are no cap. A constraint that skips R and admits a later minor (`~8.2.0 || ~8.4.0` against R 8.3)
is no cap either. A root php that does not parse, or that admits no known minor, gives no R, and
S11 does not fire. S11 reads `require.php` only. A cap from `php-64bit` or from a `conflict` shows
in the [PHP window](#the-php-window), with no flag.

S11's `data`:

| Field | What it holds |
|---|---|
| `newest_php` | R |
| `newest_php_source` | `known` when R is the newest known minor, `require_php` when composer.json's `require.php` limits it, `platform_php` when the lock's `platform.php` limits it |
| `requires`, `stops_at` | The installed `require.php`, and the newest known minor that it admits |
| `excludes_target` | True when the target PHP's minor is outside `requires`: exactly `installed_php.target_runs` false |
| `admits_newest` | The newest release of the lowest branch whose newest release admits R or states no php, with its `branch` and `blocking` (`project`, `target` or null). Null when no release admits R, or when the release metadata was not read |

- **Which releases count.** `admits_newest` reads the stable releases above the installed one. Above
  an `X.Y.x-dev` snapshot or a snapshot with a branch alias, the releases count from `X.Y.0`. Above
  a `dev-<name>` snapshot, only the releases dated after the lock entry's `time` count. Of those,
  only the releases on the branch of the highest release dated at or before that `time` count, or on
  a higher branch. A backport to a lower branch is a downgrade. With no release dated at or before
  that `time`, every later release counts.

- **Each branch's newest release.** Composer installs the newest release of a branch, so a branch
  counts only through its newest release. When that release states no php, the branch is the
  answer: Composer installs it on any PHP, and `blocking` is null. When it caps php below R again,
  the branch is no answer, although an older release on it admits R. When its php does not parse,
  the branch is no answer either. Then `admits_newest` comes from the next higher branch.

- **R moves with the GA dates.** When a newer minor gets its GA on or before the run clock, a
  package capped below it gains `capped`. S5 reads the same GA dates.

- **The move.** The `do:` line of a `capped` lead goes to `admits_newest`: `blocked` when the
  target PHP does not run it, else `raise-php` when the project's lowest PHP is outside it, else
  `require` for another branch or a held release, else `update`. With no such release, the line
  is `no move: no release above <version> admits PHP <R>`.

- **A move that lands on a cap.** A move of another flag whose release stops below R lists
  `capped` in `next_step.adds[]`, and its line says that `capped` would count.

Abridged from a recorded run on wallabag's lock:

```text
  lcobucci/clock 3.3.1  via lcobucci/jwt, scheb/2fa-trusted-device  ·  capped
      4 = capped 8 ÷ 2 transitive
      do: raise require.php to >=8.4, then move lcobucci/clock to ^3.6.0 through lcobucci/jwt, scheb/2fa-trusted-device
          — clears capped; …
      capped       requires php ~8.2.0 || ~8.3.0 || ~8.4.0: it stops at 8.4, so the newest PHP, 8.5, is excluded
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
  the lock's shared-commit `time` for [libyears](#libyears) and `age.installed`. `--explain` prints
  it as `installed release` (`installed_release_dated_by` in JSON). Where it does not, the
  package is unmeasured.

- A branch the parent does not have, or does not date either, stays unmeasured, as does every
  branch in an install-time run that has spent its [budget](install-time.md#time-budget).

- S2 and S8 carry the parent as `dated_by`, and `--explain` marks the branch rows it supplied.

## Security advisories

`composer audit` reports the vulnerability. lockrot adds the advisory to the package's score, says
which release fixes it and what holds that release, and names the move.

A counted advisory that affects the installed version flags the package `vulnerable`. S9 lists the
counted advisories, the deciding one first, and the finding's `security` block says what the
lookup found. Abridged from the [example run](example-run.md):

```text
  spomky-labs/otphp v10.0.3  via scheb/2fa-google-authenticator  ·  left-behind · vulnerable
      24 = left-behind 16 ÷ 2 transitive + vulnerable 16 [high advisory]
      vulnerable   2 advisories: 1 high, 1 medium — none fixed on 10.x; worst: PKSA-kbc7-dq62-pt7d Unbounded digits
                   parameter in a provisioning URI triggers an uncaught DivisionByZeroError in OTP generation
```

- **Points.** The advisory that scores highest is the security part of the score: its severity's
  points, doubled when no release fixes it or none runs on the target PHP. More advisories do not
  add up. Reach never halves the security part. [The score](#score) shows the sum.

- **Naming.** The evidence names the deciding advisory by its CVE, else its repository id, and
  counts the rest by severity. It calls it `worst` only when its severity is the worst one.

- **The footer.** The `security:` line counts the vulnerable packages and their advisories by
  severity, and the `update now:` line lists the packages that `composer update` fixes.

- **Scope.** The lookup covers the packages the run checked. Without `--dev`, the `security:` line
  adds `packages-dev not checked (pass --dev)`. The JSON document records the scope as
  `include_dev`.

- **Baseline.** The [baseline](baseline.md) records each advisory by id, with its severity and fix
  kind. A new advisory, a higher severity or a lost fix is new to the gate.

When the lookup does not run, or runs in part, the finding carries S10 `{check: advisories}` and
`security.check` reads `partial` or `not_run`:

- `--offline`.

- Composer older than 2.4: the plugin on an LTS Composer. The PHAR bundles a newer one.

- At install time, for the repositories that remain once the budget is spent.

- A repository that failed. The run gets a [note](notes.md#advisories_unavailable) and, under
  `--strict-network`, exit `1` ([exit codes](ci.md#exit-codes)).

- No configured repository publishes advisories (`no_feed`).

What the lookup found still counts. A package with no counted advisory then reads `unchecked`,
never `clear`, in `security.status`. The note [`advisories_not_checked`](notes.md#advisories_not_checked)
names the reason.

### Which advisories count {#which-advisories-count}

lockrot asks for the advisories of every package that the run checks, by name, whatever its
origin: Packagist, a private repository, vcs, a path or an archive. packages-dev are asked only with
`--dev`. `composer audit` asks the same way.

- `extra.lockrot.advisory-lookup: composer-repositories` asks only for the packages installed from
  a Composer repository ([configuration.md](configuration.md#extralockrot-keys)). Every other
  package gets the skip `{check: advisories, reason: not_from_composer_repository}`.

- lockrot asks each configured Composer repository that publishes advisories. A repository without
  a feed is listed with the outcome `no_feed`.

- For a package installed from vcs or a path, the advisory is published for the package of that
  name. Check that the version you hold is the one the advisory names.

- Composer's policy never stops the lookup. Under `policy: false`, `COMPOSER_POLICY=0`,
  `policy.advisories: false` or `policy.advisories.audit: ignore`, lockrot asks and counts every
  advisory. These switches stop Composer from blocking an install or an update. The note
  [`advisories_disabled_by_policy`](notes.md#advisories_disabled_by_policy) says so.

- An advisory with no severity counts as `unrated`. An advisory with no affected range counts, with
  the fix `unknown`.

What each repository answered is data. `security.feeds[]` holds one entry per repository with an
`answer` (`answered`, `failed`, `not_asked`) and the number of `records` it returned for the name,
for every version. `security.records` counts the distinct advisory ids of every answering
repository, and `run.advisory_lookup` lists each repository with its outcome. No score or gate
reads a record count. The `advisories:` footer line prints them:

```text
advisories: 200 packages asked · packagist.org answered (181 on record for 49 packages)
```

### Severity {#severity}

| Composer's severity | Severity | Points | With no reachable fix | Gate rank of `vulnerable-*` |
|---|---|---|---|---|
| `critical` | `critical` | 32 | 64 | 4 |
| `high` | `high` | 16 | 32 | 3 |
| `medium`, `moderate` | `medium` | 8 | 16 | 2 |
| none, or another word | `unrated` | 8 | 16 | 2 |
| `low` | `low` | 2 | 4 | 1 |

- Rows, chips and ties follow the order of the table: `critical`, `high`, `medium`, `unrated`,
  `low`. A medium advisory wins a tie over an unrated one.

- The `vulnerable-*` values of `--fail-on` and the baseline compare severities by gate rank, where
  `unrated` equals `medium`. So a rating of an unrated advisory as medium is not a new fact.

- `severity_published` keeps Composer's own word.

### Fix {#fix}

Each counted advisory gets a fix kind. lockrot reads every stable release above the installed one,
on the installed branch and every higher one, with each release's own `require.php`, and the lock's
links on the package: the root `require` and `require-dev`, every locked package's `require`, and
every `conflict`.

| Fix kind | When | Doubles the advisory |
|---|---|---|
| `update` | A release fixes it, the target PHP and the project's `require.php` admit it, and nothing in the lock holds it back. `composer update <package>` reaches it | no |
| `upgrade` | As `update`, but a requirement or a conflict excludes the release. `held_by` names each holder | no |
| `raise-php` | The target runs the release, and the project's `require.php` does not admit it | no |
| `unknown` | lockrot could not read the releases, or the advisory gives no affected range | no |
| `blocked` | A release fixes it, and the target PHP does not run it | yes |
| `none` | No release fixes it | yes |

- A release fixes an advisory when it and every later release on its branch lie outside the
  advisory's range. S9's `fix.version` is the first such release in the order that the Limits
  paragraph gives, and `fix.newest` is the branch's newest release. On the installed branch,
  `fix.newest` and `installed_branch_fixes.newest` are the newest release that the lock's links
  allow and the target PHP runs: what `composer update` installs there.

- `fix.admits_target_php` and `fix.admits_project_php` are the floor answers of a branch row,
  asked of `fix.php` ([The PHP test](#the-php-test-in-explain)). Null is no answer, never admitted.

- `held_by` names each holder with its constraint and, when the holder is a finding of this
  report, its own verdict and move. An abandoned holder reads
  `held by abandoned symfony/x (replacement: …)`.

- `security.installed_branch_fixes` says how many advisories the installed branch fixes.
  `security.gets` says what `composer update` installs and which advisories it clears.
  `security.partial` says what a `composer update` clears when the move goes further.

- In `--explain`, every branch row carries `fixes`: the advisories it fixes, the release to move
  to, its holders and the score after the move. So you can compare a patch on the installed
  branch with the next major.

- A fix that lockrot could not judge reads "not known", never "not fixed".

Limits: a class reads the lock's links on the package, and Composer's solver checks the move to
the release ([The move check](#the-move-check)). A refused move holds every release that its
constraint admits, and an `update` release becomes `upgrade`, with the holds. When the refusal
names no hold, the release is `blocked`. The fix and the move take the first release in this
order: `update`, `upgrade`, `raise-php` with no holder, `raise-php` with holders, `blocked`.
Within one of these steps, a release that no refused constraint admits comes before a refused
one, and then the lower release comes first. The suggested constraint starts at the release of
the move.

### Accepting advisories {#accepting-advisories}

Accept an advisory where `composer audit` accepts it: `config.policy.advisories` (`ignore-id`,
`ignore`, `ignore-severity`) on Composer 2.10 and later, `config.audit.ignore` from 2.4 and
`config.audit.ignore-severity` from 2.9. What audit drops on the Composer that runs lockrot,
lockrot drops.

- An ignored advisory is listed in `security.ignored[]`, with the setting that matched (`by`) and
  what it matched on (`matched`). It is not in S9 and does not count.

- When Composer rejects the policy section, lockrot ignores no advisory and says so in a
  [note](notes.md#advisory_ignore_unreadable).

- An [`ignore` entry](configuration.md#the-allowlist) and the built-in allowlist accept
  maintenance flags only. A package they cover still counts its advisories, and the row says
  `maintenance accepted`. The note
  [`ignore_does_not_cover_advisories`](notes.md#ignore_does_not_cover_advisories) names each entry
  whose package carries a counted advisory.

- The [baseline](baseline.md) accepts an advisory as known for the gate. It still counts in the
  score.

### The move {#move}

A graded finding's `do:` line names one move: what to run, what it clears, and what holds it. JSON
writes the move as `next_step`. Abridged from the [example run](example-run.md):

```text
  phpseclib/phpseclib 3.0.55  via php-amqplib/php-amqplib, php-amqplib/rabbitmq-bundle  ·  vulnerable
      8 = vulnerable 8 [medium advisory]
      do: composer update 'phpseclib/phpseclib:^3.0.57' (gets 3.0.57) — clears the advisory; Composer's solver checked it against the lock
```

| Kind | For | The line |
|---|---|---|
| `update` | an advisory, an old promise or a cap that the installed branch fixes | `composer update <package> (gets <version>)`, or `update it with Composer (gets <version>)` when the [command argument rule](schema.md#the-move) refuses the step |
| `require` | a fix or a branch that a requirement must allow | `require <package> <constraint>`, or `move <package> to <constraint> through <dependents>` for a package you do not require |
| `raise-php` | a fix or a branch that needs a higher `require.php` | `raise require.php to >=<version>, then require …` |
| `blocked` | no fix or branch runs on the target PHP | `no move runs on the target: …` |
| `replace`, `find-alternative` | `abandoned`, `silent` | the named replacement, or `find a maintained alternative` |
| `tag` | `pinned` | the newest tag, on the snapshot's own line first |
| `test` | `old-promise` with no newer release | `test it on PHP <target>` |
| `no-fix`, `no-single-fix`, `no-tag`, `no-move` | nothing to move to | why, in words |

- **Which move leads.** An `update` that fixes an advisory always leads. Otherwise the move of the
  larger part of the score leads, and the other part's move follows in `next_step.also[]`. Two
  moves to one branch merge into one, with the tightest constraint. Two `blocked` moves to one
  branch merge too, unless one of them names a tag. A `blocked` move never merges with a move that
  runs on the target PHP.

- **A tie of the parts.** The move whose `if_applied.total` is lower leads. A move with a null
  `if_applied` counts as the score of the finding. On an equal total, the security move leads.

- **The PHP it needs.** `next_step.php_check` reads the branch's highest release, the release
  that Composer installs at the top of the constraint. A `blocked` move reads the release that
  keeps it off the target. When your `require.php` does not admit that PHP, the line starts with
  "raise require.php to …", and `commands[]` starts with `composer require php:<version>
  --no-update`. That holds for each kind that runs a Composer request: `update`, `require`,
  `raise-php` and `tag`.

- **What it clears.** `next_step.clears[]` names only what lockrot checked for every version the
  constraint admits. `unverified[]` names what it could not check, and `leaves[]` what the move
  does not touch.

- **The score after it.** `next_step.if_applied` is the score once the move is made, worked out
  by the same rules. It reads `at most` when a fact could not be checked.

- **Through a dependency.** For a package that you do not require, the line names the packages it
  comes in through. When the chain passes another package, the first one reads "({requirer}
  requires it)". Then the line names one action per group of those packages:
    - "change your own package" for your own path package (`through[].local`);
    - "replace" for a package whose locked release is its newest (`through[].newest_is_installed`);
    - "replace or update" for each other package.

    When the newest release of such a package does not require it, the line says so.

- **A new major.** A move to a higher branch says that a new major brings its own requirements.

- **A PHP that the lock records.** When `run.php_window.platform_override` lies outside the minor
  of the target PHP, Composer solves the commands of a move at that PHP, not at the target. The
  line of each move with commands then starts with "First set config.platform.php to your PHP
  <target> release: the lock records <version>." So does the `update now:` line. The commands
  stay the same. When `run.move_check.baseline` is `refused`, the sentence goes on with "Composer
  still refuses the lock itself:" and names where the cause is: the `php:` line when the window
  excludes the target, else the Run tab.

The `update now:` footer line lists every package whose `update` move Composer's solver resolves
with the constraint inline, with one command for all of them.

The table, markdown and github formats, `--explain` and the install-time block print each command
in its POSIX form, for a POSIX shell. For cmd.exe or PowerShell, render `commands[]` as
[The move](schema.md#the-move) shows, or use the copy menu of the html page.

### The move check {#the-move-check}

lockrot asks Composer's own solver about every move that runs a Composer request: the lead move,
each move in `next_step.also[]`, and the move to each branch row's candidate. It builds the request
of `composer update <package>:<constraint> --dry-run`, or of `composer require` for a package that
composer.json requires, and solves it offline, on the lock and the versions that Composer's cache
holds. JSON writes the answer as the move's `check`.

| `check.result` | What it says | The steps in `commands[]` |
|---|---|---|
| `resolves` | Composer installs the move from the lock in `form`: `inline`, `with-dependencies` or `with-all-dependencies`. `check.installs` names the release that it installs | the form that resolves, with `--with-dependencies` or `--with-all-dependencies` when it needs one |
| `refused` | no form installs it: `held_by[]` names the holds that the refusal and a `composer prohibits`-style scan find | no `update` or `require` step, only the `why-not` step |
| `not_checked` | lockrot did not ask. `check.reason` says why | the inline form, unchecked, then the `why-not` step. A `require`, `raise-php` or `tag` move of a package that composer.json does not require gets no package step |

- **Holds.** `held_by[]` adds the links that the solver names, with the package that a link points
  to in `target`:
    - `root-require`: a root requirement on another package that the move needs at another
      version. With no `target`, it is the move's own constraint, and no release that Composer can
      install meets it.
    - `platform`: a requirement on `php`.
    - `require` or `conflict` of the target release, or a `conflict` that composer.json declares.
    - `replace`: a package that replaces `target`, or the moved package when `target` is null.
      Composer installs only one of the two.

    A hold of the package's own release reads `<package> <version>, its own release`, then its
    link. A hold names its holder's verdict only at the holder's locked version, because a
    verdict grades the installed release. A root hold names the composer.json section that lists
    the package, and a `require-dev` hold ends its link with " in require-dev". A root requirement
    that admits the move's release is no hold.

    The text lists the holds of composer.json first, then the package's own releases, then the
    holds on the package, then the other holds. Within each group, a higher verdict comes first,
    then the package name decides.

- **The form.** The first form that installs `newest` resolves the move. When no form installs
  it, the form that installs the highest release resolves the move, the first form on a tie. The
  line then reads "(gets 5.3.8, below the newest 5.3.9)", and `security.gets.version` reads
  `check.installs`.
- **A joint request.** When Composer refuses a move, lockrot asks once more: the move and an update
  of each package that a package hold names. The joint request resolves only when it installs the
  package inside the move's constraint and changes the release of a holder. Then `held_by[]` is
  empty on the move, on its branch row and on the S9 `fix` at its release, and `moves_with[]`
  names each holder whose release the joint request changes. The joint
  request moves the packages that require the package. So the `transitive` reason adds no words,
  and the `unreached` words end at "lockrot found no path from your requirements to it". The step
  names those holders:
  `composer update 'firebase/php-jwt:^7.0.0' laravel/socialite`. The line ends with ", with an
  update of laravel/socialite (to v5.30.0)". A package that composer.json
  requires gets `composer require <package>:<constraint> --no-update` first, then
  `composer update <package>` and the holders. When the joint request is refused, `held_by[]`
  names its holds.
- **The why-not step.** An `update`, `require`, `raise-php` or `tag` move whose check does not
  resolve ends with `composer why-not <package> <version> --locked`. The version is the move's
  `version`.
- **A refusal that names no hold.** The release is `blocked`, so the advisory counts as having no
  reachable fix. The text says that Composer names no hold.
- **A package that you do not require.** When its check resolves, the step is `composer update
  <package>:<constraint>`, which adds no root requirement.
- **A package that composer.json lists.** It gets the `composer require` step of the section that
  lists it, also in a run without `--dev`. For a package that only `require-dev` lists, the step
  adds `--dev`, and the line reads "require <package> <constraint> in require-dev".
- **Update now.** lockrot solves the candidates of `update now:` as one request. A move that
  resolves only through a joint request is no candidate: its own step moves its holders. It leaves out the
  candidates that Composer refuses together with the others, and writes a run note. The members of
  `security.update_now` are the names of the command that Composer resolves. When Composer refuses
  them together, or does not check them, `update now:` is empty, and the note
  [`update_now_refused`](notes.md#update_now_refused) or
  [`update_now_not_checked`](notes.md#update_now_not_checked) says why. When the baseline does not
  resolve, `update now:` is empty too.
- **The baseline.** First, lockrot solves a partial update of a name outside the lock. When
  Composer refuses it, the lock itself does not install on the target PHP. Then no move is checked,
  and the run note [`moves_not_checked`](notes.md#moves_not_checked) gives Composer's problem text.
  When that refusal needs a package that could not be loaded, `run.move_check.baseline` is
  `not_checked` with the load reason (`unloaded_name`, `offline` or `install_time_budget`). Each
  move then reads that reason.

Limits of the check:

- It ignores `ext-*` requirements, as `--ignore-platform-req=ext-*` does. `run.move_check` says so
  once per run (`ignores_extensions`), and the `moves:` footer line names it.
- It solves at `config.platform.php` when that version is in the target PHP's minor, else at the
  newest patch of the target minor (`8.4.99`). Composer reads `8.4` as `8.4.0`. It keeps every
  other `config.platform` override.
- It offers the tagged releases of a package. It offers the dev versions too when the package has
  no tagged release. Composer also offers them when composer.json's
  [`minimum-stability`](https://getcomposer.org/doc/04-schema.md#minimum-stability) or a
  [stability flag](https://getcomposer.org/doc/04-schema.md#package-links) admits `dev` for the
  package, and so does the check. A move to a dev snapshot is
  not a move that lockrot proposes.
- It offers a package that no Composer repository serves, such as a package from a path or VCS
  repository, at its locked version only.
- With `--offline`, a package that composer.json admits at `dev` needs its `~dev` file in
  Composer's cache. Without that file, the move is not checked (`check.reason` `offline`). From
  these repositories, such a package with tags and no dev version also reads `not_checked` with
  `offline`:

    - A repository with no [`metadata-url`](https://getcomposer.org/doc/05-repositories.md#metadata-url-available-packages-and-available-package-patterns),
      only v1 `providers`.
    - A repository whose `packages.json` inlines `packages` beside `metadata-url`.
    - A server that answers 404 for a missing `~dev` file.

- On Composer 2.9 and later, it removes the versions that an advisory affects, as Composer does in
  an update. It reads the settings of Composer's `block` operation: the ignore list, the ignored
  severities and the blocking of abandoned packages. It blocks every name that the update can move,
  packages-dev and new dependencies included. `COMPOSER_NO_SECURITY_BLOCKING` on Composer 2.9.2 and
  later, or `COMPOSER_NO_BLOCKING` on Composer 2.10 and later, set to a true value, turns this
  blocking off, as in Composer. `run.move_check.blocking_disabled_by` and the `moves:` line then
  name the variable.
- When a package that a resolving solve asks for could not be loaded, the solve can resolve at a
  lower release than Composer installs. The move is then not checked, with the load reason
  (`unloaded_name`, `offline` or `install_time_budget`). A refusal that needs such a package reads
  the same.
- Composer blocks only the advisories that it reads. When the advisory lookup did not read a
  package that a resolving solve installs, the move is not checked (`check.reason`
  `advisory_lookup_incomplete`). An `--offline` run, a spent install-time budget and a failed
  repository cause it. A refused solve stays refused. A refused move also stays refused when its
  joint request is not checked, because Composer refuses the move alone.
- On Composer 2.10 and later, an update also removes the versions that a filter list names. Such a
  list is the `malware` list under `config.policy`, which blocks by default, or a custom list there.
  The check does not read these lists. So a move that resolves in lockrot can fail in Composer, or
  install another version.
- It stops a chain of refusals after `Analyzer::CHECK_PASSES` passes. A new request after the
  last one is not checked (`check.reason` `pass_cap`).
- When Composer's solver fails on a request, the move is not checked (`check.reason`
  `solver_error`), and the run note [`move_check_failed`](notes.md#move_check_failed) names the
  failure. The run still writes its report.
- Composer 2.2 and 2.3 accept an inline constraint only on a root requirement. There, the
  `update` move of a package that composer.json does not require gets the plain `composer update
  <package>`. A `require`, `raise-php` or `tag` move of such a package gets no package step.
- The solver stops at the first failing chain. The scan adds what `composer prohibits` names, but
  `held_by[]` can still miss a hold that a deeper chain holds.
- A run without composer.json checks no move (`check.reason` `no_composer_json`).
- A run whose composer.json Composer cannot load checks no move (`check.reason`
  `composer_json_invalid`). Composer refuses such a file, for example for a requirement name that
  is no package name. Composer 2.2 stops the run with its own error instead.

### The PHP window {#the-php-window}

On which PHP minors does your lock install? `run.php_window` answers with require-dev and without.
`with_dev` reads the packages, packages-dev and the root with its `require-dev`. `without_dev` reads
the packages and the root without `require-dev`. `with_dev` is null when the lock has no
`packages-dev` key, because Composer then refuses an install with require-dev. A run writes
`with_dev` and `without_dev`, with or without `--dev`.

Composer does not show the window, and these facts hide it:

- `composer install` checks the lock against the lock's own `platform-overrides`. A change of
  `config.platform.php` in composer.json does not change that check. `platform_override` holds the
  lock's `platform-overrides.php`. Composer checks the lock at that PHP on every machine.
- The platform check that the Composer autoloader runs checks only the lower bound of `php` and
  `php-64bit`. It checks only the packages without require-dev.

How lockrot reads the window:

- It reads the `require` and `conflict` links on `php` and `php-64bit` of each package of the lock.
  On a 64-bit PHP, Composer gives `php-64bit` the version of `php`, so lockrot reads each link as a
  version.
- It reads the root's links per name: composer.json first, then the lock's `platform` for a name that
  composer.json lacks. A run without composer.json reads the lock's `platform` alone.
- In the set with require-dev, a `require-dev` link replaces the `require` link of the same name.
  `platform-dev` replaces `platform` in the same way. So require-dev can widen the window as well as
  narrow it.
- `php-zts`, `php-debug` and `php-ipv6` are host features, not versions. `host_requires` lists the
  ones that a requirement names, and the window ignores them.
- A known minor is a PHP minor whose GA date is on or before the run's date. A known minor is in
  `minors` when every requirement admits its newest patch (`8.4.99`) and no conflict matches it.
- lockrot reads the target's minor at the PHP that [the move check](#the-move-check) solves at
  instead, and `target.php` holds that PHP. The move check reads `config.platform.php` from
  Composer's config, the global `config.json` included. A cap inside a minor, such as `<8.4.5`, leaves that minor out.
- lockrot reads the edges and `gaps` on the known minors. PHP 6 and PHP 7.5 to 7.9 do not exist, so
  `~7.4.0 || ~8.0.0` has no gap.
- `lower_edge.php` is the lowest PHP of the first minor in the window. `lower_edge.set_by` lists the
  links that exclude the PHP just below it.
- `upper_edge.excludes` is the known minor after the window, and `upper_edge.set_by` lists the links
  that exclude it. `upper_edge` is null when the newest known minor is in the window.
- A set with no minor has `closest`: the known minors that the fewest links exclude, and those
  links.
- `target.inside` says whether the set with require-dev admits the target PHP. When `with_dev` is
  null, `without_dev` decides. While `target.inside` is false, Composer refuses the baseline of
  [the move check](#the-move-check), and every move is `not_checked`. `target_fix` then gives
  Composer's answer to one update of the packages that exclude the target.

The window is null when a package or the root provides or replaces `php` or `php-64bit`, or when a
php link does not parse. It judges no package and changes no move.

#### The update that admits the target {#the-update-that-admits-the-target}

When the window excludes the target PHP, `target_fix` gives one update of the packages that exclude
it. It also gives the answer of Composer's solver:

- `packages` lists the package `require` links that fail at `target.php` and the package
  `conflict` links that match it, on the set that `target.inside` reads. Production packages come
  first, then packages-dev, each by name.
- lockrot solves `composer update` of those packages at `target.php`, with no constraint. It tries
  the packages alone, then `--with-dependencies`, then `--with-all-dependencies`, as
  [the move check](#the-move-check) does.
- `check` is `resolves`, `refused` or `not_checked`. On `resolves`, `command` holds the update that
  resolved. On `refused`, `held_by` lists the links that the refusal names, as a move's `held_by[]`
  does. On `not_checked`, `reason` says why, and it is null when the run checked no move.
- A root link on `php` or `php-64bit` that excludes the target is the root ceiling. No update can
  admit the target, so `check` is `refused` with no solve. `held_by` names each such root link with
  its section: `require`, `require-dev` or `conflict`.
- `lock_platform` lists the root `require` links that exclude the target and that only the lock's
  `platform` or `platform-dev` holds. composer.json has no link of that name, and Composer writes
  the lock's platform from composer.json on every update. So such a link is no root ceiling and no
  hold. With no package listed, lockrot solves an update that names no package, and `command` is
  `composer update --lock`. On a run with no composer.json, the lock's platform is the root, and
  `lock_platform` is empty.
- `platform_step` is true when `config.platform.php` lies outside the target minor. The solve then
  runs at the target. Before you run the command, set `config.platform.php` to your release of the
  target PHP. The lock's `platform-overrides` does not set it.
- A listed name that is no Composer package name reaches no solve. `check` is then `not_checked`
  with the reason `unloaded_name`. When the move check does not check its baseline, `reason` is
  the reason of the baseline, not `unloaded_name`.

`target_fix` is null when the window admits the target, and when the target lies below the lowest
edge of the set that `target.inside` reads. No update of a package can lower that edge.

The `php:` line of the table, markdown and github formats prints the window. When the minors of
`with_dev` differ, the line prints `with_dev` and `without_dev`. The line adds the target when the
window excludes it. With `target_fix`, the line ends with its answer. That is the command, the holds
of the refusal, the root links of the root ceiling, or the reason that the run did not check it.
Each `lock_platform` link adds "the lock records …, which composer.json does not have" last, with
the update that rewrites it. A
root hold on a package of `packages-dev` ends with " in require-dev". Without
`target_fix`, the line adds the PHP of `platform_override` and the links that set one edge. That is
the lowest edge when the target lies below it, else the edge after the last minor of the analysed
set. Abridged from the [example run](example-run.md):

```text
moves: checked with the solver of Composer 2.10.3 against the lock; ext-* requirements not checked
php: the lock installs on PHP 8.2 to 8.4; lcobucci/clock excludes 8.5
```

wallabag 2.6.14 at the target PHP 8.4, whose composer.json requires `php-http/guzzle5-adapter ^2.0`:

```text
php: the lock installs on PHP 7.4, so your target, 8.4, is excluded; Composer's solver at PHP 8.4 refuses the update of the 3 packages that exclude it: composer.json requires php-http/guzzle5-adapter ^2.0
```

## The score {#score}

The score adds up the counted flags. Every report carries the whole model in `run.score_model`, and
[Score model 1](verdicts/model-1.md) gives each rule with its numbers.

| Term | Points |
|---|---|
| `abandoned`, `silent` | 32 |
| `pinned`, `left-behind`, `old-promise` | 16 |
| `stale`, `capped` | 8 |
| each maintenance flag after the lead | a quarter of its points |
| `vulnerable` | the highest advisory: critical 32, high 16, medium 8, unrated 8, low 2 |
| no reachable fix (`none`, `blocked`) | that advisory × 2 |
| transitive or unreached | maintenance ÷ 2 |
| packages-dev | the whole score ÷ 2 |

```text
maintenance = the lead in full + ¼ of each other counted maintenance flag
security    = the points of the highest-scoring counted advisory
score       = ⌊ (maintenance ÷ 2 if not direct + security) ÷ 2 if packages-dev ⌋
```

The score line writes the sum, and it evaluates to the score:

```text
24 = left-behind 16 ÷ 2 transitive + vulnerable 16 [high advisory]
18 = (silent 32 + old-promise 4 [¼ of 16]) ÷ 2 transitive
8 = vulnerable 16 [unrated advisory 8 × 2: no reachable fix] ÷ 2 dev
```

- A corroborating flag prints its share: `old-promise 4 [¼ of 16]`.

- A halving prints its reason: `transitive`, `unreached` (nothing in `composer.json` reaches the
  package) or `dev`. A halving that changes nothing is left out of the line.

- An exact value that is not whole prints after the line: `4 = … (4.5, rounded down)`.

- An accepted flag prints after the line, and adds nothing: `0 (left-behind accepted)`.

- The JSON `score` holds every step: `terms[]` with each flag's weight, share and contribution,
  `modifiers[]` with each halving, `parts` with each part's contribution, `band` with the distance
  to the next grade, and `decided_by` (`maintenance`, `security`, `combination` or `either`).

- `score.without[]` gives the score without each counted flag, and `score.accepted[]` the score if
  an accepted flag counted. Both are new runs of the rules, not a subtraction: when the lead goes,
  the next flag takes the lead in full. A row reads `at least` when a check that did not run could
  raise it, or when the `tag` move lands on a line that S8's rule can leave behind.

- A package required only through a name that another locked package replaces or provides is
  reached through that package, so it is not `unreached`.

Findings sort by verdict, then by the security part, highest first, then by score, then direct
before transitive, then by package name. So inside a band, a package with a serious advisory comes
first.

### The `priority` alias {#priority}

report-2 keeps `priority` beside `verdict`: the grade of a graded finding, else `none`. `priority`
and the root `priorities` are [deprecated](compatibility.md#deprecation): read `verdict` and the
grades in `counts`. Without a baseline, `priority` predicts the gate. Under a baseline it is still
the full grade, while the gate reads `min(score, 2 × new)`, so read `gate.fails` for what fails
([Baseline](baseline.md)).

### The grade in each format {#priority-in-each-format}

Every output format carries the grade. The `table` groups findings under it, and the JSON gives
each finding `verdict`, `lead`, `flags` and `score`. SARIF ranks each result by its own term. No
record identity holds the grade, so a grade change opens no new record. Each format's mark (GitHub
level, GitLab `severity`, SARIF `level`) follows `--fail-on`
([marks](ci.md#how-each-format-marks-a-finding)). Each format's section in
[ci.md](ci.md#choosing-a-format) shows where the grade appears.

## Transitive exposure

You can act only on what `composer.json` names. For a transitive finding, the report says which
direct requirements pull it in. For a direct requirement, it says which graded packages it pulls
in.

### Every direct requirement that reaches a package

The `via` column of the table and the markdown report names the first three direct requirements
that reach the package, then counts the rest (`a, b, c and 2 more`). The github and gitlab messages
end with the same list. `--format=json` carries the full list as `direct_dependents` on every
finding, the package itself included when it is direct. `chain` holds the shortest path from one of
them. A direct requirement's own row reads `direct`, whoever else reaches it.

If you remove one requirement, every other one that reaches the package still installs it.

### S7 on the direct requirement

Each direct requirement whose subtree holds attributed graded packages gets S7. S7 lists them in
report order, with the shortest chain to each. A graded package that the project requires directly
counts under no requirement, its own included, whoever else reaches it. Abridged from the [example
run](example-run.md):

```text
  wallabag/rulerz-bundle dev-master  direct  ·  pinned
      16 = pinned 16
      pulls in 15 flagged packages: hoa/consistency (abandoned), hoa/compiler (abandoned), hoa/event (abandoned),
      hoa/exception (abandoned), hoa/file (abandoned) and 10 more
```

The evidence names the first few, each with its lead or `vulnerable`. `--format=json` carries them
all under the signal's `data.packages`, each with its `verdict`, `lead`, `flag_ids` and `chain`. A
direct requirement that scores 0 carries S7 too: its row prints only under `--all`, and the
`pulled in by:` line counts it either way.

### Shared packages and `unattributed` {#shared-packages-and-unattributed}

A graded transitive package reached from more direct requirements than the run's
`exposure_rule.max_fan_in` is shared infrastructure, such as a framework's own contracts reached
from every bundle, and nobody's to remove. The JSON document records the value the run used. Such
a package:

- Is not in S7 or in the `pulled in by:` line.

- Keeps its own row, and `direct_dependents` still names every parent.

- Is listed in the JSON document under `unattributed`, with its `verdict`, `lead` and `fan_in` (how
  many direct requirements reach it), in report order.

Fan-in counts the run's direct requirements, so under `--dev` `require-dev` counts too: a package
can be attributed without `--dev` and shared with it. A graded transitive package no direct
requirement reaches is in neither list: every one in a run without `composer.json`.

### The `pulled in by:` line

The summary block counts, per direct requirement, the attributed graded packages it pulls in, most
first. The line names the first few requirements and counts the rest. The JSON document carries
the whole list as `exposure`. A direct requirement appears only when it pulls in an attributed
package, and lockrot prints the line only when some graded package is attributed.

Limits:

- The graph is the lock's `require` edges. A requirement on a name that no locked package carries
  leads to each package that replaces or provides it (a virtual package such as
  `psr/log-implementation`), whatever version that package provides. The `via` column reads `?`
  when nothing reaches the package.

- At install time, lockrot analyses only the packages that the transaction touches. A direct
  requirement gets S7 only when it is in the transaction, and the [compact block](install-time.md)
  prints neither S7 nor the other parents. `composer lockrot` on the full lock has the whole
  picture.

## How deep the rot sits {#rot-depth}

A graded package that no line of `composer.json` names is out of your direct reach: you move it
through the packages that require it. The `depth:` footer line counts the graded packages by how
far below `composer.json` they sit. From the [example run](example-run.md):

```text
depth: 34 of 54 flagged packages are named by no line of composer.json · direct 20 · 1 hop 18 · 2 hops 9 ·
3 hops 5 · 4 hops 2 ·
deepest: hoa/ustring and hoa/zformat, 4 hops (wallabag/rulerz › hoa/ruler › hoa/compiler › hoa/regex › hoa/ustring)
```

- A finding's `depth` is the number of hops from the nearest `composer.json` requirement the run
  walked: 0 for a direct requirement. It is null when nothing reaches the package, and
  `depth_unmeasured` says why: `unreached`, or `no_composer_json` in a run that read no
  `composer.json`.

- `named_in` lists the `composer.json` sections that name the package, or a name it replaces or
  provides: `require`, `require-dev`, or none. A package that `composer.json` names only in
  `require-dev` is reached through others in a run without `--dev`.

- The root `rot_depth` counts the graded findings by reach, by depth, by section and by
  production and development, and names the deepest ones with their chain.

Depth is context, as S7 is. No score, gate or sort reads it: reach is the only part of the graph
that the score reads.

## What was not checked

`ok` means no flag counts: every check ran and found nothing, or a check never ran. Two lists on
the finding tell the cases apart:

- `checks_missing[]`, raised as S10: a check lockrot did not run on a package it judges. Most such
  gaps close on a rerun with a token, the network or a newer Composer. `--fail-on=unchecked` fails
  on them.

- `checks_skipped[]`: a check that lockrot chose not to run, by the project's own choice, on the
  project's own code, or because the answer is not a gap (a 404). It raises no S10 and reaches no
  gate.

A row with S10 adds `checks missing: S2, S8 (undated releases)`. A score-0 row, which the text
formats print only under `--all`, keeps its verdict and adds the same `checks missing:` line.

| Check | S10 `reason` | Cause | Blocks |
|---|---|---|---|
| `repository_activity` | `no_token` | No credentials for the host, and the package was not among those asked about | S3, S4 |
| `repository_activity` | `anonymous_budget` | A candidate the anonymous request budget could not fit | S3, S4 |
| `repository_activity` | `install_time_budget` | The install-time budget ran out | S3, S4 |
| `repository_activity` | `rate_limit` | The host answered "too many requests" | S3, S4 |
| `repository_activity` | `fetch_failed` | A timeout, a transport error or an unreachable host | S3, S4 |
| `repository_activity` | `offline` | `--offline` | S3, S4 |
| `repository_activity` | `host_not_supported` | The repository is on a host that lockrot cannot ask ([host note](notes.md#repository_activity_host_not_supported)) | S3, S4 |
| `repository_activity` | `not_from_composer_repository` | The package comes from vcs, or from an origin that lockrot cannot tell: lockrot asks no host for it | S3, S4 |
| `release_metadata` | `not_from_composer_repository` | The same origins: no Composer repository serves its releases | S2, S8 |
| `release_dates` | `undated_releases` | A release that S2 or S8 reads has no [trusted date](#dates-from-the-monorepo), and no monorepo parent dates it | S2 when the highest tag is undated. S8 when the installed branch's release is undated and a higher branch exists. S8 also when a higher branch's release is undated, the installed branch has no release for `release-warn-years`, and S8 did not fire. Never S8 on a branch snapshot, which has no release branch |
| `advisories` | `offline`, `composer_too_old`, `install_time_budget`, `lookup_failed`, `no_feed` | The advisory lookup did not run, or ran in part ([Security advisories](#security-advisories)) | S9 |
| `releases` | `releases_unknown` | A counted advisory's fix is unknown because the package's release data did not come | S9 |

S10's `data` lists each missing check as `unchecked` (`{check, reason, blocks}`) and every blocked
signal under `blocks`. `checks_missing[]` holds the same entries.

**Origins outside a Composer repository.** A package that no Composer repository serves has no
release metadata, so its maintenance is not judged (`maintenance_judged: false`). Its advisories are
still asked by name. The finding's `origin.kind` decides where its two maintenance checks go:

| `origin.kind` | `release_metadata` and `repository_activity` | `--fail-on=unchecked` |
|---|---|---|
| `vcs` | `checks_missing[]` (S10) | fails, unless [`accept-unchecked`](configuration.md#accept-unchecked) accepts both checks |
| `unknown`: lockrot cannot tell which repository served it, for example asset-packagist.org or a Satis that leaves no notification-url | `checks_missing[]` (S10) | fails, unless `accept-unchecked` accepts both checks, by `package` |
| `path`: the project's own code | `checks_skipped[]` | never |
| `artifact`, `package`, when `composer.json` lists the repository | `checks_skipped[]` | never |

The skips of `checks_skipped[]`:

| Check | Skip `reason` | When |
|---|---|---|
| `repository_activity` | `allowlisted` | An allowlist entry accepts the whole package, so lockrot does not ask the host |
| `repository_activity` | `no_repository` | The package names no repository URL that lockrot can place |
| `repository_activity` | `repository_not_found` | The host answered 404: an answer, not a gap |
| `repository_archived` | `no_token`, `fetch_failed` | The host answered, without the archived flag: anonymous GitLab, or a failed GitLab project request. S3 cannot fire |
| `release_metadata` | `unavailable`, `not_found` | The metadata did not come. The package reads `unknown` when nothing else counts, and [`--strict-network`](ci.md#exit-codes) fails on the fetch |
| `release_branch` | `branch_snapshot` | A branch snapshot has no release branch for S8 |
| `advisories` | `not_from_composer_repository` | `advisory-lookup: composer-repositories`, and the package is not from a Composer repository |
| `advisories` | `unparseable_version` | Composer cannot parse the installed version |
| any | `accepted_by_config` | An [`accept-unchecked`](configuration.md#accept-unchecked) entry accepted the S10 entry. `unchecked_accepted[]` holds it |
| any | `not_from_composer_repository` | The `path`, `artifact` and `package` origins above |

- lockrot raises S10 only where the missing check could change the score. So a package whose S1
  fired, or that an allowlist entry accepts whole, writes its maintenance gaps as skips. Its
  `advisories` and `releases` gaps stay S10.

- Credentials for every host remove `no_token` and `anonymous_budget`. `rate_limit`, `fetch_failed`,
  `offline` and `install_time_budget` can still occur, and `release_dates` asks no host, so a fully
  credentialed run can still carry S10.

- Three gaps stay whatever the credentials: `host_not_supported`, `no_feed` and
  `not_from_composer_repository`. An [`accept-unchecked`](configuration.md#accept-unchecked) entry
  accepts each one by name, with a reason. It hides no flag and changes no score. `undated_releases` can stay too, and no entry
  accepts it.

- A finding's `activity` says what the repository activity lookup did: `status` `read`,
  `not_found` or `not_read`, the `reason` when it did not read, the host and the repository, and
  whether the host marks the repository archived.

- `--fail-on=unchecked` fails the run on any finding that carries S10. It catches a workflow that
  does not pass `GITHUB_TOKEN` to lockrot ([ci.md](ci.md#what-unchecked-fails-on)).

## Libyears

Libyears give one number for how far behind the whole lock is. The number decides no verdict.
From the [example run](example-run.md):

```text
libyears: at least 187.9 behind across 194 of 200 packages · 119.8 from direct requirements ·
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

Each finding carries `libyears`, `libyears_unmeasured` and `libyears_at_least`, and the document
carries a root `libyears` block ([schema.md](schema.md#libyears)).

lockrot checks the keys in this order and counts an unmeasured package under the first that
applies:

| `unmeasured` key | When |
|---|---|
| `not_from_composer_repository` | lockrot asked for no metadata: a `path`, `vcs`, `artifact` or inline `package` entry, or a `type: composer` repository that advertises no notify URL. The finding's `from_composer_repository` is false |
| `unavailable`, `not_found` | lockrot asked for metadata and did not get it, or the repository does not list the package. Equal to `metadata.status` |
| `branch_snapshot` | The installed version is a branch (`dev-main`, `2.x-dev`): it has a commit date, not a release date, and `pinned` already says what there is to say |
| `shared_commit`, `undated` | The installed release has no date lockrot trusts: its commit carries other tags, or the lock entry has no `time`. The same ids as `age.installed_unmeasured` |
| `no_stable_release` | The package has no tagged release |
| `undated_releases` | The package has tagged releases, and none has a trusted date. The same id as `age.release_unmeasured` |

The keys are an [open set](schema.md#open-sets): read one you do not know as another way a package
went unmeasured ([compatibility.md](compatibility.md#open-sets)).

- **Lower bounds.** When the newest tag has no trusted date, lockrot measures the package to the
  newest release above the installed version that has a trusted date. That release can be on a
  higher branch or on the installed branch. A tag's commit is never younger than the release it
  names, so the value is a lower bound: `libyears_at_least` is true, and the root counts such
  findings in `at_least_count`. The line then reads `at least`.

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

- [Score model 1](verdicts/model-1.md) — each rule of the score, with its numbers
- [example-run.md](example-run.md) — a full recorded run with these flags, the grade groups and
  the footer lines
- [configuration.md](configuration.md#extralockrot-keys) — the thresholds, `target-php`,
  `advisory-lookup` and `fail-on` keys with their defaults
- [configuration.md](configuration.md#the-allowlist) — accepting a package's maintenance flags
- [configuration.md](configuration.md#accept-unchecked) — accepting a check that lockrot cannot run
- [configuration.md](configuration.md#explaining-one-package) — every fact behind one finding,
  with `--explain`
- [baseline.md](baseline.md) — accepting findings you have decided to live with
- [ci.md](ci.md) — choosing `--fail-on`, exit codes, and what each output format shows
- [notes.md](notes.md) — what each run note means, including the checks S10 reports
- [internals.md](internals.md) — which host needs which token, so fewer findings carry S10
- [schema.md](schema.md) — every JSON field named here
- [compatibility.md](compatibility.md) — which names and orders are frozen, and how a verdict can
  change between releases
