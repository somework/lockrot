# Install-time summary

With the plugin installed, nothing has to be enabled: `composer require`, `composer update` and
`composer install` print a compact block for the packages that transaction is about to install or
update. To turn it off for a project, set `"extra": {"lockrot": {"install-time": "off"}}`; to
silence all of lockrot for one command, set `LOCKROT_DISABLE=1`.

The block covers the new package and everything it drags in, not the whole lock file. Composer fires
the event lockrot listens on before it prints its own operations list, so the block appears above it.
This is the real stderr of `composer require phpzip/phpzip:2.0.8` in a fresh project:

```text
Writing lock file
Installing dependencies from lock file (including require-dev)
lockrot: dependency rot in 4 of 4 changed packages
  silent      phpzip/phpzip 2.0.8: last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago); released 2015-11-16, before PHP 8.5 GA (2025-11-20); php constraint ">=5.3.0" has no upper bound
  silent      grandt/binstring 1.0.0: last release 2015-08-13 (11.1 years ago); last push 2015-08-13 (11.1 years ago); released 2015-08-13, before PHP 8.5 GA (2025-11-20); php constraint ">=5.0" has no upper bound (via phpzip/phpzip)
  silent      grandt/phpzipmerge 1.0.4: last release 2015-08-18 (11.1 years ago); last push 2015-08-18 (11.1 years ago); released 2015-08-18, before PHP 8.5 GA (2025-11-20); php constraint ">=5.3.0" has no upper bound (via phpzip/phpzip)
  silent      grandt/relativepath 1.0.2: last release 2015-05-14 (11.3 years ago); last push 2020-04-01 (6.5 years ago); released 2015-05-14, before PHP 8.5 GA (2025-11-20); php constraint ">=5.0" has no upper bound (via phpzip/phpzip)
Run composer lockrot for details.
Package operations: 4 installs, 0 updates, 0 removals
```

`phpzip/phpzip` leads because it is the direct dependency and therefore `critical`; the three
packages it drags in are transitive and `high`. No `--target-php` was passed, so the run compared
against the running PHP.

## At most 10 lines, always

Header, one line per flagged package (most severe first), at most two notes, footer. Beyond that the
list is cut with `… and N more`. The budget counts lines as written, not rendered terminal rows — a
long evidence line may still wrap past one row in a narrow terminal.

The order is the report's own: [priority](verdicts.md) first, so the ten lines go to the packages
that apply most directly to the project.

A transitive package's line ends with the chain it is pulled in by, resolved through the whole lock. The block
stops there: neither the other direct requirements that reach the package nor what a direct requirement pulls in
(signal S7, see [verdicts.md](verdicts.md#transitive-exposure)) is printed here — both answer questions asked over
the full report, and `composer lockrot` has them.

## Silent only when the transaction was both checked and clean

A package whose metadata never arrived is reported as `unknown`, which is not a finding. So if
nothing is flagged *but* a lookup failed, a shorter block is printed instead of nothing, and silence
never has to be second-guessed:

```text
lockrot: 4 of 4 changed packages could not be checked
  note: Repository metadata unavailable for 4 packages: not checked: install-time budget exhausted
Run composer lockrot for details.
```

## Time budget

The install-time pass has a hard time budget, 5 seconds by default, so it cannot hold up a
`composer install`. A package whose metadata was never requested is reported as
`not checked: install-time budget exhausted`, and a skipped GitHub round adds the note
`repository activity not checked: install-time budget exhausted`; both reach you through the block
above.

`composer require`/`update` of a few packages is served from the metadata Composer has just fetched
for the same packages, in the same process, and fits comfortably. A `composer install` into an empty
`vendor/` on a large lock — a fresh clone, a CI job — is the case that does not: the budget can run
out before every package is checked, because Composer revalidates its metadata cache in sequential
batches. In our runs the default budget covered roughly 140–170 packages of a 200-package lock, cold
or warm, so from about 150 packages expect the block to say how many were not checked rather than
read as clean.

Raise it for a large lock that consistently runs out of time, or lower it for a stricter cap on
install latency:

```json
{
    "extra": {
        "lockrot": {
            "install-time-budget": 20
        }
    }
}
```

Integer seconds, 1–120. Like the other install-time keys it has no CLI option and no environment
override.

## Never fails the install

A failed lookup — an unreachable repository, an exhausted budget — is reported, not raised: it is
data lockrot did not get, not a reason to stop. Only an error lockrot cannot interpret at all (a
malformed `extra.lockrot`, an unreadable `composer.lock`, a defect in lockrot) becomes a single
`lockrot: install-time check skipped: …` line — and even then the install continues.

The one exception is `install-time-strict`.

## `install-time-strict`

```json
{
    "extra": {
        "lockrot": {
            "install-time-strict": true
        }
    }
}
```

This turns the summary into a gate: when a finding reaches the `fail-on` threshold, lockrot stops the
transaction before any operation runs, and `composer require`/`update`/`install` exits `1` through
Composer's own error handling.

What that leaves behind is Composer's behaviour, not lockrot's, and is worth knowing. By the time the
check runs, `composer require` has already added the package to `composer.json` and written the new
`composer.lock`, and Composer's automatic revert of those two files is already disarmed at that
point. So a blocked `composer require` leaves `composer.json` and `composer.lock` updated with
nothing installed in `vendor/`; `composer install` (or `git checkout composer.json composer.lock`) is
the way back.

A finding a [baseline](baseline.md) already carries does not stop a `composer require`. The compact
block still lists it.

## A note on the `--dry-run` development flag

The block reads a package's development flag from the lock the transaction is about to leave behind.
Under `composer require --dev … --dry-run` no such lock is written, so a package that is not in the
lock yet is treated as production and its [priority](verdicts.md) can read one step high.
`composer lockrot` on the real lock always has the flag.

## Related

- [configuration.md](configuration.md) — `install-time`, `install-time-strict`, `install-time-budget`
- [verdicts.md](verdicts.md) — what the verdicts and priorities mean
