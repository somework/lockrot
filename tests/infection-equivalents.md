# Mutants no test can observe

Each entry is a mutant that escapes the mutation gate (`infection.json5`), with the reason that no
test that Infection runs can tell it from the original code. "Hard to test" is not "equivalent".
Write an entry as

    - `src/Path/File.php` Mutator `the mutated line, as in the source` — reason

`tools/mutation/pr_gate.py` reads the file, the mutators and the code span. Put several mutators of
one line before one code span. Quote a line that holds a backtick in a double-backtick span. Write "Mutator x2" for a mutant that escapes twice. If the line occurs more than once in its file,
start the reason with `in method():`.

## src/Allowlist

- `src/Allowlist/AllowlistEntry.php` AssignCoalesce `$parser = self::$parser ??= new VersionParser();` — a memo. `VersionParser` has no state, so a new instance normalises the same way.
- `src/Allowlist/ProjectIgnoreList.php` DecrementInteger `if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {` — `(int)` of the whole `YYYY-MM-DD` match is the year, so `$matches[0]` and `$matches[1]` give the same integer.
- `src/Allowlist/ProjectIgnoreList.php` Concat `return new \DateTimeImmutable($expires.'T23:59:59+00:00');` — the PHP date parser reads the date and the time in either order, so the swapped operands give the same instant.
- `src/Allowlist/BuiltinAllowlist.php` CastString `$ids[] = (string) $entry->reasonId();` — every built-in entry has an id, because `load()` rejects an entry without one, so the cast never changes a value.
- `src/Allowlist/BuiltinAllowlist.php` CastString `$ids[] = (string) AllowlistEntry::forType($type)->reasonId();` — `forType()` always sets the id `type-<type>`, so the cast never changes a value.

## src/Analyzer

- `src/Analyzer/Analyzer.php` ReturnRemoval `return [$metadata, []];` — in dateSplitPackages(): with no package to date, the rest of the method finds no candidate and makes no request.
- `src/Analyzer/Libyears.php` GreaterThan `if ($newest === null || $release['at'] > $newest) {` — on a tie the two dates are the same instant, and nothing reads which release gave it.
- `src/Analyzer/Libyears.php` LessThan `if ($behind > 0.0 && ($worst === null || $behind > $worst[1] || ($behind === $worst[1] && strcmp($finding->package(), $worst[0]->package()) < 0))) {` — two findings of one report never share a package name, so `strcmp()` never returns 0 here.
- `src/Analyzer/Report.php` UnwrapArrayValues `return array_values(array_filter($this->findings, static fn (Finding $f): bool => Verdict013::flagged($f->verdict())));` — the findings are sorted with every flagged one first, so the filter leaves the keys `0..n-1`.
- `src/Analyzer/Report.php` UnwrapArrayValues `return array_values(array_filter($this->sorted(), static fn (Finding $f): bool => $f->isGraded()));` — `sorted()` puts every graded finding first, so the filter leaves the keys `0..n-1`.
- `src/Analyzer/RunSettings.php` UnwrapArrayValues `'flagged_verdicts' => array_values(array_filter(Verdict::all(), [Verdict::class, 'flagged'])),` — the flagged verdicts come first in `Verdict::SEVERITY`, so the filter leaves the keys `0..n-1`. The call keeps the JSON a list if that order changes.

## src/Baseline

- `src/Baseline/BaselineSchema.php` LogicalOr x2 `if (!\is_array($error) || !\is_string($error['property'] ?? null) || !\is_string($error['message'] ?? null)) {` — a guard for PHPStan. Every error of justinrainbow/json-schema is an array with a string `property` and `message`, so every operand is false.
- `src/Baseline/BaselineSchema.php` Ternary `$number = \is_array($envelope) ? ($envelope['schema'] ?? null) : null;` — lockrot ships one baseline schema number, and `numberOf()` gives it for every file. A test can see the mutant when a second number ships.
- `src/Baseline/BaselineSchema.php` ReturnRemoval `return self::$schemas[$number];` — a memo. Without it the method decodes the same bundled file again.
- `src/Baseline/BaselineSchema.php` LogicalOr `if (!is_file($path) || !is_readable($path)) {` — the bundled schema file exists and is readable, so both operands are false.

## src/Clock.php

- `src/Clock.php` DecrementInteger, IncrementInteger `$seconds = max(0, $seconds);` — in tenthsOf(): one second either side of 0 rounds to 0 tenths of a year on both paths.

## src/Composer

- `src/Composer/ComposerHttpClient.php` CastString `$origin = (string) substr($origin, 4);` — the `in_array()` before it admits only hosts longer than 4 characters, so `substr()` never returns false.
- `src/Composer/ComposerHttpClient.php` CastInt `$status = $e instanceof TransportException ? (int) ($e->getStatusCode() ?? 0) : 0;` — `getStatusCode() ?? 0` is already an int.
- `src/Composer/InstallTimeSummary.php` UnwrapArrayValues `$repositories = array_values($composer->getRepositoryManager()->getRepositories());` — the repository manager only appends and prepends, so its array is already a list.
- `src/Composer/InstallTimeSummary.php` TrueValue, Foreach_ `foreach ($lock->packages(true) as $package) {` — these names reach `BaselineComparison::compare()` for the stale list only, and the install-time summary prints no stale list.
- `src/Composer/LockrotCommand.php` Throw_ `throw $this->bootstrapError;` — the statements after it read the same manifest and throw the same exception, so the exit code and the message stay the same.
- `src/Composer/LockrotCommand.php` CastString `$cwd = (string) getcwd();` — `getcwd()` returns false only when the working directory is gone, and then PHPUnit cannot start.
- `src/Composer/LockrotCommand.php` LogicalOr `if ($finding === null || $facts === null) {` — in explain(): one loop fills both, so one is null exactly when the other is.
- `src/Composer/LockrotCommand.php` CastArray `foreach ((array) $input->getOption('output') as $spec) {` — `--output` is `VALUE_IS_ARRAY`, so symfony/console always returns an array. The cast is for PHPStan.
- `src/Composer/LockrotCommand.php` UnwrapArrayValues `return [$config, array_values(RepositoryFactory::defaultRepos($io, $config, $manager))];` — nothing reads the keys of the repository list.
- `src/Composer/LockrotCommand.php` ReturnRemoval `return null;` — in resolveComposer(): without a composer.json, `tryComposer()` returns null too.
- `src/Composer/LockrotCommand.php` Ternary, FalseValue `$composer = method_exists($this, 'tryComposer') ? $this->tryComposer() : $this->getComposer(false);` — the vendored Composer has `tryComposer()`, and its `getComposer(false)` calls `tryComposer()`.
- `src/Composer/SelfUpdateCommand.php` FalseValue `$phar = $this->runningPhar ?? \Phar::running(false);` — the two calls differ only inside a running PHAR. Infection does not run the e2e `PharTest`.
- `src/Composer/SelfUpdateCommand.php` FunctionCallRemoval `class_exists(TerminalText::class);` — the call loads the class before the archive is replaced. From the source tree the autoloader also finds it later.
- `src/Composer/LockrotCommand.php` Coalesce `$lockrot->project() ?? $project->name(),` — in explain(): explain-2 writes no `run.project`, so no output reads the project name of this run.

## src/Config

- `src/Config/ConfigSchema.php` LogicalOr x2 `if (!\is_array($error) || !\is_string($error['property'] ?? null) || !\is_string($error['message'] ?? null)) {` — a guard for PHPStan. Every error of justinrainbow/json-schema is an array with a string `property` and `message`, so every operand is false.
- `src/Config/ConfigSchema.php` ReturnRemoval `return self::$schema;` — a memo. Without it the method decodes the same bundled file again.
- `src/Config/ConfigSchema.php` LogicalOr `if (!is_file($path) || !is_readable($path)) {` — the bundled schema file exists and is readable, so both operands are false.
- `src/Config/UnknownKeys.php` GreaterThan `if (\strlen($key) > self::LONGEST_KEY) {` — a key of `LONGEST_KEY` bytes is too far from every known key for a suggestion, so the loop also gives null.
- `src/Config/UnknownKeys.php` ReturnRemoval `return null;` — on PHP 8 `levenshtein()` measures a long key and finds no known key near it. The guard is for PHP 7.4, which Infection does not run.

## src/Data

- `src/Data/Forge/ActivityClient.php` LessThan `if ($decoded !== null && $result->fromCache() && ($cachedAt === null || $result->fetchedAt() < $cachedAt)) {` — `<=` differs only when the two dates are equal, and then the assignment keeps the same value.
- `src/Data/Forge/ActivityClient.php` TrueValue `$rateLimited[$forge] = true;` — a set. Only `isset()` reads it.
- `src/Data/Forge/ActivityClient.php` UnwrapArrayValues `return array_values($groups);` — `fetch()` only iterates the groups.
- `src/Data/Forge/ActivityFetchPlanner.php` TrueValue `$capped[$forge] = true;` — a set. Only `isset()` reads it.
- `src/Data/Forge/ActivityFetchPlanner.php` DecrementInteger, IncrementInteger `$checkedPackages[$forge] = ($checkedPackages[$forge] ?? 0) + 1;` — in select(): for a repository already seen, an earlier package set the counter of its forge, so `?? 0` never applies.
- `src/Data/Forge/ActivityFetchPlanner.php` TrueValue `$seen[$repo->key()] = true;` — a set. Only `isset()` reads it.
- `src/Data/Forge/RepoLocator.php` CastString `$bareHost = (string) preg_replace('{:\d+$}', '', $lowerHost);` — the pattern is a literal, so `preg_replace()` never returns null.
- `src/Data/Forge/SupportSource.php` CastString `$url = (string) preg_replace('{/(?:-/)?(?:tree|src)/[^/]+$}', '', $url);` — the pattern is a literal, so `preg_replace()` never returns null.
- `src/Data/Http/HttpResult.php` GreaterThan `if ($at === false || ($errors !== false && $errors['warning_count'] > 0)) {` — `getLastErrors()` returns false when it has nothing to report. The 7.4 leg of the test matrix kills it.
- `src/Data/Php/PhpReleaseDates.php` CastString, Concat `$dates[(string) $minor] = new \DateTimeImmutable($date.'T00:00:00+00:00');` — PHP never casts a key with a dot to an int, and the date parser reads the date and the time in either order.
- `src/Data/Repository/MonorepoParents.php` ReturnRemoval `return [];` — with no split package to date, the loop selects nothing and the method returns `[]` too.
- `src/Data/Repository/PackageMetadata.php` TrueValue `$replaces[$link->getTarget()] = true;` — a set. Only `array_keys()` reads it.
- `src/Data/Repository/PackageMetadata.php` TrueValue `$sharedCommitVersions[$normalized] = true;` — a set. Only `isset()` reads it.
- `src/Data/Repository/PackageMetadata.php` ReturnRemoval `return false;` — in needsParentDates(): a branch snapshot has no stable tag on its branch, so the checks after the return also give false.
- `src/Data/Repository/ReleaseBranch.php` PregMatchRemoveCaret `if (preg_match('/^(\d+)\.(\d+)\.(\d+)\./', $normalized, $m) !== 1) {` — a normalised stable version starts with its major digits, so the anchor changes no match.
- `src/Data/Repository/RepositoryMetadataLoader.php` ReturnRemoval `return new MetadataBatch($pass1->metadata(), $pass1->stillRemaining(), $failed);` — pass 2 after a spent deadline starts no chunk and marks the same names, so the batch is the same.
- `src/Data/Repository/RepositoryMetadataLoader.php` TrueValue `return new ChunkPassResult($metadata, $failed, $stillRemaining, $needDev, true);` — pass 2 after a spent deadline starts no chunk and marks the same names, so the batch is the same.
- `src/Data/Repository/RepositoryMetadataLoader.php` TrueValue `$seen[$id] = true;` — a set. Only `isset()` reads it.
- `src/Data/Repository/RepositoryUrl.php` DecrementInteger `return strncmp($shown, '...', 3) === 0;` — nothing that `shown()` returns starts with `..` and no third dot.
- `src/Data/Repository/RepositoryUrl.php` LessThan `return $at !== null && ($line === null || $at < $line) ? $at : null;` — in closer(): one pattern finds both offsets, each at its own character, so they are never equal.
- `src/Data/Abandoned/ComposerAbandonedPolicyReader.php` CastString `$rules[] = ['pattern' => (string) $pattern, 'reason' => $reason, 'constraints' => $constraints];` — Composer's `Config::merge()` renumbers an integer key, so no pattern reaches the reader as an integer.
- `src/Data/Abandoned/ComposerAbandonedPolicyReader.php` UnwrapArrayFilter `return array_filter($config, 'is_string', \ARRAY_FILTER_USE_KEY);` — Composer's policy readers look up named keys only, so an integer key that the filter drops changes nothing.
- `src/Data/Advisory/AdvisoryIgnore.php` FalseValue `return new self([], [], false);` — the flag sets only the `by` of a match, and an empty list matches nothing.
- `src/Data/Advisory/AdvisoryIgnore.php` FalseValue `return new self([], [], false, $why);` — the flag sets only the `by` of a match, and an empty list matches nothing.
- `src/Data/Advisory/AdvisoryIgnore.php` TrueValue `return new self([], [], true, null, ['policy_key' => $policyKey, 'value' => $value]);` — the flag sets only the `by` of a match, and an empty list matches nothing.
- `src/Data/Advisory/AdvisoryIgnore.php` CastString `$ids[(string) $key] = \is_string($reason) ? $reason : null;` — PHP stores a numeric string key as an integer either way, and `array_key_exists()` finds it by the string.
- `src/Data/Advisory/ComposerAdvisoryPolicyReader.php` UnwrapArrayFilter x2 `$advisories = AdvisoriesPolicyConfig::fromRawConfig(array_filter($policy, 'is_string', \ARRAY_FILTER_USE_KEY), array_filter($audit, 'is_string', \ARRAY_FILTER_USE_KEY), new VersionParser());` — Composer's policy readers look up named keys only, so an integer key that the filter drops changes nothing.
- `src/Data/Advisory/RepositoryAdvisoryLoader.php` TrueValue `$ids[$advisory->advisoryId] = true;` — only the keys are counted.
- `src/Data/Advisory/RepositoryAdvisoryLoader.php` CastString `$name = (string) $name;` — in attribute(): a package name holds a `/`, so PHP never stores it as an integer key.
- `src/Data/Advisory/RepositoryAdvisoryLoader.php` CastString `$name = (string) $name;` — in coverage(): a package name holds a `/`, so PHP never stores it as an integer key.
- `src/Data/Advisory/RepositoryAdvisoryLoader.php` UnwrapArrayValues `return array_values(array_filter($copies, static function (PartialSecurityAdvisory $advisory) use ($versions): bool {` — the caller only iterates the result with `foreach`.
- `src/Data/Advisory/RepositoryAdvisoryLoader.php` UnwrapArrayUnique `$feeds[] = ['composer_repository' => $repository['composer_repository'], 'answer' => AdvisoryCoverage::ANSWERED, 'reason' => null, 'message' => null, 'records' => \count(array_unique($records))];` — Composer's answer lists an advisory once per name and repository.

## src/Filesystem, src/Graph, src/Html, src/Json, src/Lock

- `src/Filesystem/AtomicWriter.php` FunctionCallRemoval `error_clear_last();` — every failing call records its own warning, which replaces the last one.
- `src/Filesystem/Path.php` CastString `return strpos($path, $root) === 0 ? (string) substr($path, \strlen($root)) : null;` — `$root` ends in `/` and `$path` does not, so `$path` is longer and `substr()` never returns false.
- `src/Filesystem/Path.php` LogicalOr, DecrementInteger x2 `if ($a['ino'] === 0 || $b['ino'] === 0) {` — the filesystems of CI and of developers report an inode. Only a Windows filesystem without a file index reaches the fallback.
- `src/Graph/DependencyGraph.php` ReturnRemoval `return $this->trees[$root];` — a memo. The edges do not change, so the tree is the same.
- `src/Graph/DependencyGraph.php` TrueValue `$present[$package->name()] = true;` — a set. Only `isset()` reads it.
- `src/Graph/DependencyGraph.php` ArrayItemRemoval `foreach (isset($present[$name]) ? [$name] : ($satisfiedBy[$name] ?? [$name]) as $package) {` — a name that no locked package satisfies has no edges: a walk follows only an existing edge, and `namedIn()` answers only for a package with edges.
- `src/Graph/DependencyGraph.php` TrueValue `$resolved[$package] = true;` — a set. Only its keys are read.
- `src/Html/ReportDocument.php` Continue_ `continue;` — in details(): the skipped packages are the end of the sorted list, so `break` selects the same packages.
- `src/Json/JsonReader.php` ReturnRemoval `return false;` — for an empty array, `array_keys([]) === range(0, -1)` is false, so the method returns false too.
- `src/Lock/ConfiguredRepositories.php` CastString `$host = (string) preg_replace('{:\d+$}', '', strtolower($parts[0]));` — the pattern is a literal, so `preg_replace()` never returns null.
- `src/Lock/ConfiguredRepositories.php` CastString `$path = (string) preg_replace('{\.git$}', '', trim(strtolower($parts[1]), '/'));` — the pattern is a literal, so `preg_replace()` never returns null.

## src/Output

- `src/Output/HtmlFormatter.php` BitwiseOr `return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');` — `title()` is the one caller, and its string holds no quote and no invalid byte.
- `src/Output/JsonFormatter.php` FalseValue `public function format(Report $report, bool $showAll = false): string` — the JSON document lists every finding and does not read `$showAll`.
- `src/Output/ReportTarget.php` GreaterThan `if (strpos($spec, $known.':') === 0 && \strlen($known) > \strlen($format)) {` — two format names of one length cannot both start a spec before its colon, so `>=` picks the same name.
- `src/Output/TableFormatter.php` CastString `return $finding->verdict().' (was '.(string) $baseline->previousVerdictOf($finding->package()).')';` — `previousVerdictOf()` is not null for a verdict that worsened. The cast is for the type.
- `src/Output/TerminalWidth.php` Coalesce `$width = self::fromEnv($env) ?? self::fromConsoleTerminal($env) ?? self::fromApplication($application) ?? FormatContext::DEFAULT_WIDTH;` — `fromConsoleTerminal()` gives null when `COLUMNS` is set, so the order of the first two steps does not matter.
- `src/Output/HtmlFormatter.php` UnwrapArrayFilter UnwrapArrayValues `return \is_array($reads) ? array_values(array_filter($reads, 'is_int')) : [];` — the vendored manifest lists its report numbers as a JSON list of integers, so the filter keeps every item and the keys stay `0..n-1`.

## src/Security

- `src/Security/FixFinder.php` CastString `$newest[(string) $key] = $branch['highest']['pretty'];` — PHP stores a decimal string key such as `"1"` as an int, so the cast changes no key. The cast is for the type.
- `src/Security/LinkIndex.php` TrueValue `$locked[$package->name()] = true;` — a set. Only `isset()` reads it.

## src/SelfUpdate

- `src/SelfUpdate/ReleaseLocator.php` CastString `'version' => (string) preg_replace('/^(\d+\.\d+\.\d+)\.0(?!\d)/', '$1', $normalized),` — the pattern is a literal and the subject a short normalised version, so `preg_replace()` never returns null.

## src/Signal

- `src/Signal/ConstraintOpenness.php` CastInt `$targetMajor = (int) explode('.', PhpReleaseDates::minorOf($targetPhp))[0];` — numeric strings compare as numbers.
- `src/Signal/ConstraintOpenness.php` CastInt, DecrementInteger, IncrementInteger `$lowerMajor = $lower->isZero() ? 0 : (int) explode('.', $lower->getVersion())[0];` — numeric strings compare as numbers, and 0 moved by 1 stays below every PHP major.
- `src/Signal/ConstraintOpenness.php` ConcatOperandRemoval `$target = new Constraint('==', $this->parser->normalize(PhpReleaseDates::minorOf($targetPhp).'.0'));` — `normalize('8.4')` equals `normalize('8.4.0')`.
- `src/Signal/PhpFloor.php` CastString `return ($kind === self::PROJECT ? 'the project\'s php ' : 'the target PHP ').(string) $this->php($kind);` — `php($kind)` is null only for an absent floor, and no caller describes one.
- `src/Signal/PhpFloor.php` ReturnRemoval `return null;` — in parse(): composer/semver reads null as `""` and throws, and the `catch` returns the same value.
- `src/Signal/Rule/LeftBehindRule.php` LessThanOrEqualTo `if ($release['at'] === null || !ReleaseBranch::isAbove((string) $key, $branch) || $release['at'] <= $ownAt) {` — a higher branch released at the instant of the installed branch cannot be alive while the installed branch is old enough for S8.

## src/Verdict

- `src/Verdict/Finding.php` LogicalAnd `return \is_string($replacement) && $replacement !== '' ? $replacement : null;` — in replacement(): `successor()` is the one caller and needs a `/`, so an empty string and null give the same answer.
