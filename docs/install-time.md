---
title: Install-time summary — what composer require prints, and how to make it a gate
description: "The block lockrot prints during composer require, update and install: what it covers, its line and time budgets, when it stays silent, and the install-time-strict gate."
---

# Install-time summary {#install-time-summary}

With the [plugin installed](index.md#install), `composer require`, `composer update` and
`composer install` print a block above Composer's operations list. It covers only the packages that
the transaction installs or updates, not the whole lock. It follows
[`include-dev`](configuration.md#extralockrot-keys), as `composer lockrot` does: without it, the
check skips each `packages-dev` package, even when Composer installs it. It never fails the install
unless you set [`install-time-strict`](#install-time-strict).

| To | Use |
|---|---|
| See why one package is graded | `composer lockrot --explain=<package>` ([explaining one package](configuration.md#explaining-one-package)) |
| Accept what is graded | A [baseline](baseline.md) |
| Accept one package's maintenance flags | The [allowlist](configuration.md#the-allowlist) |
| Turn the block off for a project | `"install-time": "off"` in [`extra.lockrot`](configuration.md#extralockrot-keys) |
| Silence all of lockrot for one command | [`LOCKROT_DISABLE=1`](configuration.md#environment-overrides): not even the skipped line prints |
| Give the check more or less time | [`install-time-budget`](#time-budget), in seconds |
| Stop the install on a finding at `fail-on` | [`"install-time-strict": true`](#install-time-strict) |

The block has this shape, on stderr:

```text
lockrot: <graded> of <changed> changed packages flagged — <grade counts>
  <grade> [<score>] <package> <version>: <flags> — <the move>
  … and <n> more
  update now: composer update <packages>
  gate: fails · --fail-on=<values> · <n> findings fail it · target php <version> (<source>)
  note: <note text>
Run composer lockrot for details.
```

## At most ten lines, always {#at-most-10-lines-always}

The block is at most ten lines, in this order:

- The header, with the counts per grade.
- One line per graded package, in the report's order: its grade, its score, its counted flags and
  its move, cut at a word boundary past 160 characters. A command in the move shows whole, even
  past 160 characters, or not at all, and a space parts it from the `…`. When `vulnerable` is the only counted flag, it adds its
  advisories: the deciding severity and the count (`vulnerable (critical advisory; 15 in all)`).
  For one advisory or for low ones it gives the count before the severity
  (`vulnerable (1 high advisory)`, `vulnerable (2 low advisories)`).
  `… and <n> more` replaces the lines that do not fit. When a move's line is longer than 160
  characters, it drops "Composer still refuses the lock itself: the moves_not_checked note names
  the first cause." first, so that its command shows.
- `update now:` with every package that `composer update` fixes, shown or not.
- The [`gate:` line](ci.md#reading-the-gate-line), when the gate fails.
- Up to two notes. The [`moves_not_checked`](notes.md#moves_not_checked) note comes first, because
  each move's platform step points at it. When the run reads a baseline and no setting gives the
  target PHP, the next note is lockrot's own: `target php from the running PHP (<version>); fix changes against the
  baseline not compared`. It has no code. Set the [target PHP](configuration.md#extralockrot-keys)
  to compare fix changes. The other [run notes](notes.md) follow, in the order the run wrote them. A note
  that does not fit is counted in the footer.
- The footer, `Run composer lockrot for details.`

lockrot counts lines as written, not as terminal rows. A long line can wrap in a narrow terminal.

The block does not show the direct requirements that reach a transitive package, or, for a direct
requirement, the graded packages it pulls in (S7). `composer lockrot` shows both
([transitive exposure](verdicts.md#transitive-exposure)).

An `extra.lockrot` key that lockrot does not read adds one `lockrot: unknown key …` line above the
block, outside its ten, whether or not anything is graded
([unknown keys](configuration.md#unknown-keys)). The line prints whenever the install-time check
runs: not under `LOCKROT_DISABLE`, not with `install-time: off`, and only for a transaction that
installs or updates something.

## <a name="never-silent-about-a-package-it-could-not-check"></a>Never silent about a package it could not check {#never-silent-about-a-package-it-could-not-check}

A package whose metadata never arrived is `unknown`, which is not graded. When nothing is graded,
a short block prints instead of nothing if the run had a network failure (a note with
`sets_network_failures: true`) or the gate fails. With nothing graded, only `fail-on: unchecked`
can fail the gate.

| Header | When |
|---|---|
| `lockrot: <n> of <m> changed packages could not be checked` | `<n>` packages are `unknown` |
| `lockrot: <m> changed packages checked, one check incomplete` | No package is `unknown`, and a check did not complete: advisories, a monorepo parent's metadata, a repository host, or another check that a finding's S10 names |

The [`gate:` line](ci.md#reading-the-gate-line) follows the header when the gate fails. Then up to
two notes and the footer follow. They are the run's first notes, `moves_not_checked` first, so the one behind the failure can
fall past the cut, and the footer does not count the rest. `composer lockrot --format=json` lists
every note, each with its `sets_network_failures` ([run notes](notes.md)).

Silence means that nothing is graded, no note is a network failure and the gate passes. It does not
mean that every check ran: skipped repository activity, a skipped advisory check or the anonymous
cap ([run notes](notes.md)) prints nothing on its own. The unknown-key line still prints.

## Time budget

Once `install-time-budget` seconds have passed, the install-time check starts no new metadata,
advisory or repository-activity request. A repository-activity request gets the time left as its
timeout when it starts. lockrot starts no more of them at once than Composer's parallel limit
(`COMPOSER_MAX_PARALLEL_HTTP`), so no request waits in Composer's queue for a free slot. Composer
2.11 holds a host after a 429 with `Retry-After`, and a request to that host waits with its
timeout. A retry or a redirect on the same host keeps the timeout of its request. Composer sets
the timeout of a metadata or advisory request, and lockrot cannot shorten it.
So a metadata or advisory request that is already running can hold the install for Composer's
timeout, and Composer can retry a failed transfer. With curl, the timeout of one metadata request is
`default_socket_timeout` or 300 seconds, whichever is larger
([Composer 2.10.0](https://github.com/composer/composer/blob/2.10.0/src/Composer/Util/Http/CurlDownloader.php)).
An advisory that a repository publishes in its metadata takes that timeout too. A request to a
repository's advisory API times out after 10 seconds
([Composer 2.10.0](https://github.com/composer/composer/blob/2.10.0/src/Composer/Repository/ComposerRepository.php)).
A move-check solve that has started runs to its end, past the budget. The budget stops only a solve
that has not started.
With a warm cache, Composer sends fewer requests. `composer lockrot` has no budget.

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

When the budget runs out, a flag that needs repository activity (S3, S4) can be missing, and the
grade can read milder than the one `composer lockrot` gives for the same package.

| The budget ran out before or during | Effect on the block | Note |
|---|---|---|
| A package's metadata | The package carries `libyears_unmeasured` `unavailable`. It reads `unknown` when nothing else counts, such as a counted advisory or the lock's abandoned marking. When nothing is graded, the [could-not-be-checked header](#never-silent-about-a-package-it-could-not-check) counts it | [`metadata_unavailable`](notes.md#metadata_unavailable), a network failure |
| The advisory check | The advisories that the lookup did not reach add nothing to the score, and the findings carry S10 `install_time_budget` | [`advisories_not_checked`](notes.md#advisories_not_checked) |
| A monorepo parent's metadata | Split packages whose own tags have no trusted date stay unmeasured and carry S10 `undated_releases` ([dates from the monorepo](verdicts.md#dates-from-the-monorepo)) | None |
| The repository activity check, before it began | lockrot does not read S3 and S4. Findings carry S10 `install_time_budget` | [`repository_activity_not_checked`](notes.md#repository_activity_not_checked) |
| The repository activity check, part way | Each request that is still running stops at the timeout it got when it started. Those that time out have no activity, and their findings carry S10 `fetch_failed` | [`repository_activity_unreachable`](notes.md#repository_activity_unreachable), a network failure |

`composer require` and `composer update` reuse the metadata that Composer fetched in the same
process, so they rarely run out. A `composer install` with a cold cache on a large lock (a fresh
clone, a CI job) can run out before lockrot checks every package. Raise the budget for such
a lock, or lower it to spend less time on the check.

## When the install continues and when it stops {#never-fails-the-install}

| Symptom | Cause | Install | What to do |
|---|---|---|---|
| A note in the block | Something the run could not see: an unreachable repository, an exhausted budget, a missing token | Continues, unless `install-time-strict` is on with `fail-on: unchecked` and a finding carries S10 | Look up its code in [run notes](notes.md) |
| One `lockrot: install-time check skipped: …` line | lockrot could not run the check. Causes: a malformed `extra.lockrot` or lockrot environment variable. A value that Composer refuses in a blocking or policy variable, `config.policy` or `config.audit`. An unreadable `composer.lock`. An unreadable [baseline](baseline.md#install-time) file, a baseline-1 file included. A baseline of another score model ([baseline.md](baseline.md#a-baseline-from-a-newer-lockrot)). An `extra.lockrot.baseline` that names a missing file. A defect in lockrot. `-q` does not hide the line | Continues, and `install-time-strict` does not apply | Run `composer lockrot`. For a configuration problem, it names the problem and exits `2` ([exit codes](ci.md#exit-codes)) |
| One `lockrot: Composer <version> is older than 2.10, so lockrot does nothing. …` line | The plugin runs on a Composer older than 2.10. `composer require` and `composer remove` can print it twice, because the plugin writes it on every activation. `-q` does not hide it | Continues, and `install-time-strict` does not apply | Upgrade Composer to 2.10 or later. To gate CI, run the [PHAR](phar.md) |
| `composer install` or `composer require` fails with `somework/lockrot v0.14.0 requires composer-plugin-api ^2.9 -> found composer-plugin-api[<version>] but it does not match the constraint.` | An older Composer refuses the plugin before lockrot runs. The message names the plugin API, not Composer 2.10 | Fails | Upgrade Composer to 2.10 or later. To gate CI, run the [PHAR](phar.md) |
| `lockrot: install-time-strict stopped the install; gate: fails …` and Composer exits `1` | [`install-time-strict`](#install-time-strict) is on and the gate fails | Stopped | See [after a stop](#install-time-strict) |

## <a name="install-time-strict"></a>`install-time-strict` {#install-time-strict}

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
one, lockrot stops nothing. When the gate fails, as `composer lockrot` decides it, lockrot stops the
transaction before any package operation runs, and Composer exits `1`. The message holds the
[`gate:` line](ci.md#reading-the-gate-line). [Choosing `--fail-on`](ci.md) covers the values.

- A finding in the [baseline](baseline.md#install-time) does not stop the install unless its new
  facts reach `fail-on`. The block lists both kinds.
- A refused configuration value or baseline file skips the check: `install-time-strict` stops
  nothing, and the install continues with one `install-time check skipped` line. A committed
  baseline-1 file and an `extra.lockrot` value that config-2 refuses do this. So does a value that
  Composer refuses in a blocking or policy variable. Run `composer lockrot` after an upgrade, and
  fix what it names. For a baseline-1 file, run `--generate-baseline`
  ([baseline.md](baseline.md#moving-from-a-baseline-1-file)).
- A gap in what the run could see stops the install only through `fail-on: unchecked`, which
  matches every finding that carries S10 ([what was not checked](verdicts.md#what-was-not-checked)).
  Such a finding can be `ok` and missing from the block. `--strict-network` has no install-time
  counterpart.
- An advisory gap is S10 too. With `fail-on: unchecked`, an advisory lookup that runs out of budget
  (`install_time_budget`), fails (`lookup_failed`) or finds no feed (`no_feed`) stops the install.
  So does a package whose metadata did not arrive and that carries a counted advisory
  (`releases_unknown`).
- lockrot never stops a `--dry-run`, since no operation is about to run.
- The stop works only when Composer runs plugins. `--no-plugins` or `COMPOSER_NO_PLUGINS=1` skips
  it. To gate CI, run `composer lockrot --fail-on=<value>` or the [PHAR](phar.md).

A stopped `composer require` or `composer update` leaves `composer.lock` updated (and, for
`require`, `composer.json`) and nothing new in `vendor/`. `composer install` then stops on the same
finding. Do one of these:

- Undo the change. Run `composer remove <package>`, or restore `composer.json` and `composer.lock`
  from version control.
- For a graded finding, accept it into the [baseline](baseline.md), then run `composer install`.
- For a maintenance flag, allowlist the package ([the allowlist](configuration.md#the-allowlist)),
  then run `composer install`. An allowlist entry never accepts an advisory. Accept an advisory in
  Composer's audit settings ([accepting advisories](verdicts.md#accepting-advisories)), then run
  `composer install`.

A baseline covers only graded findings. On an `ok` or `unknown` finding, a `fail-on: unchecked`
stop clears when that check runs (set a token, raise the budget or rerun), or when an
[`accept-unchecked`](configuration.md#accept-unchecked) entry accepts a gap that no rerun closes.

## <a name="a-note-on-the-dry-run-development-flag"></a>Under `--dry-run` {#a-note-on-the-dry-run-development-flag}

lockrot reads the lock through the locker of Composer. On a plain install, the locker reads
`composer.lock`. Under `--dry-run` and with `config.lock` set to `false`, Composer keeps the
updated lock in memory, and lockrot reads that lock. So a package from `composer require --dev … --dry-run` keeps its `packages-dev` membership.
With `include-dev` on, its score is halved for `dev`, as in `composer lockrot`. With `include-dev`
off, the check skips it.

## Related

- [configuration.md](configuration.md#extralockrot-keys) — the `install-time`,
  `install-time-strict` and `install-time-budget` keys
- [notes.md](notes.md) — every note the block can print, and which are network failures
- [baseline.md](baseline.md#install-time) — how the baseline applies at install time
- [verdicts.md](verdicts.md) — what the flags, the score and the verdicts mean
- [example-run.md](example-run.md) — a recorded run of the full report the footer points to
