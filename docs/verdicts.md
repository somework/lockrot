---
title: lockrot verdicts and priority — abandoned, silent, pinned
description: The nine verdicts lockrot gives a package in composer.lock, the signals behind each one, and how direct, transitive and dev dependencies set the priority.
---

# Verdicts and priority

Every package in `composer.lock` gets exactly one verdict and one priority. The verdict says what
was observed about the package. The priority says how much that applies to *your* project.

## The nine verdicts

| Verdict | Meaning | Signals |
|---|---|---|
| `abandoned` | The package's Composer repository marks it abandoned (Packagist by default), or its repository is archived on GitHub or GitLab | S1 or S3 |
| `silent` | No stable release for at least `release-high-years` (default 5y) **and** no repository push for at least `push-high-years` (default 5y); an archived repository is reported as `abandoned` instead | S2 high AND S4 high, NOT S1, NOT S3 |
| `pinned` | Installed version is a branch snapshot — `dev-master`, `dev-main`, any other `dev-*` branch, a `2.x-dev` alias or a `#hash` reference — or the package has no stable release at all | S6 |
| `left-behind` | No stable release on the installed version's release branch for at least `release-warn-years` (default 3y), while a higher branch has released since and within `release-warn-years` — the package is alive, the branch you are on is not | S8 |
| `old-promise` | The installed version was written for an older PHP major, or for none at all (`>=7.2`, `*`), and released before the target's major existed, and its `require.php` admits the target only because it has no upper bound | S5 |
| `stale` | Old release or old push, but not old enough (or not on both fronts) for `silent` | one of S2/S4 |
| `unknown` | No data could be obtained (not found in any configured Composer repository, or all lookups failed) | — |
| `finished` | Matched the built-in or project allowlist — the package is complete by design, not neglected | allowlist match |
| `ok` | None of the above | — |

Severity order, used by `--fail-on` and the baseline:
`abandoned > silent > pinned > left-behind > old-promise > stale > unknown > finished = ok`.

`finished` and `ok` sit equal and lowest in that ordering. Like `unknown`, neither is ever
flagged: they have priority `none`, no verdict or priority threshold reaches them, and they are
listed only with `--all`. `--fail-on=unchecked` is the exception, because it reads S10 rather than
the verdict: a finding carrying S10 fails the run whatever its verdict, `ok` and `unknown` included
(an allowlisted, `finished` package never carries S10). Where lockrot lists verdicts — `counts`, `run.flagged_verdicts`, the schema enums, this page —
`finished` comes before `ok`. The order, and the six flagged verdicts above `unknown`, are frozen for
1.x — see [compatibility.md](compatibility.md#closed-sets-and-their-order).

An allowlist match never competes in the order at all. It is checked before any signal is read and
always wins, so an allowlisted package reports `finished` whatever its signals say — see
[configuration.md](configuration.md).

## The signals

| Signal | What it observes |
|---|---|
| S1 | The Composer repository marks the package abandoned, sometimes naming a replacement |
| S2 | Time since the last stable release, against `release-warn-years` / `release-high-years` |
| S3 | The repository is archived — on GitHub, or on GitLab when the run has credentials there (the anonymous API hides the flag); Bitbucket Cloud has no archived state |
| S4 | Time since the last push to any branch (GitHub) or the newest commit on any branch (GitLab, Bitbucket), against `push-warn-years` / `push-high-years` |
| S5 | The installed release predates the GA of the target PHP's major, and its `require.php` was written for an older major with no upper bound — it admits the target on a promise made before the major existed |
| S6 | The installed version is a branch snapshot (`dev-master`, `dev-main`, `2.x-dev`, `#hash`), or the package has no stable release |
| S7 | A direct requirement pulls in flagged transitive packages — informational, never a verdict; see [Transitive exposure](#transitive-exposure) |
| S8 | Time since the last stable release on the installed version's release branch, against `release-warn-years` / `release-high-years`, counted only when a higher branch has released since and within `release-warn-years`; see [Left behind](#left-behind) |
| S9 | Security advisories affecting the installed version — never a verdict; raises the priority where no fix is coming; see [Security advisories](#security-advisories) |
| S10 | A check the verdict rests on did not run, and the signals it blocked — informational, never a verdict; see [What was not checked](#what-was-not-checked) |

S3 and S4 come from the repository host — GitHub, GitLab or Bitbucket — and need network access;
see [internals.md](internals.md) for how that data is fetched and cached, which host reads what, and
[configuration.md](configuration.md) for the thresholds.

S8 reads the same release dates S2 does, one branch at a time — see [Left behind](#left-behind).
S9 comes from the same Composer repositories, through the advisory API `composer audit` uses.

S5 is not what `composer check-platform-reqs` checks. That command tests the platform against each
constraint — PHP 8.4 satisfies `>=7.2`, so it passes — while S5 tests the constraint against the
release history: a `>=7.2` written in 2019 says nothing about PHP 8, which did not exist. The line
is the GA of the target's *major* (8.0, 2020-11-26, for any 8.x target), not of the target minor:
nothing older than PHP 8.4 was tested on 8.4, `^8.0` included, and a `>=7.2` cut in 2022 with PHP
8.1 on every CI matrix differs from a `^7.2 || ^8.0` of the same day in spelling alone. A
constraint that names the target's major (`^7.2 || ^8.0`) is a promise its author made on purpose
and is never S5. Composer's own convention is silent on the upper bound — Symfony writes `>=8.2`,
Laravel `^8.2` — and S5 does not fault the style; it reads the date.

```text
  old-promise  matomo/decompress 2.1.0  direct
               released 2020-01-11 for PHP 5 (php ">=5.3.2"), before PHP 8 existed (8.0 GA
               2020-11-26); admits 8.4 untested
```

Every finding's evidence line states the concrete fact — release date, push date, constraint string
— and the report footer states the data date. There are no severity words beyond the verdict names
above.

## Abandoned, and where to

`abandoned` is one verdict for two situations. Packagist's marker comes with a free-text
`replacement` field, and a good share of abandoned packages carry one: on the weekly watch over
twenty applications, 19 of 72 did, 17 of them naming a package. For the reader those are different
findings — `swiftmailer/swiftmailer` says to go to `symfony/mailer`, `hoa/ruler` says nothing — and
the report keeps them apart without splitting the verdict (the verdict is what `--fail-on`, the
baseline, the SARIF rule and every count key on). Each finding carries `replacement` in
`--format=json`: the package the repository names, when what it names is a Composer package name.
`Symfony` (sensio/framework-extra-bundle) and `EnglishInflector from the String component`
(doctrine/inflector) are what Packagist has on record, and they stay in the evidence as text, not
in the field. The document carries the split next to the counts —
`"abandoned": {"total": 7, "with_replacement": 6}` — and the summary line says
`abandoned 7 (6 with a replacement)` where the number is not zero. The HTML page tags the row with
the replacement and links it on the package card.

A named replacement is where to go, not a string to swap: `psr/http-factory` for
`php-http/message-factory`, `symfony/error-handler` for `symfony/debug` and `web-token/jwt-library`
for four `web-token/jwt-*` packages are migrations, not renames. Where the replacement is already in
the lock, the work left is removing the old package.

## Left behind

`composer outdated --major-only` says a newer major exists. S2 says nothing, because the package's
newest release is exactly the one that is fresh. Neither says that the branch you are on gets no
fixes any more.

A version's *release branch* is what a caret constraint on it would stay inside: the major for
anything `>= 1.0` (`1.x`, so `1.2` and `1.9` share it and `2.0` does not), the major and minor
below that (`0.3.x` for `0.3.*`, as `^0.3` has it), and below `0.1` the patch alone (`^0.0.3` is
`>=0.0.3 <0.0.4`, so `0.0.3` is a branch of its own). S8 takes the newest stable release on the
installed branch — a backport on a lower minor counts, a pre-release does not — and measures its
age against `release-warn-years` / `release-high-years`, the S2 thresholds. It fires only when some
higher branch has released *after* that date and within `release-warn-years` of today: a `2.0` that
was abandoned before `1.x` got its last release is not the upstream moving on, and a package whose
every branch went quiet years ago is not alive — that is S2's case, `stale` or `silent`, not S8's.
The verdict is `left-behind` at either threshold. Three years without a release is `stale` for a
package, because a package can simply be done; for a branch below one that keeps shipping it is
the branch left, and the higher branch's releases are the proof. The level (`warn` past three
years, `high` past five) stays on the signal, in `--format=json` and in the evidence's years.

```text
  left-behind  smalot/pdfparser v1.1.0  via j0k3r/graby
               branch 1.x last released 2021-08-03 (5.1 years ago); 2.x released v2.12.5
               (2026-04-17)
```

S2 has nothing to say — the package released five months ago — and S5 has nothing either: v1.1.0
was cut in 2021, with PHP 8 on the table. The branch installed here has been quiet for 5.1 years
while 2.x kept going. For a package the project requires itself the evidence adds what to change —
`require ^2.12 to follow` — the constraint written as `composer require` would write it (`^2.12`
from v2.12.5; `^0.4.3` below 1.0), so it can be pasted into `composer.json` or handed to a bot; a
transitive package's parent owns that line, so there the clause stays off. The constraint is on
the signal's data as `suggested_constraint` in every case. The second clause names the higher
branch whose release is newest, with that release:
it is where fixes land now, which with a living LTS below the current major can be the LTS.

### Within reach

The newest branch proves the upstream moved on; it is not always one the project can follow.
Matomo 5.13 supports `php >=7.2.5` and locks monolog 1.27.1; monolog 3.x requires `php >=8.1`, so
`require ^3.12` is a line Matomo cannot write without dropping PHP 7 — and 2.x, which requires
`>=7.2` and released last month, is the branch it can move to. S8 holds every higher branch to two
floors: the project's own `require.php` as written in `composer.json`, and the target PHP
(`config.platform.php` or the running PHP, the version Composer resolves against — a branch outside
it will not install at all). The branch it tells the project to follow is the newest releasing one
within both, and when that is not the newest branch the evidence says what holds the newest back:

```text
  left-behind  monolog/monolog 1.27.1  direct
               branch 1.x last released 2022-06-09 (4.3 years ago); 3.x released 3.12.0
               (2026-09-09), needs php >=8.1 above the project's php >=7.2.5; 2.x released 2.11.1
               (2026-09-02); require ^2.11 to follow
```

When no releasing branch is within reach the evidence ends with `no releasing branch within reach`
and suggests nothing: the way forward is a PHP upgrade, which is not a `composer.json` line. The
verdict is `left-behind` either way — the branch installed is the one the upstream left. In
`--format=json` the signal carries `newest_php`, `newest_within_reach`, `floor_php` and
`floor_source` (`project` or `target`), and `reachable_branch`, `reachable_version` and
`reachable_release` for the branch `suggested_constraint` follows; `--explain` lists each branch's
php requirement in its branch table. A branch that requires no PHP is within reach of anything.

A branch snapshot (`dev-master`, `2.x-dev`) belongs to no branch and is `pinned`. A package whose
installed version the repository does not list — a private fork, or a lock written against a tag
since deleted, sitting above everything the repository has on that branch — carries no S8: the
branch's dates say nothing about it. Nor does a branch whose highest tag the repository leaves
undated: the dates are the repository's, and Packagist dates a tag by the commit it points at, so
a package split out of a monorepo (`illuminate/*`, `symfony/*`) has tags with no date and tags
dated by the last change to that directory, years before the release that carried them. With the
branch's newest tag undated, how much younger it is than the newest dated one cannot be read, and
the branch is not measured. A tag that shares its commit with two or more other stable tags counts
as undated too: a split cuts a tag on every release whether or not the directory changed, so its
tags pile up on one commit — `illuminate/macroable` has 83 stable tags on the commit behind
`v10.49.0`, all dated 2023 — and the date on such a tag is the commit's, not the release's. Two tags
on one commit are read as dated: that is a re-tag, or a branch's last two releases cut with nothing
changed between them (`symfony/*` 3.4.46 and 3.4.47), where the date is one release interval off at
most and a branch that really stopped stays measurable. The same reading
applies to the package's own age (S2): with its highest tag undated or shared, the age is unknown
and S2 stays quiet. A highest tag with a date and a commit of its own is read as it stands.

### Dates from the monorepo

A split package's dates are missing for one reason: its tags are cut by a monorepo, and the
monorepo's own tag for the same version is dated by the release that carried it. `laravel/framework`
declares `replace: {illuminate/contracts: self.version}` — the two `v8.83.27` are one release — so
where a branch of `illuminate/contracts` carries no date of its own, the branch of the same name in
`laravel/framework` supplies it, and the finding says whose date it is:

```text
  left-behind  illuminate/contracts v5.8.36  direct
               branch 5.x last released 2020-08-18 (6.1 years ago, dated by laravel/framework);
               12.x released v12.69.2 (2026-09-08); require ^12.69 to follow
```

The parent is found in the lock first — a Laravel application already has `laravel/framework`, and
nothing extra is fetched. Otherwise lockrot asks the configured repositories for it, one request,
and only for a monorepo that
[`resources/monorepo-parents.json`](https://github.com/somework/lockrot/blob/main/resources/monorepo-parents.json)
lists as carrying a package this lock needs dates for. That file holds `laravel/framework`,
`symfony/symfony` and `cakephp/cakephp` with the components each was last seen to replace, and it
decides only what is worth fetching: what a parent dates is its live `replace` list. So
`symfony/polyfill-ctype`, whose own tags are cut the same way by a repository no Packagist package
replaces, costs nothing — it is in almost every lock, and nothing can date it.

A branch the parent does not have, or does not date either, stays unmeasured as before; so does
every branch during an install-time run that has used up its budget. The signal carries the parent
as `dated_by` in `--format=json`, and `--explain` marks the rows it supplied.

The parent dates the installed version too. The lock's `time` for a split package is the date
Packagist gave its tag — the shared commit's — so `illuminate/contracts` v8.83.27 is locked at
2022-01-13 for a release of 2022-12-08. Where the parent lists the same version, its date is the
installed version's for [libyears](#libyears), and `--explain` prints it as `installed release`
(`installed_release_dated_by` in JSON); where it does not, the lock's date is not trusted and the
package goes unmeasured rather than eleven months too far behind.

## Security advisories

`composer audit` reports the vulnerability. lockrot carries the same advisories on the finding, as
S9, and says whether a fix is coming.

S9 lists every advisory whose affected range matches the installed version, fetched from the
configured Composer repositories exactly as audit fetches them — one request to Packagist for the
whole lock, the package files themselves on a repository that carries advisories inline. It never
decides a verdict: a vulnerability on a healthy package is audit's finding and stays out of the
priority.

Each advisory is then held against two releases the repository already lists: the highest stable
tag on the installed version's branch, and the package's highest stable tag — each only when it is
above the installed version, since a tag the range spares is no fix when reaching it means going
back, and a repository that lists nothing above what is installed names none. One that neither range
covers is already fixed, and the line says by what — `fixed by v3.4.47` when the branch's tag is
enough, since a `composer update` inside the constraint gets it; `fixed by v8.1.7` when only the
package's is. On an `abandoned`, `silent` or `left-behind` package, the advisories nothing listed
fixes are the ones no fix will come for: the evidence closes what was observed about the package
itself with `no fix expected` — ahead of what it pulls in, when it is a direct requirement that
does — and the priority goes up one step, `critical` at most. On a left-behind branch only a fix
*on the branch* counts as one the project can reach, so `3 fixed by v8.1.7; no fix expected on
3.x` is one line: the fix exists, and it will not land where this lock is. On an abandoned package
whose repository names a replacement the clause says where to go — `no fix expected; migrate to
symfony/mailer` — since the advisory will not be fixed here and the package that took over is
where fixes land. A package whose every
advisory is fixed by a listed release is not raised, whatever its verdict — the fix is out, and
the finding says which release carries it.

```text
  left-behind  symfony/http-foundation v3.4.18  via laravel/framework, also via webklex/php-imap
               branch 3.x last released 2020-10-24 (5.9 years ago); 8.x released v8.1.7
               (2026-09-14); released 2018-10-31 for PHP 5 (php "^5.5.9|>=7.0.8"), before PHP 8
               existed (8.0 GA 2020-11-26); admits 8.5 untested; 4 security advisories affect v3.4.18
               (CVE-2019-10913, CVE-2025-64500, CVE-2019-18888 and 1 more); 2 fixed by v3.4.47, 2
               fixed by v8.1.7; no fix expected on 3.x
```

Two of the four were fixed within 3.x — v3.4.47 is out of their range — and a `composer update`
gets them; the other two are fixed only in 8.x, which is what the branch will not get, so they earn
the raise: `high` for a transitive requirement becomes `critical`. The same package `abandoned`
rather than left behind would count fixes anywhere in it, since no branch of it will release again:

```text
  abandoned    swiftmailer/swiftmailer v6.1.3  via laravel/framework
               marked abandoned by its repository, replacement: symfony/mailer; repository archived
               on GitHub; last release 2021-10-18 (4.9 years ago); last push 2021-10-25 (4.9 years
               ago); released 2018-09-11 for PHP 7 (php ">=7.0.0"), before PHP 8 existed (8.0 GA
               2020-11-26); admits 8.5 untested; 1 security advisory affects v6.1.3 (CVE-2024-28859);
               fixed by v6.3.0
```

The one advisory is fixed by the package's last release; the priority stays at the verdict's own
`high`, and the line says where the fix is instead of claiming there is none.

An allowlisted package is
never raised — `finished` says the project vouches for it — but its advisories are counted in the
footer line. Each advisory is named by its CVE, or by its Packagist
id when it has none; the worst severity first, three named, the rest counted. Advisories on
packages the report does not flag stay off the rows (they are audit's findings), but the footer
counts them — `53 security advisories on 17 packages the report does not flag; see composer audit`
— so a clean-looking report does not read as a clean audit. The count covers the packages the
run checked: without `--dev` that is the production set, which `composer audit --no-dev` also
reports on, where plain `composer audit` counts development packages too — so the line then adds
`(it counts packages-dev too, which this run skipped; pass --dev to include them)`, and
`--format=json` records the scope as `include_dev`. An advisory the project
has accepted is silenced where `composer audit` silences it, not in lockrot's own configuration: `config.policy.advisories` (`ignore-id`, `ignore`,
`ignore-severity`) on Composer 2.10 and later, `config.audit.ignore` and `audit.ignore-severity`
before. What audit drops, lockrot drops. A policy section Composer itself rejects — a key reserved
for a later version, say — leaves lockrot with no ignore list at all; the report then carries a note
saying so, and every advisory counts until the section parses.

`stale`, `pinned` and `old-promise` are not raised: an old release, a branch snapshot or an open
php constraint says nothing about whether a fix is coming. The [baseline](baseline.md) stays keyed on
the verdict, so a baselined finding is `known` whatever S9 adds to its priority.

The check needs Composer 2.4 or newer — on the 2.2 LTS the report carries one note and nothing else
changes — and cannot run under `--offline`, which is noted the same way; at install time it is
skipped, with a note, once the [budget](install-time.md) is spent. A repository that could not be
reached for advisories is a note and, under `--strict-network`, exit `1`, like any other unreachable
source. `--format=json` carries each advisory under the signal's `data.advisories` as `id`, `cve`,
`title`, `link`, `severity`, `reported_at` and `affected_versions` (the range, as Composer prints
it), null where the repository gave none, with `fixed_by` — the listed release out of the range,
null when there is none — and `fixed_on_branch`, true when that release is on the installed
version's branch.

## Priority

A package reported the same way matters less when nothing in the project requires it directly, and
less again when it is only ever installed for development.

Four rules, in order:

1. A package the report does not flag (`unknown`, `finished`, `ok`) has priority `none`.
2. Otherwise the verdict sets the base level: `abandoned` and `silent` start at **critical**,
   `pinned`, `left-behind` and `old-promise` at **high**, `stale` at **medium**.
3. The base drops one step when the package is transitive (nothing you require names it) and one
   more step when it is a development dependency. It never drops below **low**.
4. A security advisory on an `abandoned`, `silent` or `left-behind` package raises the result one
   step, never above **critical**; see [Security advisories](#security-advisories).

| Verdict | direct, prod | transitive, prod | direct, dev | transitive, dev |
|---|---|---|---|---|
| `abandoned`, `silent` | `critical` | `high` | `high` | `medium` |
| `pinned`, `left-behind`, `old-promise` | `high` | `medium` | `medium` | `low` |
| `stale` | `medium` | `low` | `low` | `low` |
| `unknown`, `finished`, `ok` | `none` | `none` | `none` | `none` |

A package nothing in your `require`/`require-dev` can reach counts as transitive.

The priority orders the report — highest first, then by verdict severity, then direct dependencies
ahead of transitive ones, then by package name — and it is carried in every format.

> **`--fail-on` takes a verdict, a priority, or `unchecked`.** The last one is not a severity at
> all: it fails on a finding whose check did not run ([What was not checked](#what-was-not-checked)).
> `--fail-on=silent` fails on what was observed,
> wherever the package sits; `--fail-on=high` fails on a `critical` or `high` finding and lets the
> same verdict pass on a transitive development package. Both are inclusive. The
> [baseline](baseline.md) stays on the verdict: a finding it already carries never fails a run,
> whichever kind of threshold is set.

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

The GitLab fingerprint and the SARIF `ruleId` read the verdict alone. The GitHub annotation level,
the GitLab severity and the SARIF `level` follow `--fail-on`, whichever kind of threshold it names,
so the colour a reviewer sees matches the exit code either way.

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
The first three other requirements by name are named, then the rest counted (`and 12 more`);
`--format=json` carries the full list as `direct_dependents` on every finding, the package itself
included when it is direct.
A direct requirement's own row stays `direct`, even when other requirements reach it as well — in
a framework application every bundle reaches the framework's own packages, and naming them there
would say nothing.

**Signal S7 on the direct requirement.** After every verdict is known, each direct requirement
whose subtree holds flagged transitive packages gets an informational signal listing them, in
report order, with the shortest chain from that requirement to each:

```text
  pinned       wallabag/rulerz-bundle dev-master  direct
               pinned to branch snapshot dev-master; pulls in 15 flagged packages:
               hoa/compiler (abandoned), hoa/consistency (abandoned), hoa/event (abandoned),
               hoa/exception (abandoned), hoa/file (abandoned) and 10 more
```

Five are named in the evidence; `--format=json` carries them all under the signal's `data`, each
with its `verdict` and `chain`. A requirement whose own verdict is `ok` carries S7 too, so `--all`
shows what a clean-looking requirement is responsible for — as does an allowlisted one, whose
`finished` row is likewise only printed under `--all` while the `pulled in by:` line below still
counts it.

A flagged transitive package reached from **more than eight** direct requirements is shared
infrastructure — in a framework application, the framework's own contracts, reached from every
bundle — and nobody's to remove, so it is left out of S7 and of the `pulled in by:` line. It keeps
its own row, with `also via … and N more`, and `direct_dependents` still names every parent.

**The `pulled in by:` line.** The summary block sums the same thing up per direct requirement,
most first:

```text
pulled in by: wallabag/rulerz-bundle 15 · wallabag/rulerz 14 · wallabag/phpepub 5 · friendsofsymfony/jsrouting-bundle
2 · friendsofsymfony/oauth-server-bundle 2 · … and 21 more
```

Five requirements are named, then the rest counted; the JSON document carries the whole list as
`exposure`. The line is printed only when some flagged package is transitive.

> **S7 decides nothing.** A package is never flagged for what it depends on. The verdict, the
> priority, `--fail-on`, the exit code and the baseline all ignore S7; it describes, the same way
> the priority does. A flagged package the project requires directly is its own row's business
> and counts under nobody, whoever else reaches it.

The graph is the lock's `require` edges. A requirement satisfied through `replace` or `provide` — a
virtual package such as `psr/log-implementation`, or a package another one replaces — contributes
no edge, so the provider can have fewer parents listed than actually pull it in. That case renders
`?` in the `via` column when nothing else reaches the package.

At install time only the packages the transaction touches are analysed, so a direct requirement
gets S7 only when it is itself part of the transaction, and the compact block does not print S7 or
the other parents at all; `composer lockrot` on the full lock always has the whole picture.

## What was not checked

`ok` is the absence of a finding, and without S10 it would mean two different things: every check
ran and found nothing, or a check never ran. The difference matters most where it is least visible.
Anonymously, GitHub allows 60 requests an hour, so the activity round asks only about packages that
already look stale on release age ([internals.md](internals.md)) — a maintainer who tags a last
release and archives the repository a week later has a *recent* release, and S3, the signal that
would call that `abandoned`, never gets to run. The run says `89 packages skipped` in its notes and
names none of them.

S10 carries that fact on the finding itself. Two checks can be missing:

| Check | Why it can be missing | What it blocks |
|---|---|---|
| `repository_activity` | `no_token` (the anonymous cap asks only about candidates), `anonymous_budget`, `install_time_budget`, `rate_limit`, `fetch_failed`, `offline` | S3, S4 |
| `release_dates` | `undated_releases`: the repository dates the package's newest releases only by a commit their tags share, and no [monorepo parent](#dates-from-the-monorepo) dates them either | S2, S8 — S2 alone where the installed version is a branch snapshot, which is on no release branch for S8 to measure |

It is informational, like S7 and S9: it never decides a verdict. It is raised only where the
missing check could have changed one — a package the repository already marks `abandoned` has
nothing left for the activity round to add, and an allowlisted package reports `finished` whatever
its signals say, so neither carries it. Credentials for every host in the lock take away the
`repository_activity` reasons, and only those: `release_dates` asks no forge, so a fully
credentialed run can still carry S10 — and still fail `--fail-on=unchecked` on it.

`--fail-on=unchecked` fails on any finding that carries it: the one threshold that is neither a
verdict nor a priority, for a pipeline that wants to hear about the workflow that never passed
`GITHUB_TOKEN` through rather than a green run over unasked questions.

## Libyears

One number for how far behind the whole lock is, laid over the verdicts rather than added to them:

```text
libyears: 171.3 behind across 194 of 200 packages · 111.7 from direct requirements ·
furthest behind smalot/pdfparser v1.1.0 at 4.7
```

For each package, the years between the release of the version installed and the package's newest
stable release — the lock's own `time` against the repository's date for its highest stable tag,
in years of 365.25 days, never below zero — summed over the packages the run analysed (with
`--dev`, `packages-dev` included). The *libyear* is the unit [libyear.com](https://libyear.com/)
gives this measure — "Rails 5.0.0 (June 2016) is 1 libyear behind 5.1.2 (June 2017)"; the metric
underneath is the version release date of [Cox, Bouwers, van Eekelen and Visser, *Measuring
Dependency Freshness in Software Systems*, ICSE 2015](https://ericbouwers.github.io/papers/icse15.pdf),
which that site cites. Nothing about today enters the number: two dates the run already holds, so
it does not move between two runs on the same lock unless a package releases.

Every finding carries its own value as `libyears` in `--format=json`, `null` when the package is not
measured, and the report's `libyears` block is the arithmetic over them: `total`,
`direct_requirements` (the same sum over the findings with `direct: true`), `measured`, `unmeasured`
by reason, `furthest_behind`. `total` and `direct_requirements` are null when `measured` is 0 —
nothing could be measured, so there is no sum; a run that measured packages and found none behind
reports `0`, which is a different answer and says so. A consumer can recompute every number in the
block from the findings:
`total` is summed before rounding, so the sum of the printed values agrees with it to within 0.005
per measured finding, and `measured` plus every count in `unmeasured` is the number of findings.
The HTML page shows the total in its ledger, the value in a sortable column, and the counts by
reason on the Run tab.

A package is **not measured**, and counted under one of four reasons, when:

| `unmeasured` key | when |
|---|---|
| `branch_snapshot` | The installed version is a branch (`dev-main`, `2.x-dev`): it has a commit date, not a release date. Measured by push date a fresh `dev-main` reads as zero and an old one as years of nothing (lox/xhprof on Matomo would add ten). The `pinned` verdict already says what there is to say. |
| `no_stable_release_date` | No date lockrot trusts for one of the two ends: no stable release exists; or the newest tag carries no date lockrot trusts — a subtree split whose tags share a commit — and nothing dated sits above the installed version either (symfony/polyfill-ctype v1.37.0: the newest tag, undated); or the installed version is dated only by such a shared commit and its [monorepo parent](#dates-from-the-monorepo) does not date it either (the lock's `time` for illuminate/macroable v10.48.28 is a year and a half before the release; where the parent lists the version, its date is used instead); or the lock entry has no `time`. Adding an unknown to a sum is not measuring. |
| `not_from_composer_repository` | A `path`, `vcs` or `package` repository entry: no metadata was asked for. |
| `metadata_unavailable` | Metadata was asked for and did not come: not listed, offline, budget, transport. |

The reasons are checked in this order, and the first that applies is the one counted: not from a
Composer repository, then metadata unavailable, then a branch snapshot, then no dated stable
release — so a `dev-main` pin on a package that never released counts as a snapshot. A lock ahead
of the last stable release — a pre-release above it, a tag the repository no longer lists — is
measured as zero, not dropped; a lock with nothing behind names no package as furthest behind.

**When the newest release is undated, the number is a lower bound.** A subtree split's tags pile
up on one commit — scheb/2fa-backup-code has v8.3.0 through v8.6.1 on one, all "2026-01-24" —
and Packagist dates each by that commit, so the newest tag's date is not the release's and lockrot
does not read it (that is also why S2 stays quiet there). But a tag's commit is never younger than
the release it names, so the newest *trusted* date of a release above the installed version, read
off the same branch view [left-behind](#left-behind) uses, is a date the package had certainly
moved past. That is what such a package is measured to: at least 4.1 years for
scheb/2fa-backup-code v5.13.2, possibly more. The HTML package card says "at least" on these.

Three things the number is not:

- **A measure of rot.** It counts every drift, healthy patches included: on the wallabag lock
  `psr/log 1.1.4` is `finished` and adds 3.4 years for a 3.x it will never need, while
  `sensio/framework-extra-bundle` is `abandoned` and adds zero, because the release installed is
  the last one there is; the package furthest behind, `smalot/pdfparser`, is `left-behind`. The
  two axes do not track each other, which is why the number does not enter a priority, `--fail-on`
  or the baseline.
- **A security number.** An advisory is S9 and the priority ladder; this is distance.
- **php-libyear's number.** [ecoAPM/php-libyear](https://github.com/ecoAPM/php-libyear) reads
  `composer.json` and sums the direct requirements only; lockrot sums the whole lock, which on
  wallabag is 171.3 against 111.7. `direct_requirements` is the same sum restricted to
  `direct: true` — the nearest number to that tool's, not the same one: run with `--dev` to
  compare, since php-libyear counts `require` and `require-dev` together; it picks the newest
  version by the project's `minimum-stability` where lockrot always takes the newest stable; and it
  scores an undated package as zero where lockrot leaves it unmeasured.

## Related

- [example-run.md](example-run.md) — a full run with every verdict in it, and the libyears block
- [configuration.md](configuration.md) — the thresholds behind S2 and S4, and the allowlist
- [baseline.md](baseline.md) — accepting findings you have already decided to live with
- [ci.md](ci.md) — exit codes and the seven output formats, and where each carries the exposure
- [compatibility.md](compatibility.md) — which of these names and orders 1.0 freezes, and how a verdict may change between releases
