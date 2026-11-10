# Contributing to lockrot

Build lockrot and run the checks that CI runs. Change lockrot only in ways that keep its promises
to users. What semantic versioning covers is in [What 1.0
freezes](docs/compatibility.md#what-10-freezes), and your duties when a change touches it are under
[Backward compatibility](#backward-compatibility). How to write docs, comments, tests and changelog
entries is in [the rule files](#writing-the-changelog-and-the-docs).

## Getting set up

```bash
git clone https://github.com/somework/lockrot.git
cd lockrot
composer install
```

| Command | What it checks |
|---|---|
| `composer test` | PHPUnit: the unit and integration suites, offline, with the clock pinned by `LOCKROT_TODAY` in `phpunit.xml.dist` |
| `composer stan` | PHPStan at level max: `src/` as PHP 7.4 (`phpstan.neon.dist`), then `tests/` (`phpstan.tests.neon.dist`) |
| `composer cs` | php-cs-fixer, dry run with a diff (`.php-cs-fixer.dist.php`). `composer cs-fix` applies the fixes |
| `LOCKROT_E2E=1 GITHUB_TOKEN=... vendor/bin/phpunit --group e2e --filter PluginTest` | The plugin inside a real Composer. It needs the network |
| `build/build-phar.sh && LOCKROT_E2E=1 vendor/bin/phpunit --group e2e --filter PharTest` | The built PHAR. The test skips when `build/lockrot.phar` is missing |
| `GITHUB_TOKEN=... vendor/bin/phpunit --group network` | The tests that reach the real network |

On PHP 7.4 and 8.0, run `vendor/bin/phpunit -c phpunit9.xml.dist` in place of `composer test`.
Add `-c phpunit9.xml.dist` to the other PHPUnit rows too.

CI runs each of these commands (the PHPUnit suite on every PHP and Composer pair of the `tests`
matrix in `.github/workflows/ci.yml`) and these gates:

| Gate | Configured in | Run it locally |
|---|---|---|
| Core coverage | Directories: `phpunit.core-coverage.xml.dist`. Floor: the "Core coverage gate" step of `ci.yml` | With pcov or Xdebug: `vendor/bin/phpunit -c phpunit.core-coverage.xml.dist --coverage-clover build/clover-core.xml`, `composer global require rregeer/phpunit-coverage-check`, then `$(composer global config bin-dir --absolute)/coverage-check build/clover-core.xml <floor>` |
| Mutation score (Infection) | Shards and their `min_msi`: the `mutation` job of `ci.yml`. Whole-tree floor for a local run: `infection.json5` | On the PHP that `.github/actions/mutation-shard/action.yml` sets up, with pcov or Xdebug: `composer global config --no-plugins allow-plugins.infection/extension-installer true`, `composer global require infection/infection:<release in that action>`, the composer/composer pin step of that action, then `$(composer global config bin-dir --absolute)/infection --threads=max --with-uncovered` |
| Undeclared dependencies | `composer-require-checker.json` | `composer global require maglnet/composer-require-checker`, then `$(composer global config bin-dir --absolute)/composer-require-checker check --config-file=composer-require-checker.json composer.json` |
| Docs build | `mkdocs.yml` | `pip install -r docs/requirements.txt`, then `mkdocs build --strict` |
| Corpus self-test | `tools/corpus/tests/` | `tools/corpus/corpus selftest` |
| Move check oracle | `.github/actions/move-check-oracle`: one run on each composer/composer version that the train 7d cases record in `inputs.move_check.composer`. A version that no case records fails. Each run compares the result, form, holds and cause of an answer, and its installs, except for the baseline and a combined update | With each recorded composer/composer installed in turn: `LOCKROT_COMPOSER_ORACLE=1 vendor/bin/phpunit tests/Integration/MoveCheckComposerOracleTest.php` |
| Engine page recordings | The `engine-pages` job of `ci.yml` | Install the composer/composer version that `build/phar/composer.lock` holds. Then run `vendor/bin/phpunit --fail-on-skipped --fail-on-empty-test-suite tests/Integration/EnginePageCasesTest.php`, and the same command on `tests/Integration/BaselinedProjectTest.php`. On another Composer, `EnginePageCasesTest` skips, and `BaselinedProjectTest` does not compare the recorded files |
| PHAR build and byte-identical rebuild | `build/build-phar.sh` | `build/build-phar.sh` ([The PHAR](#the-phar)) |

Document an escaped mutant that no test can kill, with the reason, in
`tests/infection-equivalents.md`.

## What the code has to run on

| Floor | Declared in | What it rules out |
|---|---|---|
| PHP 7.4 | `composer.json` `require.php`, `phpstan.neon.dist` `phpVersion` | PHP 8 syntax: `match`, enums, constructor promotion, `readonly`, union types in signatures, the nullsafe operator, named arguments. PHP 8 functions such as `str_contains()` without a guard |
| Composer 2.10 | `composer.json` `"composer-plugin-api": "^2.9"` and the version check of `LockrotPlugin` (Composer 2.9 also reports plugin API 2.9.0) | Composer API added after 2.10 without a version guard |
| symfony/console 5.4 API | The Composer PHAR, which Composer builds for PHP 7.2.5 and which bundles console 5.4 | Console API added after 5.4 |

PHPUnit 9 runs the 7.4 and 8.0 rows, so a test declares each data provider twice: `@dataProvider`
in the docblock and `#[DataProvider]` as an attribute.

## Commits and pull requests

- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/): `feat:`,
  `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`, with the subject in the
  imperative.
- A pull request says what changed and why, and comes with tests.
- A change that a user can see updates the home section of its topic in the docs, in the same
  branch. It also adds or rewrites its entry under `## [Unreleased]` in `CHANGELOG.md`
  ([rules](#writing-the-changelog-and-the-docs)).

## Documentation

The public site, <https://lockrot.dev>, builds `docs/` from the newest release tag. A docs change
appears there with the next release. The landing page and the blog are in
[somework/lockrot.dev](https://github.com/somework/lockrot.dev), not here.

### Writing the changelog and the docs

Reviewers check the docs, comments, tests and changelog entry of every change against these files.

| File | Covers |
|---|---|
| [`.claude/rules/writing.md`](.claude/rules/writing.md) | Every sentence: Simplified Technical English adapted to lockrot, wording that stays true, less text, the glossary |
| [`.claude/rules/code-comments.md`](.claude/rules/code-comments.md) | Comments, docblocks and config comments: no comment by default, what a comment holds, docblock types |
| [`.claude/rules/tests.md`](.claude/rules/tests.md) | Tests that fail only on a defect: what to assert, numbers over recorded fixtures, hermetic tests |
| [`.claude/rules/docs/writing.md`](.claude/rules/docs/writing.md) | `README.md`, `CONTRIBUTING.md`, `SECURITY.md` and `docs/*.md`: the reader of each page, one page one mode, docs-only wording |
| [`.claude/rules/docs/topic-homes.md`](.claude/rules/docs/topic-homes.md) | The one page that holds each recurring topic, and what the other pages say about it |
| [`.claude/rules/docs/contracts.md`](.claude/rules/docs/contracts.md) | Anchors, links, list nesting, validated JSON samples, text that tests read, and the commands that check them |
| [`.claude/rules/changelog.md`](.claude/rules/changelog.md) | `CHANGELOG.md`: the release structure, the shape of an entry, what goes in which section, and what never goes in |

`composer test` includes the tests that read doc text and validate the JSON samples in `docs/`.
`mkdocs build --strict` checks the links and anchors in `docs/` and in `CHANGELOG.md`, which the
site includes. The site build does not check the links in `README.md`, `CONTRIBUTING.md` and
`SECURITY.md`. Check them by hand.

## Backward compatibility

Check a change against [What 1.0 freezes](docs/compatibility.md#what-10-freezes) and
[What is not contract](docs/compatibility.md#what-is-not-contract) before review.

The PHP classes under `src/` are not a public API and can change in any release. Every class,
interface, trait and enum there carries `@internal`, and `tests/Unit/PublicApiTest.php` fails on
one that does not.

The namespace `Lockrot\Extension\` is reserved. Declare nothing in it. `PublicApiTest` fails on a
class in it, or on a `src/Extension/` directory, in any letter case: PHP matches namespaces
without regard to case. The other reserved names are in
[Names reserved for extensions](docs/compatibility.md#names-reserved-for-extensions).

Your duties when a change touches the contract:

- A change that can alter the verdict or the priority of a package, or what
  `--fail-on=unchecked` matches, ships in a minor release. It gets an entry under
  `### Verdict changes`. A new signal that decides verdicts ships for one minor release as
  evidence only. The policy is in [Verdict changes](docs/compatibility.md#verdict-changes).
- A deprecation follows [Deprecation](docs/compatibility.md#deprecation).

## What lockrot writes

The canonical list of what lockrot reads, writes and contacts is
[What lockrot does and does not do](SECURITY.md#what-lockrot-does-and-does-not-do).

- A change that writes anything that the list does not name (a new file, a created directory, a
  cache in another place) changes that promise. It needs the maintainer's decision before review,
  and it updates `SECURITY.md` in the same branch.
- Every file that lockrot writes into a project goes through `Lockrot\Filesystem\AtomicWriter`.

## Fixtures are recorded, not written

- `tests/fixtures/` holds `composer.lock` and `composer.json` snapshots of public projects, with the
  Packagist and GitHub responses that go with them, so the suite runs offline.
  `tests/fixtures/README.md` lists every set.
- Record responses with `GITHUB_TOKEN=$(gh auth token) bin/record-fixtures [fixtureDir ...]`.
  Without arguments it records the default acceptance fixtures.
- Never hand-edit a recorded lock or response: take a new snapshot of the lock from its project,
  and re-record the responses. When a re-recording moves an acceptance assertion, compare that
  package's signals and verdict before you change the expected value.
- `tools/report/record` records the rendering contract bundle into `tests/fixtures/contract/`, and
  `tools/report/record --all-baselined` records the real baselined projects
  (`tests/fixtures/baselined/README.md`). Both run twice and write only identical results. Review the
  diff of a re-recording like a snapshot: lockrot-report pins `contract/bundle_sha256`. After a
  re-recording, run `node emit_bundle.js` in `tools/schema/node`: the `typescript` job of CI fails
  on a stale `tests/fixtures/bundle.generated.ts`.
- `bin/record-demo` records the README demo, `docs/assets/lockrot-demo.gif`, from a real run with
  asciinema and agg. Its header lists what to export. Re-record it when the table output changes
  shape.

## Changing a schema

The published schemas are `resources/lockrot-<document>-<number>.schema.json`, one file per
document and schema number. `tools/schema/` writes the files from number 2 on and their negative
fixtures ([tools/schema/README.md](tools/schema/README.md)). Do not edit one of those files by
hand. The `-1` files keep the bytes that v0.13.0 published.

To change a generated schema:

1. Edit its builder in `tools/schema/`.
2. Rebuild the schemas and the negatives:

    ```bash
    python3 tools/schema/build_report2_schema.py
    python3 tools/schema/build_other_schemas.py
    python3 tools/schema/make_negatives.py
    python3 tools/schema/make_negatives_other.py explain-2
    python3 tools/schema/make_negatives_other.py baseline-2
    python3 tools/schema/make_negatives_other.py config-2
    python3 tools/schema/build_sarif_properties_schema.py
    python3 tools/schema/make_negatives_sarif.py
    ```

3. Rebuild the TypeScript unions and probes: `python3 tools/schema/ts/emit_dts.py`.
4. Record the contract bundle with `tools/report/record`. It rewrites `tests/fixtures/contract/`,
   including `contract-files.json`, which holds the sha256 of the published schemas and types.
5. Write the bundle module, which reads that recording:
   `(cd tools/schema/node && node emit_bundle.js)`.
6. Commit the builder and what these steps wrote in one commit.

The CI step "The generated schemas and negatives match tools/schema" runs the commands of step 2
and fails on a difference. The `typescript` job runs steps 3 and 5 and fails on a stale
`types/lockrot-report-2.known.d.ts`, `tools/schema/ts/known-map.json`,
`tools/schema/ts/probes.generated.ts` or `tests/fixtures/bundle.generated.ts`.
`ContractBundleIsCurrentTest` fails on a stale recording (step 4).
`tests/Integration/SchemaRebuildTest.php` proves that the CI step catches a hand edit.

Under one schema number the files only widen: `tests/Integration/SchemaEvolutionTest.php` holds
them to every release's copy in `tests/fixtures/schema-evolution/schemas/<version>/`. It also holds
them to the documents that older release PHARs wrote, in `tests/fixtures/schema-evolution/<version>/`.
Pinned hashes freeze both fixture sets. If the test fails, fix the schema change, never the fixture.

| Change | Also required |
|---|---|
| A new value in an open set | Add it to the builder's `x-known-values` list, never to an `enum`. `tests/Unit/Json/KnownValuesRegistryTest.php` holds each list to the code |
| A new open set | Add a row for its place to `KnownValuesRegistryTest`. Name it in the list after "Objects are open" in [Open sets](docs/schema.md#open-sets), which `ClosedSetsTest` reads |
| A new relation (a described `allOf` entry) | Add a case that breaks it to `tools/schema/make_negatives.py`. `SchemaRelationsTest` needs a document that reaches each relation and a negative that breaks it |
| A new description | Keep it free of design references and draft history: `SchemaDescriptionsTest` applies the pattern of `tools/schema/ship_text.py` |
| A new signal | [Adding a signal](#adding-a-signal) |
| A new run note code | [Adding a run note code](#adding-a-run-note-code) |
| A new value in a closed set | Not possible under the same schema number ([Closed sets](docs/compatibility.md#closed-sets-and-their-order)) |

The widening check reads `x-known-values` as an enum on both sides: a value added there is a
widening, a value dropped is a narrowing. `ClosedSetsTest` keeps its checks of the `-1` files.

The steps below name the schema files. For a file from number 2 on, make each edit in its builder
and rebuild.

### Adding a signal

1. Add the constant to `Lockrot\Signal\Signal`.
2. Add the id to `x-known-values` of `definitions.signalId` in the report and explain schemas.
3. In the report schema, add a typed `anyOf` branch of its own to `definitions.signal`, before
   the last branch, whose `data` references a new `definitions.s<n>` (the id in lower case).
4. Add the id to the `not` list of that last branch.
5. Add the id to the expected list in `testTheFixturesExerciseEverySignal`
   (`JsonSchemaConformanceTest`), and make sure a recorded fixture produces it.
6. Add its row to [The signals](docs/verdicts.md#the-signals).
7. Add an entry under `### Added` in `CHANGELOG.md`: for its first minor release the signal is
   evidence only ([Verdict changes](docs/compatibility.md#verdict-changes)).

`composer test` fails until steps 1 to 5 agree. No test checks steps 6 and 7.

### Adding a run note code

1. Add the code to `RunNote::CODES`.
2. In the report and explain schemas, add the code to `x-known-values` of `definitions.noteCode`.
3. In the report and explain schemas, add a `definitions.note<Name>` for its data, and a branch
   that references it in `definitions.noteDetail`, before the last branch.
4. In the report and explain schemas, add the code to the `not` list of that last branch.
5. Write a `docs/notes.md` section whose heading id is the code. In it, say whether the note sets
   `sets_network_failures`. Add its row to the table of that page.
6. Append its URL to `PUBLISHED_NOTE_URLS` in `tests/Integration/NotesPageTest.php`.
7. Add the code to the list in `RunNoteTest`, and a sample note to the one-of-each list in
   `JsonSchemaConformanceTest`.

`composer test` fails until these agree.

### Recording a release

To record what a published release writes:

```bash
GITHUB_TOKEN=$(gh auth token) bin/record-schema-evolution <version> <YYYY-MM-DD>
```

The script verifies the release PHAR before it runs it and refuses a version that is already
recorded. Then add the version to every per-version constant in `SchemaEvolutionTest`. The
docblock of each constant says where its value comes from.
`testTheBaselineAnOlderReleaseWroteIsRefused` needs no change: it expects a refusal for a recorded
baseline-1 file and a successful read for a recorded baseline-2 file.

## Curated data

| File | What it decides | How to change it |
|---|---|---|
| `resources/finished-packages.json` | The built-in allowlist of packages that are complete rather than unmaintained: interface packages, polyfills, metapackages | A pull request adds one entry ([Contributing a finished package](#contributing-a-finished-package)) |
| `resources/monorepo-parents.json` | The monorepos that can date their split packages' releases, and the packages each replaces ([Dates from the monorepo](docs/verdicts.md#dates-from-the-monorepo)) | `bin/refresh-monorepo-parents [parent ...]`. Without arguments, it refreshes the listed parents. It adds each parent that you name |
| `resources/php-ga-dates.json` | The release date of each PHP minor version | Edit it by hand |

A change to any of these files is a [verdict change](docs/compatibility.md#verdict-changes). A
fix that only moves packages to `finished` or `ok` can ship in a patch.

### Contributing a finished package

```json
{"pattern": "psr/*", "id": "php-fig-interfaces", "reason": "PHP-FIG interface packages are complete by design; versions change only when the interface changes"}
```

| Field | Meaning |
|---|---|
| `pattern` | Package name. `*` is a wildcard |
| `id` | Required. A short kebab-case name for the reason, which report-2 writes as `allowlist.reason_id`. A new id also goes into the `x-known-values` list of `allowlist.reason_id` in `tools/schema/build_report2_schema.py`: rebuild after you add it |
| `version` | Optional. It pins one exact release |
| `reason` | One line on why the package is finished rather than stalled. Reviewers refuse "it is fine" |

## The PHAR

`build/build-phar.sh` builds `build/lockrot.phar` reproducibly from a clean checkout. Its header
comment lists what it pins. It needs the network to fetch Box, which it checks against a pinned
sha256. CI rebuilds the archive on a second PHP version (`phar-reproducible` in `ci.yml`) and
fails when the bytes differ.

The archive's dependencies are locked in `build/phar/composer.lock`, which is committed:

- When `require` or `autoload` in the root `composer.json` changes, refresh the lock and commit it
  with the change. CI's `phar` job runs this command and fails when the lock differs:

    ```bash
    composer update somework/lockrot --no-install --no-plugins --no-interaction --working-dir=build/phar
    ```

- To bump `composer/composer` or another dependency of the archive, run `composer update` in
  `build/phar/` and commit the lock.

### Rebuilding a release

To check that a published `lockrot.phar` is the one its tag builds:

```bash
git clone https://github.com/somework/lockrot.git && cd lockrot
git checkout <tag>                                     # the release you downloaded
mkdir -p /tmp/composer-pin                             # a Composer for this build only
curl -fsSL -o /tmp/composer-pin/composer https://getcomposer.org/download/<version>/composer.phar
chmod +x /tmp/composer-pin/composer
PATH=/tmp/composer-pin:$PATH build/build-phar.sh
sha256sum build/lockrot.phar                           # compare with the release's lockrot.phar.sha256
```

`<version>` is the Composer version that built the release: the `composer:<version>` in the
`tools:` entry of `.github/workflows/phar.yml` at that tag. Your global Composer does not change.

## The HTML report page

[somework/lockrot-report](https://github.com/somework/lockrot-report) builds the page that
`--format=html` writes. lockrot vendors one release
of it as `resources/report/report.html` and `manifest.json`, so the PHAR builds without node.

```bash
tools/report/update-renderer vX.Y.Z
```

- The script needs `gh`. It verifies that the renderer's `release.yml` attested the files for that
  tag, the page against the sha256 in its manifest, and that the manifest names the requested
  version. It writes the source commit that the attestations name to
  `tools/report/renderer-commit`. A tag can move, so `check-vendored` verifies that commit too.
  `tests/Unit/Output/RendererManifestTest.php` rechecks the page against the manifest on every run.
- `--from-dir DIR` vendors a local build for development. It is not attested. Never release with it.
- `vX.Y.Z-rc.N` vendors a pre-release. A lockrot release vendors a plain `vX.Y.Z`
  ([tools/report/README.md](tools/report/README.md#which-renderer-a-lockrot-release-vendors)).
- Do not edit the vendored page by hand: change the renderer, release it, update the pin.
- A bump gets one `html` entry under `### Changed` (`.claude/rules/changelog.md`, "Entry
  shape").
- `src/Html/ReportDocument.php` in lockrot decides which packages and facts go into the page.

## The corpus

`tools/corpus/` audits a finished run against the data that the run read, with code that is
independent of lockrot. It also reads each `--explain` page against the JSON that lockrot rendered
it from. It catches what the suite cannot: an assumption that the tests share with
the code.

Run it before a release that changes one of these:

- how lockrot chooses or trusts release dates (`src/Data/`)
- the signals (`src/Signal/`)
- a sentence that lockrot prints

A full run needs `GITHUB_TOKEN` and the network, and takes hours:

```bash
tools/corpus/corpus fetch
tools/corpus/corpus run     --phar build/lockrot.phar --out head --today <YYYY-MM-DD>
tools/corpus/corpus explain --phar build/lockrot.phar --out head --today <YYYY-MM-DD>
tools/corpus/corpus check   --out head --explain-out head
```

- `--today` pins every age, so a threshold that the calendar crosses does not read as a code change.
- `tools/corpus/corpus diff base head` compares two runs. It refuses runs whose day, token mode or
  cache differ.
- The tool is stdlib-only Python on the version in `.python-version`, with nothing to install. It
  does not inherit lockrot's PHP floor.
- Code under `tools/corpus/` must not import or call lockrot's PHP to decide an answer: a checker
  that asks its subject agrees with it by construction.

## Cutting a release

Each tag of this recipe, of lockrot or of lockrot-report, needs the explicit approval of the
maintainer.

1. On a release branch:

    - Set `Lockrot\Version::STRING` in `src/Version.php` to the new version.

    - Rename `## [Unreleased]` in `CHANGELOG.md` to `## [X.Y.Z] - YYYY-MM-DD`, and write its
      summary (`.claude/rules/changelog.md`). Then open a new empty `## [Unreleased]` above it. In
      the compare links at the end of the file, point `[Unreleased]` to `vX.Y.Z...HEAD` and add an
      `[X.Y.Z]` link. The release pull request fails without the two links.

    - The heading carries the day of the final `vX.Y.Z` tag, not the day of the rc tag. Write the
      planned tag day. If the final tag falls on another day, add a commit to the release pull
      request that changes only that date and `docs/example-run.md`. That commit changes no file
      of the contract bundle. If the day changes after the merge, open a pull request into `main`
      that changes only that date and `docs/example-run.md`. Then tag the commit that its merge
      writes on `main`.

    - Check `resources/php-ga-dates.json` against the PHP release schedule on php.net: the
      schedule page of the next minor (`https://wiki.php.net/todo/php<major><minor>`) and
      `https://www.php.net/releases/`. If php.net gives the GA date of a minor that the file does
      not hold, add the date. If a date in the file differs from the schedule or the release,
      correct it. lockrot counts a minor such as 8.6 from its GA day on. S5 reads the GA date of
      an X.0 release before its day, so add an X.0 date only after that release. Check the file
      before you record `docs/example-run.md`.

    - Re-record `docs/example-run.md` after the version bump. Build the PHAR from the release
      branch, so that it prints the new version ([The PHAR](#the-phar)):
      `GITHUB_TOKEN=<token> python3 tools/release/example_run.py build/lockrot.phar <planned tag day>`.
      The script runs the page's command on the two snapshots that the page links, with
      `LOCKROT_TODAY` set to that day. It refuses a run with a run note and a clean run that flags a
      package. It writes the table, the abridged report, the clean run and the sentence "Recorded on
      <day> with lockrot X.Y.Z". Then run `ExampleRunQuotesTest`. It fails when the page names a
      version before `Version::STRING`, or that version on another day than its CHANGELOG heading.
      Fix each page that quotes a line that the recording changed. If the tag day moves, record the page
      again for the date commit.

    - Copy every `resources/lockrot-*.schema.json` file to
      `tests/fixtures/schema-evolution/schemas/X.Y.Z/` and pin their sha256 in `RELEASED_SCHEMAS`
      in `SchemaEvolutionTest`. `SchemaEvolutionTest` fails unless the releases that `CHANGELOG.md`
      lists, the keys of `RELEASED_SCHEMAS` and the directories under `schemas/` match exactly. If
      a schema differs from the previous release's, add the version to `RECORDING_PENDING`.

    - Pin the sha256 of each formatter golden in `ChangedGoldensTest::RELEASED`, set
      `RELEASED_AT` to the new version, and empty `CHANGED`. From `FIRST_RELEASE` on,
      `ChangedGoldensTest` fails unless `RELEASED_AT` names the newest release that `CHANGELOG.md`
      lists. `VersionTest` fails unless `Version::STRING` names that release.

    - Vendor a renderer release ([The HTML report page](#the-html-report-page)) in this order. Each
      build of [lockrot-report](https://github.com/somework/lockrot-report) fetches its pinned
      lockrot commit by its sha. The squash merge into `main` writes a new commit and leaves the release
      branch's commits unreachable from `main`, so a tag must keep the pinned commit reachable.

        1. When the contract bundle changes, run `tools/report/record`. Commit it with the version,
           the dated CHANGELOG heading, the schema copies and the golden pins of this step.
        2. Tag that commit `vX.Y.Z-rc.N` and push the tag. The tag keeps the commit reachable for
           the renderer pre-release. `phar.yml` ignores a `-rc.` tag. Packagist lists it as a
           pre-release, which a project on stable minimum-stability does not install.
        3. Pin the renderer to that commit, then tag a renderer pre-release `vX.Y.Z-rc.M`. Run
           `tools/report/update-renderer vX.Y.Z-rc.M`, commit the files that it writes, and wait
           for the CI run of the push to the release branch to pass. A change to the renderer's
           `release.yml` then runs first on a pre-release. The release pull request's
           `check-vendored` step refuses a pre-release, so it passes only after step 8.
        4. Let V be the commit of step 3. Tag V `vX.Y.Z-rc.<N+1>` and push the tag. The
           renderer's `node scripts/contract.mjs reachable` accepts a commit on `main` or on a
           lockrot tag, and the squash merge never puts V on `main`.
        5. Push V unchanged to `release/full-check-<sha of V>`:
           `git push origin <V>:refs/heads/release/full-check-<V>`, with the full hex sha of V in
           both places. Wait for a green full check of the whole tree on that branch. The
           renderer's `release.yml` gate accepts a full check only from such a branch. The full
           check that the rc tag of step 4 starts does not count, because its branch is the tag.
           Then delete the branch.

           If the full check of V fails on a runner or network fault, re-run its failed jobs in the
           same workflow run first. If the full check of V is red on a defect, V cannot pass the
           gate, because no later run can turn a red run of V green. A pull request mutates only
           its changed lines, and a pull request that changes the contract bundle skips its
           mutation shards until the re-vendor. So the full check of V can be the first whole-tree
           run that fails. Fix the cause on the
           release branch, and move V to the commit whose full check is green. If the fix changes
           the contract bundle, do steps 1 to 3 again first. Then do steps 4 and 5 again for the
           new V. Give each new rc tag the next unused rc number of X.Y.Z, so the rc tag and the
           `release/full-check-<sha of V>` branch are new. Run `node scripts/contract.mjs repin <V>` of
           step 6 against the new V, and commit nothing. Do not repin the renderer to the old V.

           Before you tag the renderer in step 7, check the gate that its `release.yml` runs on
           the pin. The gate needs a green CI run of V, and a green full check on a
           `release/full-check-<sha>` branch or a green CI run on `main`. The query tests the same
           condition and must print `true`:

           ```bash
           gh run list --repo somework/lockrot --commit <V> --event push --status success \
             --json workflowName,headBranch \
             --jq 'any(.[]; .workflowName == "CI") and
               (any(.[]; .workflowName == "full-check" and (.headBranch | test("^release/full-check-[0-9a-f]+$")))
                or any(.[]; .workflowName == "CI" and .headBranch == "main"))'
           ```

           A renderer tag that the gate refuses has no release, and the renderer's tag ruleset
           keeps it, so that renderer version is lost.
        6. In lockrot-report, run `node scripts/contract.mjs repin <V>`. Set the version in
           `CHANGELOG.md`. Run `npm version X.Y.Z --no-git-tag-version`, which sets `version` in
           `package.json` and both `version` fields of `package-lock.json` (the root and
           `packages[""]`). Commit only the version and the pin.
           The renderer's `release.yml` refuses a tag when `package-lock.json` names a version
           other than the one in `package.json`. That tag then has no release, and the renderer's
           tag ruleset keeps it on its commit. If the tag run fails at that check, fix
           `package-lock.json` on the renderer's `main`. Then do this step and step 7 again with
           the next patch version of the renderer, and vendor that version in step 8. Name that
           version in the CHANGELOG line that names the vendored renderer.
        7. Merge that commit into the renderer's `main`. Then tag `vX.Y.Z` on `main`.
        8. Run `tools/report/update-renderer vX.Y.Z`, and commit only the files that it writes. This
           commit must not change the contract bundle.
        9. If `Version::STRING` still names the previous release, add one commit for the bump. It
           holds the version, the dated CHANGELOG heading, the schema copies with their
           `RELEASED_SCHEMAS` row and the golden pins of step 1. When a schema differs from the
           previous release's, it also holds the `RECORDING_PENDING` entry. Then re-record
           `docs/example-run.md` (`tools/release/example_run.py`) from a PHAR that prints the new
           version. Push the recording with the bump commit. The contract bundle masks the tool
           version, so this commit changes no file of the bundle. Without it, the release pull
           request fails (step 4).

2. Build the PHAR from a clean checkout of the release branch, and check that
   `php build/lockrot.phar --version` prints the new version.
3. Before the merge, check what the tag needs outside this repository:

    - List the required checks of main's ruleset, and compare them with `.github/workflows/ci.yml`.
      Run `gh api repos/somework/lockrot/rulesets`, then
      `gh api repos/somework/lockrot/rulesets/<id> --jq '.rules[] |
      select(.type=="required_status_checks") | .parameters.required_status_checks[].context'`
      for the ruleset of `main`. Each job of `ci.yml` needs one entry, named as its `name:` key
      reads, or by its job id when it has none. A matrix job needs one entry for each row. A ruleset
      requires a check by its name, so a red job outside the list does not stop the merge. Add each
      missing entry to the ruleset before the merge. Read the matrix rows that a pull request into
      `main` runs. Remove each required context that no job or such row of `ci.yml` produces: no
      run reports it, so the pull request waits for it with no way to bypass. A context of a
      removed matrix row is stale.
    - When the release changes the flag set, a schema number or the score model, the lockrot.dev
      change that reads it is merged. The lockrot.dev build stops on a set that it does not know.
    - Before the first final renderer tag, list the renderer's rulesets with
      `gh api repos/somework/lockrot-report/rulesets`. The list leaves out each ruleset's
      conditions and rules, so read each one with
      `gh api repos/somework/lockrot-report/rulesets/<id>`. Confirm that a tag ruleset on
      `refs/tags/v*` has the `deletion` and `update` rules. If no such ruleset exists, create it,
      with lockrot's tag ruleset as the model. A moved tag gets a second attestation, and
      `update-renderer` then cannot name one source commit for the release.

4. Squash merge the release pull request into `main` with CI green. GitHub refuses a rebase merge
   of more than 100 commits
   ([repository limits](https://docs.github.com/en/repositories/creating-and-managing-repositories/repository-limits)).
   The ruleset of `main` requires a linear history, so a release branch of that size merges only
   as a squash. The repository deletes the head branch when a pull request merges, so the release
   branch does not keep the commit history. Before you merge, tag the branch head with an unused
   `vX.Y.Z-rc.N` and push the tag. Keep `vX.Y.Z` for step 6. After the merge, restore the branch
   from the merged pull request (the "Restore branch" button), and keep it until step 9.
   Write the squash message as a release summary: the subject names the version, and the body
   lists the user-visible changes of the CHANGELOG section. Do not keep the default
   concatenation of every commit message. Remove `## Review notes` from the message. On a pull
   request into `main`, the
   `quality` job also runs `tools/report/check-vendored`, the renderer check of the tag. It also
   runs `tools/release/version_bump.py`. When the pull request changes a file that the release
   ships (`src/`, `resources/`, `bin/lockrot`, `composer.json`), that check fails unless
   `Version::STRING` is the next patch, minor or major of the base. That version also needs its
   dated CHANGELOG heading and its `RELEASED_SCHEMAS` row.
5. When a schema file changes, `.github/workflows/schemas.yml` asks lockrot.dev to publish it.
   Approve the `schemas` environment of the lockrot.dev run, then check that each new schema URL
   answers (`curl -fsI https://lockrot.dev/schema/<document>-<number>.json`).
6. When the `ci.yml` push run of the squash commit on `main` passes, tag the commit `vX.Y.Z` and
   push the tag. If the tag day changed after the merge, tag the commit of the date pull request
   instead, when its `ci.yml` push run passes. `.github/workflows/phar.yml` refuses these tags:

    - a tag that is not on `main`, or whose `ci.yml` push run on `main` did not pass
    - a tag whose vendored renderer is not an attested release
    - a tag whose `-2` schemas lockrot.dev does not serve as the tag holds them
    - a tag whose archive prints another version
    - a tag whose commit day (UTC) differs from the date of the `## [X.Y.Z]` heading of
      `CHANGELOG.md` (`tools/release/tag_day.py`)

    It builds, signs and attests the PHAR, runs it on PHP 7.4, then publishes the GitHub release
    and asks lockrot.dev to rebuild. Its step "The release notes come from the CHANGELOG section of
    the tag" writes the release body with `tools/release/release_notes.py`: the `## [X.Y.Z]`
    section, with each relative link made absolute. An `X.Y.0` release with an upgrade page
    (`docs/changes/X.Y.md`) gets a link to that page first.

    If the release widens a `-2` schema (a value of an open set, an optional field), the tag run
    of `phar.yml` fails at its schema check. lockrot.dev serves the previous tag's file under a
    number that both tags publish, and the `schemas.yml` run cannot replace it. Packagist reads the
    tag at once, so recover before any other step of the release:

    1. Run `gh workflow run deploy.yml --repo somework/lockrot.dev`. The deploy reads the new tag
       and publishes its schemas.
    2. When the deploy passes, re-run the failed `phar.yml` job (`gh run rerun <run id> --failed`).
       It then publishes the release and asks lockrot.dev to rebuild.

    The step that asks lockrot.dev to rebuild is `continue-on-error`, so a failed request leaves
    the release green. Before step 7, check that the site serves what the release notes link to:

    ```bash
    gh run list --repo somework/lockrot.dev --workflow deploy.yml --limit 1
    curl -fsI https://lockrot.dev/changes/X.Y/
    curl -fs https://lockrot.dev/baseline/ | grep -c 'moving-from-a-baseline-1-file'
    ```

    The deploy run must be green and newer than the tag. The first `curl` applies to an `X.Y.0`
    release with an upgrade page. The `grep` must not print `0` when the release refuses a
    baseline-1 file. If a check fails, run `gh workflow run deploy.yml --repo somework/lockrot.dev`
    and check again.
7. When the published release changes what lockrot writes (a schema differs from the previous
   release's), record it and pin the hashes ([Recording a release](#recording-a-release)). Then
   remove the version from `RECORDING_PENDING`. `SchemaEvolutionTest` fails when a release whose
   schemas changed has no recording, unless it is the newest release and waits in
   `RECORDING_PENDING`.
8. After the GitHub release of step 6, release [lockrot-action](https://github.com/somework/lockrot-action)
   when its `@v1` jobs need the new lockrot. For lockrot 0.14.0, the action release is v1.2.0 from
   the branch `feat/one-run-output`. Each merge and the release need the maintainer's approval.

    1. Merge the branch of the action release into the action's `main`, with the release's
       `CHANGELOG.md` section.
    2. Run `gh workflow run update-lockrot.yml --repo somework/lockrot-action`, or wait for its
       daily run. It opens the pull request `chore: lockrot X.Y.Z`, which sets `LOCKROT_VERSION`
       and `LOCKROT_SHA256` in `lockrot.env` from the published PHAR.
    3. Merge that pull request when its CI passes. Without the `UPDATE_TOKEN` secret, its CI does
       not start: close and reopen the pull request to start it.
    4. Run `gh release create v1.x.y --repo somework/lockrot-action --generate-notes` on that
       `main`. The release moves the `v1` tag to its commit, and publishes the Docker image
       `ghcr.io/somework/lockrot` for the lockrot version in `lockrot.env`.
    5. Check that `v1` names the release commit
       (`gh api repos/somework/lockrot-action/git/ref/tags/v1`), and that the `docker.yml` run
       published the image `ghcr.io/somework/lockrot:X.Y.Z`.

9. Start the next release branch from the newest commit on `main`, after every pull request of this
   release merges. The squash commit can precede the date pull request of step 6. The old release
   branch, when you restored it, holds commits that `main` does not, so a second pull
   request from it shows them again and conflicts where later commits change the same lines.

## Related

- [What 1.0 freezes](docs/compatibility.md#what-10-freezes): the full contract a change must keep.
- [What lockrot does and does not do](SECURITY.md#what-lockrot-does-and-does-not-do): the list
  that a change must update when it writes or contacts something new.
- [`tools/corpus/README.md`](tools/corpus/README.md): the corpus checks, their files and exit codes.
- [`tests/fixtures/README.md`](tests/fixtures/README.md): every recorded fixture set and its source.
