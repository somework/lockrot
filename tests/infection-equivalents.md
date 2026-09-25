# Mutants no test can observe

The escapes the mutation gate (`infection.json5`, whole `src/` tree) tolerates, with the reason each
one is equivalent to the original code. One entry per mutant, by area, the nine escapes of the
original gate (src/Verdict, src/Signal, src/Output, src/Analyzer) first. Line numbers are those of
the Infection run each entry was written from, moved on 2026-09-25 past the `@internal` lines added
to every class docblock that day, so each still names the line it named before; a later edit may
shift them without changing the argument. "Hard to test" is not "equivalent": every entry here claims that no test could tell the
mutant from the original, and says why.

## src/Verdict, src/Signal, src/Analyzer, src/Output (the original gate, 2026-09-16)

- `src/Signal/ConstraintOpenness.php:48` CastInt, `:50` CastInt, IncrementInteger, DecrementInteger,
  `:79` ConcatOperandRemoval — numeric strings compare numerically, every mutated integer stays
  below any PHP major, and `normalize("8.4")` equals `normalize("8.4.0")`.
- `src/Analyzer/Report.php:133` UnwrapArrayValues — `flagged()`: the findings are sorted and every
  flagged one precedes every unflagged one, so the filtered keys are already `0..n`; the
  `array_values()` is what makes the `list` type true by construction.
- `src/Output/JsonFormatter.php:22` FalseValue — the `$showAll` default of the interface's
  parameter, which the JSON document does not read (it always lists every finding).
- `src/Output/TableFormatter.php:176` CastString — `label()`: `previousVerdictOf()` is never null
  once the comparison says "worsened"; the cast is for the type, not for a case.
- `src/Output/TerminalWidth.php:32` Coalesce — `detect()`: step 2 (symfony Terminal) refuses
  whenever `COLUMNS` is set, so swapping it with step 1 (`COLUMNS`) is unobservable by design.

## src/Analyzer/Libyears.php (0.11.0)

- `src/Analyzer/Libyears.php:181` LessThan (`strcmp(...) < 0` → `<= 0`) — `fromFindings()`: the
  tie-break between two findings with the same value compares their package names, and two findings
  in one report never share a name (the lock is keyed by it), so `strcmp` never returns 0 there and
  `<` and `<=` decide identically.

- `src/Analyzer/Libyears.php:143` GreaterThan (`$release['at'] > $newest` → `>=`) —
  `newestTrustedDateAbove()`: on a tie the two dates are equal, so keeping the first or taking the
  second yields the same instant; nothing downstream reads which branch it came from.

## src/Verdict/Finding.php (0.11.0, the successor)

- `src/Verdict/Finding.php:279` LogicalAnd (`is_string($replacement) && $replacement !== ''` → `||`)
  — `replacement()`: the one caller is `successor()`, which then requires a `/` and a name Composer
  accepts. Under `||` a null stays null (the ternary returns the value itself) and an empty string
  is returned instead of null, and an empty string has no `/`, so `successor()` answers null either
  way. Every surface — the `migrate to` clause, the JSON `replacement`, the `with_replacement`
  count — reads `successor()`, so nothing else can tell the two apart.

## src/Signal/PhpFloor.php and src/Signal/Rule/LeftBehindRule.php (0.11.0, the branch within reach)

- `src/Signal/PhpFloor.php:57` ReturnRemoval (`blocking()`, `if ($constraint === null) return null`)
  and `:104` ReturnRemoval (`project()`, `if ($projectPhp === null) return [null, null]`) — without
  the return, composer/semver's untyped `parseConstraints()` receives null, reads it as `""`, throws
  `UnexpectedValueException` ("Invalid version string"), and the `catch` below returns the same
  value. The early return says what a missing requirement means; the parser would say it too.
- `src/Signal/PhpFloor.php:77` CastString — `describe()`: `php($kind)` is null only for a floor that
  is not there, and `blocking()` never names a floor that is not there, so the cast is for the type,
  not for a case.
- `src/Signal/Rule/LeftBehindRule.php:84` LessThanOrEqualTo (`$release['at'] <= $own['at']` →
  `<`) — a higher branch released at the very instant of the installed branch's last release would
  become a candidate. For S8 to fire it would then have to be alive (released within
  `release-warn-years`) while the installed branch, released at the same instant, is at least
  `release-warn-years` old: the two cannot both hold, so the mutant never changes a verdict.

## src/SelfUpdate and src/Composer/SelfUpdateCommand.php

src/Composer/SelfUpdateCommand.php:143 FalseValue (`\Phar::running(false)` → `\Phar::running(true)`) — the
two differ only inside a running PHAR, where `false` gives `/path/lockrot.phar` and `true` gives
`phar:///path/lockrot.phar`; the unit suite is not running from a PHAR, so both return `''` and take the same
branch. The difference is exercised by `tests/E2E/PharTest.php::testSelfUpdateFinishesCleanlyAfterReplacingTheRunningArchive`,
which replaces a real archive in place and would fail on a `phar://` path, but Infection runs the `unit` and
`integration` suites only (`@group e2e` is excluded in phpunit.xml.dist), so no test it runs can see it.

src/Composer/SelfUpdateCommand.php:199 ConcatOperandRemoval (drops the closing `'</error>'`) — Symfony's
OutputFormatter wraps each text chunk in the current style's opening *and* closing sequences, so an unclosed
`<error>` tag renders byte-for-byte like a closed one (verified: `<error>lockrot: boom` and
`<error>lockrot: boom</error>` both come out as `ESC[37;41mlockrot: boom ESC[39;49m`). The only way a leaked
style could be observed is a later write on the same output, and this line is the last thing the command
writes before returning. The *reordering* mutant on the same line is observable and is killed by
`testOnATerminalTheWholeErrorIsStyledAndNotJustItsPrefix`.

src/Composer/SelfUpdateCommand.php:203 ConcatOperandRemoval (drops the closing `'</error>'`) — the same, on the
`\Throwable` branch; killed counterpart is `testAFailureThatIsNotAConfigErrorIsStillOneLineAndExitTwo`. Both
mutants on this line are new: the branch had no coverage at the time escaped-A.txt was taken, so its mutants
were counted as uncovered rather than escaped.

Both lines now pass the message through `SelfUpdateCommand::plain()` first; the mutant still only drops the
closing tag, and the reasoning above is unchanged (line numbers checked 2026-09-26).

src/SelfUpdate/ReleaseLocator.php:435 CastString (`(string) preg_replace(...)` → `preg_replace(...)`) — the
display version of a candidate. preg_replace() returns null only when the pattern fails to compile or the
backtrack limit is hit; the pattern is a literal that compiles, and the subject is a normalised stable version
(`\d+.\d+.\d+.\d+` with at most a short suffix), far below any limit. The cast states the `string` type the
candidate array promises; it never changes a value.

## src/Composer (the rest) and src/Config

Mutants that no test can observe, one line each. `SelfUpdateCommand.php` escapes belong to area A and
are not listed here even though the area-B Infection config covers `src/Composer`.

### src/Composer/ComposerHttpClient.php

- `src/Composer/ComposerHttpClient.php:119` CastString — `(string) substr($origin, 4)`: the
  `in_array()` above admits only `api.bitbucket.org` and `api.github.com`, both far longer than 4
  characters, so `substr()` can never return the `false` PHP 7.4 documents for an out-of-range start.
  The cast states the type; it does not cover a case.
- `src/Composer/ComposerHttpClient.php:175` CastInt — `(int) ($e->getStatusCode() ?? 0)`:
  `TransportException::getStatusCode()` is declared `?int`, so the `?? 0` already leaves an int and
  the cast can never change a value. The two integer mutants on the same line — the `: 0` arm for a
  failure that is not a `TransportException` — *are* observable and are covered by
  `testAFailedRequestKeepsItsStatusAndMessage()`.

### src/Composer/InstallTimeSummary.php

- `src/Composer/InstallTimeSummary.php:118` UnwrapArrayValues — `RepositoryManager::getRepositories()`
  is only ever grown by `addRepository()` (append) and `prependRepository()` (`array_unshift`), so it
  is already a list and `array_values()` returns an identical array. It is what makes the
  `list<RepositoryInterface>` parameter type true, not a normalisation any input needs.
- `src/Composer/InstallTimeSummary.php:188` TrueValue — and
- `src/Composer/InstallTimeSummary.php:188` Foreach_ — the names this loop collects reach
  `BaselineComparison::compare()` as `$presentPackages`, which uses them for the *stale* list only.
  Nothing on the install-time path reads that list: `InstallSummaryFormatter` has no baseline section,
  and `Policy::exitCode()` consults only `isKnown()`, which comes from the statuses map. The same
  measurement in `LockrotCommand::lockPackageNames()` *is* observable and is covered by
  `LockrotCommandTest::testABaselineGeneratedWithDevReportsNoStaleEntriesOnARunWithoutDev()`.

### src/Composer/LockrotCommand.php

- `src/Composer/LockrotCommand.php:119` CastString — and
- `src/Composer/LockrotCommand.php:176` CastString — `(string) getcwd()`: `getcwd()` returns false
  only when the working directory has been removed or become unreadable under the running process,
  which would already have broken PHPUnit's own bootstrap. The cast is for the type.
- `src/Composer/LockrotCommand.php:173` Throw_ — not rethrowing `$this->bootstrapError` changes
  nothing a test can see: the next statement re-reads the same manifest through the same
  `ProjectConfig::fromFile()` call and raises the identical `ConfigException`, so the exit code and
  the message are the same. The load-bearing half of the mechanism is the early `return` in
  `initialize()`, which stops `parent::initialize()` from crashing on the manifest first; that half is
  covered by `testMalformedComposerJsonIsExit2()`.
- `src/Composer/LockrotCommand.php:567` UnwrapArrayValues — `RepositoryFactory::defaultRepos()` hands
  the repositories back keyed by their configuration name, and nothing downstream reads those keys:
  `RepositoryMetadataLoader` iterates the list and never indexes it. `array_values()` is the `list<>`
  type guarantee.
- `src/Composer/LockrotCommand.php:573` ReturnRemoval — without the early return, a directory with no
  composer.json reaches `tryComposer()`, i.e. `Application::getComposer(false)`, where
  `Factory::create()` throws `InvalidArgumentException` for the missing manifest and is swallowed
  because the call is not `$required`. Null comes back either way; the return only skips a call that
  cannot succeed.
- `src/Composer/LockrotCommand.php:578` FalseValue — `$this->getComposer(false)` is the Composer 2.2
  LTS arm of the `method_exists($this, 'tryComposer')` guard. The vendored Composer has
  `tryComposer()`, so that arm is never entered by any test on this runtime.
- `src/Composer/LockrotCommand.php:578` Ternary — swapping the arms puts `getComposer(false)` on the
  taken branch, and in Composer 2.3+ `BaseCommand::getComposer(false)` is literally
  `return $this->tryComposer($disablePlugins, $disableScripts);`. The two arms are the same call.

### src/Config/ConfigSchema.php

- `src/Config/ConfigSchema.php:44` LogicalOr (both mutants) — the PHPStan narrowing the source comment
  describes. Every error justinrainbow/json-schema produces is an array carrying string `property` and
  `message`, so no `extra.lockrot` can make the three operands disagree and no input reaches the
  `continue`.
- `src/Config/ConfigSchema.php:56` ReturnRemoval — dropping the memo makes `schema()` re-read and
  re-decode `resources/lockrot-config.schema.json` and return an equal object; nothing compares the
  schema by identity, so validation behaves identically and only the number of reads differs. Making
  that observable means removing or unreading a tracked resource file *while Infection runs the suite
  in parallel processes against it*, which would make other mutants fail for the wrong reason. This is
  the weakest equivalence claim in this list: the memo saves one 2 KB read per process, and removing
  it instead of documenting it is a defensible alternative.
- `src/Config/ConfigSchema.php:60` LogicalOr — `!is_file($path) && !is_readable($path)`: the two
  operands disagree only for a file that exists and cannot be read, which a file shipped inside the
  package never is. Even then the next guard raises a `ConfigException` when `file_get_contents()`
  returns false, so the method still refuses to run on an unreadable schema.

### src/Config/Policy.php

- `src/Config/Policy.php:24` ReturnRemoval — `FailOn::reaches()` returns false for `none` by contract
  ("never for `none`"), so with the early return gone the loop rejects every finding and the method
  still falls through to `return self::EXIT_OK`. The early return skips the loop; it does not decide
  anything.

## src/Data, src/Baseline, src/Allowlist, src/Graph, src/Lock, src/Json

One line per mutant no test can see. Line numbers are the ones `build/infection-C.log` reported
after this round of work, moved on 2026-09-25 like every other in this file; all 27 escapes of that
run are listed below.

### Set membership written as `= true`, read only through `isset()`

The value is never read, so writing `false` there is the same program. Four of these:

- `src/Data/Forge/ActivityClient.php:82` TrueValue — `$rateLimited[$forge] = true`; the only reader is
  `ActivityBatch::rateLimited()`, which is `isset($this->rateLimited[$forge])`.
- `src/Data/Forge/ActivityFetchPlanner.php:53` TrueValue — `$capped[$forge] = true`; read by
  `isset($capped[$forge])` in the `array_filter` that builds `cappedForges()`.
- `src/Data/Forge/ActivityFetchPlanner.php:69` TrueValue — `$seen[$repo->key()] = true`; read by
  `isset($seen[$repo->key()])`.
- `src/Data/Repository/RepositoryMetadataLoader.php:275` TrueValue — `$seen[$id] = true`; read by
  `isset($seen[$id])`.

### `array_values()` that only makes a `list` type true

Nothing indexes or compares the keys; every consumer iterates. Same family as the
`src/Analyzer/Report.php` escape already documented in infection.json5.

- `src/Data/Forge/ActivityClient.php:122` UnwrapArrayValues — `byHost()` returns host-keyed groups
  instead of a list, and `fetch()` only `foreach`es over them.
- `src/Data/Repository/RepositoryMetadataLoader.php:73` UnwrapArrayValues — `$remaining` keeps the
  gaps `array_unique()` leaves; it is iterated, `array_splice`d (which reindexes by position) and
  rebuilt as a list by `unresolved()`.

### Casts the type system already guarantees

- `src/Data/Forge/RepoLocator.php:141` CastString — `(string) preg_replace(...)` on the bare host;
  preg_replace returns null only when the pattern fails to compile, and this one is a literal.
- `src/Data/Forge/SupportSource.php:30` CastString — the same shape, `(string) preg_replace(...)`
  stripping a `/tree/<ref>` or `/src/<ref>` suffix, with the same literal-pattern argument.
- `src/Data/Php/PhpReleaseDates.php:30` CastString — `(string) $minor`; the key only reaches this
  line when it matches `^\d+\.\d+$`, and a key with a dot in it is never cast to int by PHP.

### Memoisation of a pure function

The memo changes how often the work is done, not what it answers.

- `src/Allowlist/AllowlistEntry.php:37` AssignCoalesce — `self::$parser ??= new VersionParser()`;
  VersionParser is stateless, so a fresh one normalises identically.
- `src/Baseline/BaselineSchema.php:72` ReturnRemoval — dropping the early `return self::$schema`
  re-reads and re-decodes the same bundled schema file and reassigns it.
- `src/Graph/DependencyGraph.php:116` ReturnRemoval — dropping the early
  `return $this->trees[$root]` recomputes the BFS tree of immutable edges and gets the same map.

### Branches the shipped inputs cannot enter

- `src/Baseline/BaselineSchema.php:43` LogicalOr (two mutants) — the narrowing guard over
  `$validator->getErrors()`. Every error justinrainbow/json-schema produces is an array with string
  `property` and `message`, so all three operands are false and `&&` agrees with `||` whichever
  pair is joined. The guard exists for PHPStan, as its own comment says.
- `src/Baseline/BaselineSchema.php:76` LogicalOr — `!is_file($path) || !is_readable($path)` on
  `resources/lockrot-baseline.schema.json`, a file shipped inside the package. No test can make it
  missing or unreadable, and both operands are false for the file that is there.
- `src/Filesystem/AtomicWriter.php:43` FunctionCallRemoval — `error_clear_last()` before the write
  (moved out of `BaselineFile::write()` on 2026-09-25, when the reports of `--output` started going
  through the same writer; it was `src/Baseline/BaselineFile.php:112`).
  It only changes the reported reason if the failing call records no warning of its own, and every
  fopen/chmod/rename failure on a real file records one, which replaces whatever was there
  regardless. (A short write records none — a full disk — and then the reason may be an older
  warning; the message still says which file could not be written.)
- `src/Allowlist/ProjectIgnoreList.php:72` DecrementInteger (`$matches[1]` → `$matches[0]`) — the
  year argument of `checkdate()`. `$matches[0]` is the whole `YYYY-MM-DD` match and `(int)` of it
  is `YYYY`, which is exactly `(int) $matches[1]`, for every input the regex accepts.
- `src/Data/Http/HttpResult.php:85` GreaterThan — `$errors['warning_count'] > 0`. On PHP >= 8.2
  `DateTimeImmutable::getLastErrors()` returns false when there is nothing to report, and when it
  does return an array either `$at === false` already short-circuited (error-only cases, trailing
  data included) or `warning_count` is at least 1. This mutant IS killed on the 7.4 leg of the
  matrix, where the array comes back with both counts at zero.

### Reassignments and reorderings with the same result

- `src/Data/Forge/ActivityClient.php:70` LessThan — `$result->fetchedAt() < $cachedAt` becoming
  `<=` only fires when the two are equal, and then it assigns `$cachedAt` a value equal to the one
  it holds.
- `src/Allowlist/ProjectIgnoreList.php:76` Concat — `$expires.'T23:59:59+00:00'` reversed to
  `'T23:59:59+00:00'.$expires`. PHP's date parser is order-insensitive for this pair:
  `new DateTimeImmutable('T23:59:59+00:002027-01-31')` is `2027-01-31T23:59:59+00:00`, the same
  instant as the intended spelling. (The operand-removal mutant on the same line IS killed, by
  `ProjectIgnoreListTest::testParsesEntries`.)
- `src/Data/Php/PhpReleaseDates.php:30` Concat — the same reversal, `'T00:00:00+00:00'.$date`,
  parses to the same instant. (The operand removal there is killed, by the timezone-pinned test.)
- `src/Json/JsonReader.php:86` ReturnRemoval — dropping `return false` for the empty array lets
  execution reach `array_keys([]) === range(0, -1)`, and `range(0, -1)` is `[0, -1]`, so the
  comparison is false and the method returns false anyway.
- `src/Data/Repository/RepositoryMetadataLoader.php:159` ReturnRemoval and `:201` TrueValue — both
  make pass 2 run after pass 1 stopped on the deadline. The deadline is monotonic, so pass 2's
  first `isPast()` check is already past: it starts no chunk, makes no request, and marks exactly
  the names in `needDev` with BUDGET_REASON — which is what the removed early return did by hand.
  Pass 1's `stillRemaining()` is always empty (only pass 2 populates it), so the batch that comes
  out is identical either way. Pass 2's own exhausted flag is never read.
- `src/Data/Forge/ActivityFetchPlanner.php:62` DecrementInteger and IncrementInteger — the `?? 0`
  in `$checkedPackages[$forge] = ($checkedPackages[$forge] ?? 0) + 1` inside the already-seen
  branch. Reaching that branch means some earlier package selected this repository, which set
  `$checkedPackages[$forge]` on the same forge, so the default is unreachable there.

## src/Data/Repository/ReleaseBranch.php and src/Signal/Rule/LeftBehindRule.php (left-behind, 2026-09-18)

- `src/Data/Repository/ReleaseBranch.php:35` PregMatchRemoveCaret — `of()` matches
  `^(\d+)\.(\d+)\.(\d+)\.` against a string `VersionParser::normalize()` returned for a non-dev version,
  which always starts with the major digits: the anchor cannot move the match.
- `src/Signal/Rule/LeftBehindRule.php:68` LessThanOrEqualTo — `$release['at'] <= $own['at']`
  becoming `<` admits a higher branch released the very same instant as the installed one's last
  release. That release then has to pass the liveness check (younger than `release-warn-years`)
  while the installed branch's last release, the same instant, has to be at least
  `release-warn-years` old for the signal to fire: both cannot hold, so the admitted release never
  produces a signal.
- `src/Data/Advisory/RepositoryAdvisoryLoader.php:69` ReturnRemoval — the Composer 2.2 arm of the
  `interface_exists(AdvisoryProviderInterface::class)` guard, never entered on the vendored
  Composer; the Docker 7.4/2.2 run outside Infection covers it
  (`RepositoryAdvisoryLoaderTest::testTheComposerVersionNoteMatchesTheApi`).

### Not equivalent: the two mutants this run reports as timed out

Both are genuine infinite loops in `loadChunked()`, so the timeout is a real detection and not a
slow test. They are listed here only so nobody reads them as an unexplained "2 time outs":

- `src/Data/Repository/RepositoryMetadataLoader.php:195` NotIdentical — `while ($toChunk !== [])`
  becoming `=== []` spins forever once the queue is empty: the condition stays true, the deadline
  is not past, and splicing an empty array yields an empty chunk, so nothing ever changes.
- `src/Data/Repository/RepositoryMetadataLoader.php:204` IncrementInteger — splicing from offset 1
  never removes the first name, so the queue never empties.

## pre-post-fixes (2026-09-19)

- `src/Signal/Rule/LeftBehindRule.php:72` LessThanOrEqualTo — a higher branch released at the very
  instant the installed branch's last release was is skipped by `<=` and kept by `<`; kept, it is the
  newest candidate at the installed branch's own date, and the signal then needs that date to be
  both at least `release-warn-years` old (the branch) and younger than `release-warn-years` (the
  move-on) — impossible, so the rule returns null either way.
- `src/Composer/LockrotCommand.php:324` `explain()` LogicalOr on `$finding === null || $facts === null` —
  `Analysis::finding()` and `Analysis::facts()` are filled by the same loop over the same packages
  in `Analyzer::analyzeWithFacts()`, so one is null exactly when the other is; and the lock lookup
  two lines up already rejects every name the run does not analyse, so the branch never runs. The
  check is for the types.

## JSON schemas and monorepo-dated branches (2026-09-20)

- `src/Analyzer/Analyzer.php:232` ReturnRemoval — `dateSplitPackages()` returns early when no
  package needs dates. Without the return the method runs on: `missingCandidates()` intersects
  every parent's list with an empty children list and returns nothing, no request is made, and
  `date([])` hands the batch back untouched. The guard states the common case, it does not decide it.
- `src/Data/Repository/MonorepoParents.php:116` ReturnRemoval — the same guard one level down, with
  the same argument: `array_intersect($replaces, [])` is empty for every parent, so the loop below
  selects nothing and the method returns `[]` either way.
- `src/Data/Repository/PackageMetadata.php:191` TrueValue — `$replaces[$link->getTarget()] = true`
  is set membership read only through `array_keys()`; the value is never looked at, so `false`
  builds the same list. The same shape as `ActivityClient.php:82` above.
- `src/Data/Repository/PackageMetadata.php:308` TrueValue — `$sharedCommitVersions[$normalized] = true`
  is set membership read only through `isset()`, which is true for a `false` value as well; the
  value is never looked at.
- `src/Data/Repository/PackageMetadata.php:353` ReturnRemoval — `needsParentDates()` returns false
  for a branch snapshot, which has no branch. Without the return the lookup runs with a null key,
  PHP reads it as `''`, no branch is keyed by the empty string, and the version check after it
  finds a branch name (`dev-main`, `2.x-dev`) among no stable tags, so the method returns false all
  the same. The early return is the statement of intent.

## The html report (2026-09-21)

Measured over `src/Html/ReportDocument.php`, `src/Html/PageData.php`, `src/Output/HtmlFormatter.php`
and `src/Data/Repository/RepositoryUrl.php`: 169 mutants, 166 killed, MSI = Covered MSI 98%, ~1m45s
on 11 threads. Re-measured 2026-09-21 after the page was taught which verdicts are findings; the
pass before that one escaped seven, and four were a real gap — nothing asserted that the page's
description names the three *biggest* verdicts, so `arsort()` and the `array_slice()` bounds were
both free to change. A run with five flagged verdicts and distinct counts now pins the order and
the cut.
The first pass escaped 18; sixteen of them were real gaps in the tests and are killed now — the
whole `context` array is asserted rather than two of its keys, `repository_link` is asserted across
five sources including one that is not a URL, `trim()` is exercised by a padded URL, the default
value of `$showAll` is exercised against a run that has facts, and the payload is asserted to keep
its slashes unescaped. Two are equivalent:

- `src/Html/ReportDocument.php:83` Continue_ — `continue` becomes `break` in the skip for a package
  that is not worth explaining. `Report::compare()` orders findings by priority rank first, and an
  unflagged package has no priority at all, so the packages this branch skips are always a suffix of
  the list. Breaking out of the loop at the first of them selects exactly what stepping over each of
  them selects. It is `continue` because the loop's condition is about one package, not about where
  the list stops.
- `src/Analyzer/RunSettings.php:66` UnwrapArrayValues — `array_values()` falls away from
  `flagged_verdicts`. `Verdict::all()` returns the keys of `SEVERITY` in declaration order and the
  flagged ones are the first six of them, so `array_filter()` leaves 0..5 and the reindex changes
  nothing that a test can see. It stays because the day a flagged verdict is declared below an
  unflagged one, the filter leaves a gap in the keys and `json_encode` writes an object where the
  page expects a list. The defence is for a future ordering, and a test cannot reach it without
  rewriting `SEVERITY`.
- `src/Output/HtmlFormatter.php:129` BitwiseOr — `ENT_QUOTES | ENT_SUBSTITUTE` becomes `&`, which is
  `0`, so quotes stay unescaped and invalid UTF-8 is not substituted. `text()` has one caller and it
  is `title()`, which builds its string from two integers and literal words: no quote and no invalid
  byte can reach it today. The flags are there so that stays true if the title ever grows a value
  from the lock, and a test cannot tell the difference until it does.


## Unknown `extra.lockrot` keys (0.13.0)

Measured over the branch's changed lines (`--git-diff-lines` against `origin/main`, which takes in
`UnknownKeys` and `TerminalText` whole, the schema-error hint in `ProjectConfig` and the call sites
in the command and the install-time summary): 169 mutants, 167 killed, Covered MSI 98%. Earlier
passes escaped more, all real gaps or removable: nothing had two unknown keys in `ignore` entries,
so returning only the first went unseen (a test with three across two entries kills it); a cut that
kept part of a printable run after an escape was untested (`a\tbc…` kills the swapped
concatenation); and `TerminalText` once had a length guard, anchors and a bit mask that no input
could tell apart from their mutants, so it now reads one unit at a time with named groups and
subtracts the UTF-8 length marker instead. Two are equivalent, both on the guard in front of
`levenshtein()`:

- `src/Config/UnknownKeys.php:143` GreaterThan (`strlen($key) > 255` → `>=`) — `nearest()` gives up
  on a key of exactly 255 bytes instead of measuring it. Measured, it would come back empty anyway:
  it is at least 236 edits from the longest known key (19 bytes), far past the third of 255 the
  threshold allows, and no known key is long enough to contain it. Every length the mutant moves to
  the early return gets the same `null` the loop would give.
- `src/Config/UnknownKeys.php:144` ReturnRemoval — without the early return a key longer than 255
  bytes reaches `levenshtein()`. On PHP 8, where Infection runs, that function measures strings of
  any length, finds no known key within reach (by the same arithmetic) and returns no suggestion, so
  the result is the same. The guard is for PHP 7.4, whose `levenshtein()` emits a warning and returns
  `-1` past 255 bytes, and `-1` is within any threshold:
  `UnknownKeysTest::testAVeryLongKeyIsCutAndGetsNoSuggestion()` fails without it on the 7.4 leg of
  the test matrix, which Infection does not run.

## Several reports from one run: `--output` (2026-09-25)

Measured with `--git-diff-lines --git-diff-base=origin/main` over the branch: 236 mutants on the
changed lines. The first pass escaped 11; seven were real gaps and are killed now — nothing asserted
that the page an `--output=html:` file carries has its facts (`details`) when stdout is html too,
which left the facts gate and the `PageData` ternary free, and the `COMPOSER` manifest was only
tested with a trimmed `.json` name, which left `trim()` and the `.lock`-appending arm free. Two
escapes are the `AtomicWriter` pair above, moved from `BaselineFile` (the `reason()` LogicalAnd of
that pair has since been killed, see below). The other two are equivalent,
and so is the one the Windows-alias refusal added (259 mutants, 5 escapes, after it):

- `src/Composer/LockrotCommand.php:439` CastArray — `(array) $input->getOption('output')`. The option
  is declared `VALUE_IS_ARRAY`, and symfony/console returns an array for it in every case — `[]` when
  it is not given — so the cast never changes the value. It is there because `getOption()` is typed
  `mixed`, and a `foreach` over `mixed` is not something PHPStan lets through.
- `src/Output/ReportTargets.php:174` CastString — `(string)` around `OutputFormatter::format()`,
  which symfony/console types `?string` and returns null only for a null message; the table
  formatter always hands it a string. The cast is for the type.
- `src/Filesystem/Path.php:41` CastString — `(string) preg_replace(...)` in `isWindowsAlias()`, the
  shape of the `RepoLocator` cast above: preg_replace returns null only when the pattern fails to
  compile, and this one is a literal. The cast is for the type.

### The review fixes to `--output` (2026-09-26)

Same measurement after the fixes from the code review (files named on disk by device and inode,
dot segments folded by spelling, exclusive temporary files, kept permissions, every stderr line
escaped, known-name format parsing): 403 mutants on the changed lines, 16 escaped at first. Six
were real gaps and are killed now (three of them on one line) — the project's own composer.json was only tested through the
name rule, never as a second name on disk; no test replaced a file with execute bits; a short
write was never simulated (a stream wrapper stands in for the full disk now); and nothing pinned a
file in the root directory, which left the `rtrim()` in `Path::canonical()` free. The short-write
test also killed `reason()`'s LogicalAnd (`src/Filesystem/AtomicWriter.php:75`), listed as
equivalent until then: a short write records no warning, so the generic reason is reachable, and
its entry is gone. Four escapes are the entries above, at their new lines. The other five are
equivalent (403 mutants, 9 escapes, Covered MSI 97.8%):

- `src/Composer/SelfUpdateCommand.php:270` ConcatOperandRemoval — `'</'.$style.'>'` becomes `'</>'`.
  Symfony's formatter reads `</>` as "close the style opened last", and the line opens exactly one,
  `$style`, so both spellings render the same bytes. (`LockrotCommand`'s copy of the helper is
  killed by its own tests; the two stay separate because self-update must not load a class after it
  has replaced the archive it runs from.)
- `src/Filesystem/Path.php:119` LogicalOr and DecrementInteger x2 — `$a['ino'] === 0 ||
  $b['ino'] === 0`, the fallback to comparing resolved paths where a filesystem reports no inode.
  Every filesystem CI and a developer machine run on (ext4, APFS, tmpfs) reports one, so the
  condition is false on both sides and every mutant of it is false too; only a Windows filesystem
  without a file index reaches the fallback.
- `src/Output/ReportTarget.php:49` GreaterThan (`>` to `>=`) — two different format names of the
  same length cannot both be followed by a colon at the start of one spec, so an equal length never
  meets a second match: the longest-match rule and its `>=` twin pick the same name.
