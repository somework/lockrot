# Mutants no test can observe

The escapes the mutation gate (`infection.json5`, whole `src/` tree) tolerates, with the reason each
one is equivalent to the original code. One entry per mutant, by area, the nine escapes of the
original gate (src/Verdict, src/Signal, src/Output, src/Analyzer) first. Line numbers are as of 2026-09-17; a later edit may shift them without changing the
argument. "Hard to test" is not "equivalent": every entry here claims that no test could tell the
mutant from the original, and says why.

## src/Verdict, src/Signal, src/Analyzer, src/Output (the original gate, 2026-09-16)

- `src/Signal/ConstraintOpenness.php:46` CastInt, `:48` CastInt, IncrementInteger, DecrementInteger,
  `:62` ConcatOperandRemoval — numeric strings compare numerically, every mutated integer stays
  below any PHP major, and `normalize("8.4")` equals `normalize("8.4.0")`.
- `src/Analyzer/Report.php:96` UnwrapArrayValues — `flagged()`: the findings are sorted and every
  flagged one precedes every unflagged one, so the filtered keys are already `0..n`; the
  `array_values()` is what makes the `list` type true by construction.
- `src/Output/JsonFormatter.php:20` FalseValue — the `$showAll` default of the interface's
  parameter, which the JSON document does not read (it always lists every finding).
- `src/Output/TableFormatter.php:174` CastString — `label()`: `previousVerdictOf()` is never null
  once the comparison says "worsened"; the cast is for the type, not for a case.
- `src/Output/TerminalWidth.php:30` Coalesce — `detect()`: step 2 (symfony Terminal) refuses
  whenever `COLUMNS` is set, so swapping it with step 1 (`COLUMNS`) is unobservable by design.

## src/SelfUpdate and src/Composer/SelfUpdateCommand.php

src/Composer/SelfUpdateCommand.php:130 FalseValue (`\Phar::running(false)` → `\Phar::running(true)`) — the
two differ only inside a running PHAR, where `false` gives `/path/lockrot.phar` and `true` gives
`phar:///path/lockrot.phar`; the unit suite is not running from a PHAR, so both return `''` and take the same
branch. The difference is exercised by `tests/E2E/PharTest.php::testSelfUpdateFinishesCleanlyAfterReplacingTheRunningArchive`,
which replaces a real archive in place and would fail on a `phar://` path, but Infection runs the `unit` and
`integration` suites only (`@group e2e` is excluded in phpunit.xml.dist), so no test it runs can see it.

src/Composer/SelfUpdateCommand.php:168 ConcatOperandRemoval (drops the closing `'</error>'`) — Symfony's
OutputFormatter wraps each text chunk in the current style's opening *and* closing sequences, so an unclosed
`<error>` tag renders byte-for-byte like a closed one (verified: `<error>lockrot: boom` and
`<error>lockrot: boom</error>` both come out as `ESC[37;41mlockrot: boom ESC[39;49m`). The only way a leaked
style could be observed is a later write on the same output, and this line is the last thing the command
writes before returning. The *reordering* mutant on the same line is observable and is killed by
`testOnATerminalTheWholeErrorIsStyledAndNotJustItsPrefix`.

src/Composer/SelfUpdateCommand.php:172 ConcatOperandRemoval (drops the closing `'</error>'`) — the same, on the
`\Throwable` branch; killed counterpart is `testAFailureThatIsNotAConfigErrorIsStillOneLineAndExitTwo`. Both
mutants on this line are new: the branch had no coverage at the time escaped-A.txt was taken, so its mutants
were counted as uncovered rather than escaped.

## src/Composer (the rest) and src/Config

Mutants that no test can observe, one line each. `SelfUpdateCommand.php` escapes belong to area A and
are not listed here even though the area-B Infection config covers `src/Composer`.

### src/Composer/ComposerHttpClient.php

- `src/Composer/ComposerHttpClient.php:117` CastString — `(string) substr($origin, 4)`: the
  `in_array()` above admits only `api.bitbucket.org` and `api.github.com`, both far longer than 4
  characters, so `substr()` can never return the `false` PHP 7.4 documents for an out-of-range start.
  The cast states the type; it does not cover a case.
- `src/Composer/ComposerHttpClient.php:173` CastInt — `(int) ($e->getStatusCode() ?? 0)`:
  `TransportException::getStatusCode()` is declared `?int`, so the `?? 0` already leaves an int and
  the cast can never change a value. The two integer mutants on the same line — the `: 0` arm for a
  failure that is not a `TransportException` — *are* observable and are covered by
  `testAFailedRequestKeepsItsStatusAndMessage()`.

### src/Composer/InstallTimeSummary.php

- `src/Composer/InstallTimeSummary.php:116` UnwrapArrayValues — `RepositoryManager::getRepositories()`
  is only ever grown by `addRepository()` (append) and `prependRepository()` (`array_unshift`), so it
  is already a list and `array_values()` returns an identical array. It is what makes the
  `list<RepositoryInterface>` parameter type true, not a normalisation any input needs.
- `src/Composer/InstallTimeSummary.php:186` TrueValue — and
- `src/Composer/InstallTimeSummary.php:186` Foreach_ — the names this loop collects reach
  `BaselineComparison::compare()` as `$presentPackages`, which uses them for the *stale* list only.
  Nothing on the install-time path reads that list: `InstallSummaryFormatter` has no baseline section,
  and `Policy::exitCode()` consults only `isKnown()`, which comes from the statuses map. The same
  measurement in `LockrotCommand::lockPackageNames()` *is* observable and is covered by
  `LockrotCommandTest::testABaselineGeneratedWithDevReportsNoStaleEntriesOnARunWithoutDev()`.

### src/Composer/LockrotCommand.php

- `src/Composer/LockrotCommand.php:104` CastString — and
- `src/Composer/LockrotCommand.php:161` CastString — `(string) getcwd()`: `getcwd()` returns false
  only when the working directory has been removed or become unreadable under the running process,
  which would already have broken PHPUnit's own bootstrap. The cast is for the type.
- `src/Composer/LockrotCommand.php:158` Throw_ — not rethrowing `$this->bootstrapError` changes
  nothing a test can see: the next statement re-reads the same manifest through the same
  `ProjectConfig::fromFile()` call and raises the identical `ConfigException`, so the exit code and
  the message are the same. The load-bearing half of the mechanism is the early `return` in
  `initialize()`, which stops `parent::initialize()` from crashing on the manifest first; that half is
  covered by `testMalformedComposerJsonIsExit2()`.
- `src/Composer/LockrotCommand.php:364` UnwrapArrayValues — `RepositoryFactory::defaultRepos()` hands
  the repositories back keyed by their configuration name, and nothing downstream reads those keys:
  `RepositoryMetadataLoader` iterates the list and never indexes it. `array_values()` is the `list<>`
  type guarantee.
- `src/Composer/LockrotCommand.php:370` ReturnRemoval — without the early return, a directory with no
  composer.json reaches `tryComposer()`, i.e. `Application::getComposer(false)`, where
  `Factory::create()` throws `InvalidArgumentException` for the missing manifest and is swallowed
  because the call is not `$required`. Null comes back either way; the return only skips a call that
  cannot succeed.
- `src/Composer/LockrotCommand.php:375` FalseValue — `$this->getComposer(false)` is the Composer 2.2
  LTS arm of the `method_exists($this, 'tryComposer')` guard. The vendored Composer has
  `tryComposer()`, so that arm is never entered by any test on this runtime.
- `src/Composer/LockrotCommand.php:375` Ternary — swapping the arms puts `getComposer(false)` on the
  taken branch, and in Composer 2.3+ `BaseCommand::getComposer(false)` is literally
  `return $this->tryComposer($disablePlugins, $disableScripts);`. The two arms are the same call.

### src/Config/ConfigSchema.php

- `src/Config/ConfigSchema.php:42` LogicalOr (both mutants) — the PHPStan narrowing the source comment
  describes. Every error justinrainbow/json-schema produces is an array carrying string `property` and
  `message`, so no `extra.lockrot` can make the three operands disagree and no input reaches the
  `continue`.
- `src/Config/ConfigSchema.php:54` ReturnRemoval — dropping the memo makes `schema()` re-read and
  re-decode `resources/lockrot-config.schema.json` and return an equal object; nothing compares the
  schema by identity, so validation behaves identically and only the number of reads differs. Making
  that observable means removing or unreading a tracked resource file *while Infection runs the suite
  in parallel processes against it*, which would make other mutants fail for the wrong reason. This is
  the weakest equivalence claim in this list: the memo saves one 2 KB read per process, and removing
  it instead of documenting it is a defensible alternative.
- `src/Config/ConfigSchema.php:58` LogicalOr — `!is_file($path) && !is_readable($path)`: the two
  operands disagree only for a file that exists and cannot be read, which a file shipped inside the
  package never is. Even then the next guard raises a `ConfigException` when `file_get_contents()`
  returns false, so the method still refuses to run on an unreadable schema.

### src/Config/Policy.php

- `src/Config/Policy.php:23` ReturnRemoval — `FailOn::reaches()` returns false for `none` by contract
  ("never for `none`"), so with the early return gone the loop rejects every finding and the method
  still falls through to `return self::EXIT_OK`. The early return skips the loop; it does not decide
  anything.

## src/Data, src/Baseline, src/Allowlist, src/Graph, src/Lock, src/Json

One line per mutant no test can see. Line numbers are the current ones, as
`build/infection-C.log` reported them after this round of work; all 27 escapes of that run are
listed below.

### Set membership written as `= true`, read only through `isset()`

The value is never read, so writing `false` there is the same program. Four of these:

- `src/Data/Forge/ActivityClient.php:80` TrueValue — `$rateLimited[$forge] = true`; the only reader is
  `ActivityBatch::rateLimited()`, which is `isset($this->rateLimited[$forge])`.
- `src/Data/Forge/ActivityFetchPlanner.php:51` TrueValue — `$capped[$forge] = true`; read by
  `isset($capped[$forge])` in the `array_filter` that builds `cappedForges()`.
- `src/Data/Forge/ActivityFetchPlanner.php:67` TrueValue — `$seen[$repo->key()] = true`; read by
  `isset($seen[$repo->key()])`.
- `src/Data/Repository/RepositoryMetadataLoader.php:274` TrueValue — `$seen[$id] = true`; read by
  `isset($seen[$id])`.

### `array_values()` that only makes a `list` type true

Nothing indexes or compares the keys; every consumer iterates. Same family as the
`src/Analyzer/Report.php` escape already documented in infection.json5.

- `src/Data/Forge/ActivityClient.php:120` UnwrapArrayValues — `byHost()` returns host-keyed groups
  instead of a list, and `fetch()` only `foreach`es over them.
- `src/Data/Repository/RepositoryMetadataLoader.php:72` UnwrapArrayValues — `$remaining` keeps the
  gaps `array_unique()` leaves; it is iterated, `array_splice`d (which reindexes by position) and
  rebuilt as a list by `unresolved()`.

### Casts the type system already guarantees

- `src/Data/Forge/RepoLocator.php:139` CastString — `(string) preg_replace(...)` on the bare host;
  preg_replace returns null only when the pattern fails to compile, and this one is a literal.
- `src/Data/Forge/SupportSource.php:28` CastString — the same shape, `(string) preg_replace(...)`
  stripping a `/tree/<ref>` or `/src/<ref>` suffix, with the same literal-pattern argument.
- `src/Data/Php/PhpReleaseDates.php:29` CastString — `(string) $minor`; the key only reaches this
  line when it matches `^\d+\.\d+$`, and a key with a dot in it is never cast to int by PHP.

### Memoisation of a pure function

The memo changes how often the work is done, not what it answers.

- `src/Allowlist/AllowlistEntry.php:36` AssignCoalesce — `self::$parser ??= new VersionParser()`;
  VersionParser is stateless, so a fresh one normalises identically.
- `src/Baseline/BaselineSchema.php:70` ReturnRemoval — dropping the early `return self::$schema`
  re-reads and re-decodes the same bundled schema file and reassigns it.
- `src/Graph/DependencyGraph.php:115` ReturnRemoval — dropping the early
  `return $this->trees[$root]` recomputes the BFS tree of immutable edges and gets the same map.

### Branches the shipped inputs cannot enter

- `src/Baseline/BaselineSchema.php:41` LogicalOr (two mutants) — the narrowing guard over
  `$validator->getErrors()`. Every error justinrainbow/json-schema produces is an array with string
  `property` and `message`, so all three operands are false and `&&` agrees with `||` whichever
  pair is joined. The guard exists for PHPStan, as its own comment says.
- `src/Baseline/BaselineSchema.php:74` LogicalOr — `!is_file($path) || !is_readable($path)` on
  `resources/lockrot-baseline.schema.json`, a file shipped inside the package. No test can make it
  missing or unreadable, and both operands are false for the file that is there.
- `src/Baseline/BaselineFile.php:112` FunctionCallRemoval — `error_clear_last()` before the write.
  It only changes the reported reason if the failing call records no warning of its own, and every
  file_put_contents/rename failure records one, which replaces whatever was there regardless.
- `src/Baseline/BaselineFile.php:154` LogicalAnd — `is_string($message) && $message !== ''` in
  `reason()`. `$message` is null only when `error_get_last()` is null, which (per the entry above)
  cannot happen on a path that reaches `reason()`; and a recorded warning message is never `''`.
- `src/Allowlist/ProjectIgnoreList.php:71` DecrementInteger (`$matches[1]` → `$matches[0]`) — the
  year argument of `checkdate()`. `$matches[0]` is the whole `YYYY-MM-DD` match and `(int)` of it
  is `YYYY`, which is exactly `(int) $matches[1]`, for every input the regex accepts.
- `src/Data/Http/HttpResult.php:84` GreaterThan — `$errors['warning_count'] > 0`. On PHP >= 8.2
  `DateTimeImmutable::getLastErrors()` returns false when there is nothing to report, and when it
  does return an array either `$at === false` already short-circuited (error-only cases, trailing
  data included) or `warning_count` is at least 1. This mutant IS killed on the 7.4 leg of the
  matrix, where the array comes back with both counts at zero.

### Reassignments and reorderings with the same result

- `src/Data/Forge/ActivityClient.php:68` LessThan — `$result->fetchedAt() < $cachedAt` becoming
  `<=` only fires when the two are equal, and then it assigns `$cachedAt` a value equal to the one
  it holds.
- `src/Allowlist/ProjectIgnoreList.php:75` Concat — `$expires.'T23:59:59+00:00'` reversed to
  `'T23:59:59+00:00'.$expires`. PHP's date parser is order-insensitive for this pair:
  `new DateTimeImmutable('T23:59:59+00:002027-01-31')` is `2027-01-31T23:59:59+00:00`, the same
  instant as the intended spelling. (The operand-removal mutant on the same line IS killed, by
  `ProjectIgnoreListTest::testParsesEntries`.)
- `src/Data/Php/PhpReleaseDates.php:29` Concat — the same reversal, `'T00:00:00+00:00'.$date`,
  parses to the same instant. (The operand removal there is killed, by the timezone-pinned test.)
- `src/Json/JsonReader.php:84` ReturnRemoval — dropping `return false` for the empty array lets
  execution reach `array_keys([]) === range(0, -1)`, and `range(0, -1)` is `[0, -1]`, so the
  comparison is false and the method returns false anyway.
- `src/Data/Repository/RepositoryMetadataLoader.php:158` ReturnRemoval and `:200` TrueValue — both
  make pass 2 run after pass 1 stopped on the deadline. The deadline is monotonic, so pass 2's
  first `isPast()` check is already past: it starts no chunk, makes no request, and marks exactly
  the names in `needDev` with BUDGET_REASON — which is what the removed early return did by hand.
  Pass 1's `stillRemaining()` is always empty (only pass 2 populates it), so the batch that comes
  out is identical either way. Pass 2's own exhausted flag is never read.
- `src/Data/Forge/ActivityFetchPlanner.php:60` DecrementInteger and IncrementInteger — the `?? 0`
  in `$checkedPackages[$forge] = ($checkedPackages[$forge] ?? 0) + 1` inside the already-seen
  branch. Reaching that branch means some earlier package selected this repository, which set
  `$checkedPackages[$forge]` on the same forge, so the default is unreachable there.

## src/Data/Repository/ReleaseBranch.php and src/Signal/Rule/LeftBehindRule.php (left-behind, 2026-09-18)

- `src/Data/Repository/ReleaseBranch.php:32` PregMatchRemoveCaret — `of()` matches
  `^(\d+)\.(\d+)\.` against a string `VersionParser::normalize()` returned for a non-dev version,
  which always starts with the major digits: the anchor cannot move the match.
- `src/Signal/Rule/LeftBehindRule.php:51` ReturnRemoval — without the `return null` on a null
  branch, `$byBranch[null]` reads the key `''`, which no branch key ever is (they are `\d+` or
  `0.\d+`), so the next guard returns the same null. The early return is what makes the array
  read well-typed, not a case of its own.
- `src/Signal/Rule/LeftBehindRule.php:61` LessThanOrEqualTo — `$release['at'] <= $own['at']`
  becoming `<` admits a higher branch released the very same instant as the installed one's last
  release. That release then has to pass the liveness check (younger than `release-warn-years`)
  while the installed branch's last release, the same instant, has to be at least
  `release-warn-years` old for the signal to fire: both cannot hold, so the admitted release never
  produces a signal.
- `src/Data/Advisory/RepositoryAdvisoryLoader.php:67` ReturnRemoval — the Composer 2.2 arm of the
  `interface_exists(AdvisoryProviderInterface::class)` guard, never entered on the vendored
  Composer; the Docker 7.4/2.2 run outside Infection covers it
  (`RepositoryAdvisoryLoaderTest::testTheComposerVersionNoteMatchesTheApi`).

### Not equivalent: the two mutants this run reports as timed out

Both are genuine infinite loops in `loadChunked()`, so the timeout is a real detection and not a
slow test. They are listed here only so nobody reads them as an unexplained "2 time outs":

- `src/Data/Repository/RepositoryMetadataLoader.php:194` NotIdentical — `while ($toChunk !== [])`
  becoming `=== []` spins forever once the queue is empty: the condition stays true, the deadline
  is not past, and splicing an empty array yields an empty chunk, so nothing ever changes.
- `src/Data/Repository/RepositoryMetadataLoader.php:203` IncrementInteger — splicing from offset 1
  never removes the first name, so the queue never empties.
