---
title: Install-time summary — what composer require prints, and how to make it a gate
description: "The block lockrot prints during composer require, update and install: what it covers, its line and time budgets, when it stays silent, and the install-time-strict gate."
---

# Install-time summary {#install-time-summary}

With the [plugin installed](index.md#install), `composer require`, `composer update` and
`composer install` print a block above Composer's operations list. It covers only the packages that
the transaction installs or updates, not the whole lock. It never fails the install unless you set
[`install-time-strict`](#install-time-strict).

| To | Use |
|---|---|
| See why one package is flagged | `composer lockrot --explain=<package>` ([explaining one package](configuration.md#explaining-one-package)) |
| Accept what is flagged | A [baseline](baseline.md) |
| Never flag one package | The [allowlist](configuration.md#the-allowlist) |
| Turn the block off for a project | `"install-time": "off"` in [`extra.lockrot`](configuration.md#extralockrot-keys) |
| Silence all of lockrot for one command | [`LOCKROT_DISABLE=1`](configuration.md#environment-overrides): not even the skipped line prints |
| Give the check more or less time | [`install-time-budget`](#time-budget), in seconds |
| Stop the install on a finding at `fail-on` | [`"install-time-strict": true`](#install-time-strict) |

The block has this shape, on stderr:

```text
lockrot: dependency rot in <flagged> of <changed> changed packages
  <verdict>   <package> <version>: <evidence>
  <verdict>   <package> <version>: <evidence> (via <direct> > <intermediate>)
  … and <n> more
  note: <note text>
Run composer lockrot for details.
```

## At most ten lines, always {#at-most-10-lines-always}

The block is at most ten lines, in this order:

- The header.
- One line per flagged package, in the report's order (highest [priority](verdicts.md#priority)
  first). `… and <n> more` replaces the lines that do not fit.
- Up to three of the run's notes, in the order the run wrote them ([run notes](notes.md)).
- The footer, `Run composer lockrot for details.`

lockrot counts lines as written, not as terminal rows. A long line can wrap in a narrow terminal.

A transitive package's line ends with the chain that pulls it in, `(via a > b)`, resolved through
the whole lock. The block does not show the other direct requirements that reach the package, or,
for a direct requirement, the flagged packages it pulls in (S7). `composer lockrot` shows both
([transitive exposure](verdicts.md#transitive-exposure)).

An `extra.lockrot` key that lockrot does not read adds one `lockrot: unknown key …` line above the
block, outside its ten, whether or not anything is flagged
([unknown keys](configuration.md#unknown-keys)). The line prints whenever the install-time check
runs: not under `LOCKROT_DISABLE`, not with `install-time: off`, and only for a transaction that
installs or updates something.

## Never silent about a package it could not check {#never-silent-about-a-package-it-could-not-check}

A package whose metadata never arrived is `unknown`, which is not flagged. When nothing is
flagged but the run had a network failure (a note with `sets_network_failures: true`), a short
block prints instead of nothing:

| Header | When |
|---|---|
| `lockrot: <n> of <m> changed packages could not be checked` | `<n>` packages are `unknown` |
| `lockrot: <m> changed packages checked, one check incomplete` | Metadata arrived for every package, and a later lookup failed: advisories, a monorepo parent's metadata, or a repository host |

Up to three notes and the footer follow the header. They are the run's first notes, so the one
behind the failure can fall past the cut. `composer lockrot --format=json` lists every note, each
with its `sets_network_failures` ([run notes](notes.md)).

Silence means nothing is flagged and no note is a network failure. It does not mean every check
ran: skipped repository activity, a skipped advisory check or the anonymous cap
([run notes](notes.md)) prints nothing on its own. The unknown-key line still prints.

## Time budget

Once `install-time-budget` seconds have passed, the install-time check starts no new metadata or
advisory request, and it cuts repository-activity requests to the time left. A request that is
already running finishes first, so an install can take somewhat longer than the budget.
`composer lockrot` has no budget.

```json
{
    "extra": {
        "lockrot": {
            "install-time-budget": 20
        }
    }
}
```

The default is `5` seconds ([range](configuration.md#extralockrot-keys)). The install-time keys
have no CLI option and no environment override.

When the budget runs out, a verdict that needs repository activity (S3, S4) can read milder than
the one `composer lockrot` gives for the same package.

| The budget ran out before or during | Effect on the block | Note |
|---|---|---|
| A package's metadata | The package is `unknown`. When nothing is flagged, the [could-not-be-checked header](#never-silent-about-a-package-it-could-not-check) counts it | [`metadata_unavailable`](notes.md#metadata_unavailable), a network failure |
| The advisory check | A priority that an advisory can raise stays one step lower | [`advisories_not_checked`](notes.md#advisories_not_checked) |
| A monorepo parent's metadata | Split packages whose own tags have no trusted date stay unmeasured and carry S10 `undated_releases` ([dates from the monorepo](verdicts.md#dates-from-the-monorepo)) | None |
| The repository activity check, before it began | lockrot does not read S3 and S4. Findings carry S10 `install_time_budget` | [`repository_activity_not_checked`](notes.md#repository_activity_not_checked) |
| The repository activity check, part way | lockrot cuts the requests that are still running to the time left. Those that time out have no activity, and their findings carry S10 `fetch_failed` | [`repository_activity_unreachable`](notes.md#repository_activity_unreachable), a network failure |

`composer require` and `composer update` reuse the metadata that Composer fetched in the same
process, so they rarely run out. A `composer install` with a cold cache on a large lock, such as a
fresh clone or a CI job, can run out before lockrot checks every package. Raise the budget for such
a lock, or lower it to spend less time on the check.

## When the install continues and when it stops {#never-fails-the-install}

| Symptom | Cause | Install | What to do |
|---|---|---|---|
| A note in the block | Something the run could not see: an unreachable repository, an exhausted budget, a missing token | Continues, unless `install-time-strict` is on with `fail-on: unchecked` and a finding carries S10 | Look up its code in [run notes](notes.md) |
| One `lockrot: install-time check skipped: …` line | lockrot could not run the check: a malformed `extra.lockrot` or lockrot environment variable, an unreadable `composer.lock`, an unreadable [baseline](baseline.md#install-time) file, an `extra.lockrot.baseline` that names a missing one, or a defect in lockrot | Continues, and `install-time-strict` does not apply | Run `composer lockrot`. For a configuration problem, it names the problem and exits `2` ([exit codes](ci.md#exit-codes)) |
| `lockrot: findings at or above fail-on=…` and Composer exits `1` | [`install-time-strict`](#install-time-strict) is on and a finding reaches `fail-on` | Stopped | See [after a stop](#install-time-strict) |

## `install-time-strict` {#install-time-strict}

```json
{
    "extra": {
        "lockrot": {
            "install-time-strict": true,
            "fail-on": "high"
        }
    }
}
```

`install-time-strict` needs a `fail-on`, from `LOCKROT_FAIL_ON` or `extra.lockrot.fail-on`. Without
one, lockrot stops nothing. When a finding reaches `fail-on`, lockrot stops the transaction before
any package operation runs, and Composer exits `1`. [Choosing `--fail-on`](ci.md) covers the values.

- A finding in the [baseline](baseline.md#install-time) does not stop the install unless its
  verdict is worse than the baseline recorded. The block lists both kinds.
- A gap in what the run could see stops the install only through `fail-on: unchecked`, which
  matches every finding that carries S10 ([what was not checked](verdicts.md#what-was-not-checked)).
  Such a finding can be `ok` and missing from the block. Advisory
  gaps and `metadata_unavailable` do not stop the install on their own, and `--strict-network` has
  no install-time counterpart.
- lockrot never stops a `--dry-run`, since no operation is about to run.

A stopped `composer require` or `composer update` leaves `composer.lock` updated (and, for
`require`, `composer.json`) and nothing new in `vendor/`. `composer install` then stops on the same
finding. Do one of these:

- Undo the change. Run `composer remove <package>`, or restore `composer.json` and `composer.lock`
  from version control.
- For a flagged finding, accept it into the [baseline](baseline.md), then run `composer install`.
- Allowlist the package ([the allowlist](configuration.md#the-allowlist)), then run
  `composer install`.

A baseline covers only flagged findings. The allowlist clears a `fail-on: unchecked` stop on an
`ok` or `unknown` finding. For a repository-activity gap, the stop also clears when that check runs
(set a token, raise the budget or rerun).

## Under `--dry-run` {#a-note-on-the-dry-run-development-flag}

Under `composer require --dev … --dry-run`, lockrot treats a package that is absent from the lock on
disk as production, so its [priority](verdicts.md#priority) can read one step high.
`composer lockrot` on the real lock has the flag.

## Related

- [configuration.md](configuration.md#extralockrot-keys) — the `install-time`,
  `install-time-strict` and `install-time-budget` keys
- [notes.md](notes.md) — every note the block can print, and which are network failures
- [baseline.md](baseline.md#install-time) — how the baseline applies at install time
- [verdicts.md](verdicts.md) — what the verdicts and priorities mean
- [example-run.md](example-run.md) — a recorded run of the full report the footer points to
