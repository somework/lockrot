# Contributing to lockrot

Build lockrot, run the checks CI runs, and change it without breaking a promise it makes to users.
What semantic versioning covers is in [What 1.0 freezes](docs/compatibility.md#what-10-freezes), and
your duties when a change touches it are under [Backward compatibility](#backward-compatibility).
How to write docs and changelog entries is in [the rule files](#writing-the-changelog-and-the-docs).

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
| `composer cs` | php-cs-fixer, dry run with a diff (`.php-cs-fixer.dist.php`); `composer cs-fix` applies the fixes |
| `LOCKROT_E2E=1 GITHUB_TOKEN=... vendor/bin/phpunit --group e2e --filter PluginTest` | The plugin inside a real Composer; needs the network |
| `build/build-phar.sh && LOCKROT_E2E=1 vendor/bin/phpunit --group e2e --filter PharTest` | The built PHAR; skipped when `build/lockrot.phar` is missing |
| `GITHUB_TOKEN=... vendor/bin/phpunit --group network` | The tests that reach the real network |

On PHP 7.4 and 8.0, call `vendor/bin/phpunit -c phpunit9.xml.dist` in place of `composer test`
and add `-c phpunit9.xml.dist` to the other PHPUnit rows.

CI runs each check above (the PHPUnit suite on every PHP and Composer pair of the `tests` matrix
in `.github/workflows/ci.yml`) and these gates:

| Gate | Configured in | Run it locally |
|---|---|---|
| Core coverage | Directories: `phpunit.core-coverage.xml.dist`. Floor: the "Core coverage gate" step of `ci.yml` | With pcov or Xdebug: `vendor/bin/phpunit -c phpunit.core-coverage.xml.dist --coverage-clover build/clover-core.xml`, `composer global require rregeer/phpunit-coverage-check`, then `$(composer global config bin-dir --absolute)/coverage-check build/clover-core.xml <floor>` |
| Mutation score (Infection) | Shards and their `min_msi`: the `mutation` job of `ci.yml`. Whole-tree floor for a local run: `infection.json5` | On the PHP the `mutation` job uses, with pcov or Xdebug: `composer global config --no-plugins allow-plugins.infection/extension-installer true`, `composer global require infection/infection:<constraint in the mutation job>`, then `$(composer global config bin-dir --absolute)/infection --threads=max` |
| Undeclared dependencies | `composer-require-checker.json` | `composer global require maglnet/composer-require-checker`, then `$(composer global config bin-dir --absolute)/composer-require-checker check --config-file=composer-require-checker.json composer.json` |
| Docs build | `mkdocs.yml` | `pip install -r docs/requirements.txt`, then `mkdocs build --strict` |
| Corpus self-test | `tools/corpus/tests/` | `tools/corpus/corpus selftest` |
| PHAR build and byte-identical rebuild | `build/build-phar.sh` | `build/build-phar.sh` ([The PHAR](#the-phar)) |

An escaped mutant that no test can kill is documented, with the reason, in
`tests/infection-equivalents.md`.

## What the code has to run on

| Floor | Declared in | What it rules out |
|---|---|---|
| PHP 7.4 | `composer.json` `require.php`; `phpstan.neon.dist` `phpVersion` | PHP 8 syntax: `match`, enums, constructor promotion, `readonly`, union types in signatures, the nullsafe operator, named arguments. PHP 8 functions such as `str_contains()` without a guard |
| Composer plugin API 2.2 | `composer.json` `"composer-plugin-api": "^2.2"` | Composer API added after 2.2 without a version guard |
| symfony/console 2.8 API | The Composer 2.2 LTS binary, which bundles console 2.8 | Console API added after 2.8. `require-dev` resolves a newer console, so such a call passes locally and fails only in the plugin e2e step on the Composer 2.2 rows of CI |

PHPUnit 9 runs the 7.4 and 8.0 rows, so a test declares each data provider twice: `@dataProvider`
in the docblock and `#[DataProvider]` as an attribute.

## Commits and pull requests

- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/): `feat:`,
  `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`, with the subject in the
  imperative.
- A pull request says what changed and why, and comes with tests.
- A change a user can see updates the docs section that is its topic's home, in the same branch,
  and adds or rewrites its entry under `## [Unreleased]` in `CHANGELOG.md`
  ([rules](#writing-the-changelog-and-the-docs)).

## Documentation

The public site, <https://lockrot.dev>, is built from `docs/` at the newest release tag, so a docs
change appears there with the next release. The landing page and the blog are in
[somework/lockrot.dev](https://github.com/somework/lockrot.dev), not here.

### Writing the changelog and the docs

A change to the docs or the changelog is reviewed against these files.

| File | Covers |
|---|---|
| [`.claude/rules/docs/writing.md`](.claude/rules/docs/writing.md) | `README.md`, `CONTRIBUTING.md`, `SECURITY.md` and `docs/*.md`: the reader of each page, one page one mode, concision, terms, wording that stays true |
| [`.claude/rules/docs/topic-homes.md`](.claude/rules/docs/topic-homes.md) | The one page that holds each recurring topic, and what the other pages say about it |
| [`.claude/rules/docs/contracts.md`](.claude/rules/docs/contracts.md) | Anchors, links, list nesting, validated JSON samples, text that tests read, and the commands that check them |
| [`.claude/rules/changelog.md`](.claude/rules/changelog.md) | `CHANGELOG.md`: the release structure, the shape of an entry, what goes in which section, and what never goes in |

`composer test` includes the tests that read doc text and validate the JSON samples in `docs/`;
`mkdocs build --strict` checks the links and anchors in `docs/` and in `CHANGELOG.md`, which the
site includes. Links in `README.md`, `CONTRIBUTING.md` and `SECURITY.md` are not built, so check
them by hand.

## Backward compatibility

Check a change against [What 1.0 freezes](docs/compatibility.md#what-10-freezes) and
[What is not contract](docs/compatibility.md#what-is-not-contract) before review.

The PHP classes under `src/` are not a public API and may change in any release. Every class,
interface, trait and enum there is marked `@internal`, and `tests/Unit/PublicApiTest.php` fails on
one that is not.

The namespace `Lockrot\Extension\` is reserved. Nothing is declared in it, and `PublicApiTest`
fails on a class that is, or on a `src/Extension/` directory, in any letter case: PHP matches
namespaces without regard to case. The other reserved names are in
[Names reserved for extensions](docs/compatibility.md#names-reserved-for-extensions).

Your duties when a change touches the contract:

- A change that can alter the verdict or the priority a package gets, or what
  `--fail-on=unchecked` matches, ships in a minor release and gets an entry under
  `### Verdict changes`. A new signal that decides verdicts ships for one minor release as
  evidence only. The policy is in [Verdict changes](docs/compatibility.md#verdict-changes).
- A deprecation follows [Deprecation](docs/compatibility.md#deprecation).

## What lockrot writes

The canonical list of what lockrot reads, writes and contacts is
[What lockrot does and does not do](SECURITY.md#what-lockrot-does-and-does-not-do).

- A change that writes anything the list does not name (a new file, a created directory, a cache
  in another place) changes that promise. It needs the maintainer's decision before review, and it
  updates `SECURITY.md` in the same branch.
- Every file lockrot writes into a project goes through `Lockrot\Filesystem\AtomicWriter`.

## Fixtures are recorded, not written

- `tests/fixtures/` holds `composer.lock` and `composer.json` snapshots of public projects, with the
  Packagist and GitHub responses that go with them, so the suite runs offline.
  `tests/fixtures/README.md` lists every set.
- Record responses with `GITHUB_TOKEN=$(gh auth token) bin/record-fixtures [fixtureDir ...]`.
  Without arguments it records the default acceptance fixtures.
- Never hand-edit a recorded lock or response: take a new snapshot of the lock from its project,
  and re-record the responses. When a re-recording moves an acceptance assertion, compare that
  package's signals and verdict before changing the expected value.
- The README demo, `docs/assets/lockrot-demo.gif`, comes from a real run by `bin/record-demo`
  (asciinema and agg; its header lists what to export). Re-record it when the table output changes
  shape.

## Changing a schema

The published schemas are `resources/lockrot-<document>-<number>.schema.json`, one file per
document and schema number. Under one schema number they only widen:
`tests/Integration/SchemaEvolutionTest.php` holds them to every release's copy in
`tests/fixtures/schema-evolution/schemas/<version>/`. It also holds them to the documents older
release PHARs wrote, in `tests/fixtures/schema-evolution/<version>/`. Both fixture sets are
frozen by pinned hashes, so a failure there is fixed in the schema change, never in the fixture.

| Change | Also required |
|---|---|
| A new value in an open set | Add it to that node's `x-known-values`, never to an `enum`. `tests/Unit/Verdict/ClosedSetsTest.php` holds each list to the code |
| A new open set | Register its node, pattern, values and doc phrase in `openSets()` in `ClosedSetsTest`, the one registry of open sets. Name it in the list after "Objects are open" in `docs/schema.md` "Open sets", the one list `ClosedSetsTest` reads |
| A new signal | [Adding a signal](#adding-a-signal) |
| A new run note code | [Adding a run note code](#adding-a-run-note-code) |
| A new value in a closed set | Not possible under the same schema number ([Closed sets](docs/compatibility.md#closed-sets-and-their-order)) |

The widening check reads `x-known-values` as an enum on both sides: a value added there is a
widening, a value dropped is a narrowing.

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

`composer test` fails until steps 1 to 5 agree; nothing checks steps 6 and 7.

### Adding a run note code

1. Add the code to `RunNote::CODES`.
2. In the report and explain schemas, add the code to `x-known-values` of `definitions.noteCode`.
3. In the report and explain schemas, add a `definitions.note<Name>` for its data, and a branch
   that references it in `definitions.noteDetail`, before the last branch.
4. In the report and explain schemas, add the code to the `not` list of that last branch.
5. Write a `docs/notes.md` section whose heading id is the code and which says whether the note
   sets `sets_network_failures`, and add its row to the table at the top of that page.
6. Append its URL to `PUBLISHED_NOTE_URLS` in `tests/Integration/NotesPageTest.php`.
7. Add the code to the list in `RunNoteTest`, and a sample note to the one-of-each list in
   `JsonSchemaConformanceTest`.

`composer test` fails until these agree.

### Recording a release

To record what a published release writes:

```bash
GITHUB_TOKEN=$(gh auth token) bin/record-schema-evolution <version> <YYYY-MM-DD>
```

The script verifies the release PHAR before it runs it and refuses a version already recorded.
Then add the version to every per-version constant in `SchemaEvolutionTest`; each constant's
docblock says what it holds and where its value comes from.

## Curated data

| File | What it decides | How to change it |
|---|---|---|
| `resources/finished-packages.json` | The built-in allowlist of packages that are complete rather than unmaintained: interface packages, polyfills, metapackages | A pull request adds one entry ([below](#contributing-a-finished-package)) |
| `resources/monorepo-parents.json` | The monorepos that can date their split packages' releases, and the packages each replaces. lockrot requests a listed monorepo only when the lock needs dates for one of its packages and does not hold the monorepo ([Dates from the monorepo](docs/verdicts.md#dates-from-the-monorepo)) | `bin/refresh-monorepo-parents [parent ...]`: with no arguments it refreshes the listed parents; a parent named on the command line is added |
| `resources/php-ga-dates.json` | The release date of each PHP minor version | Edited by hand |

A change to any of these files is a [verdict change](docs/compatibility.md#verdict-changes); a
fix that only moves packages to `finished` or `ok` may ship in a patch.

### Contributing a finished package

```json
{"pattern": "psr/*", "reason": "PHP-FIG interface packages are complete by design; versions change only when the interface changes"}
```

| Field | Meaning |
|---|---|
| `pattern` | Package name; `*` is a wildcard |
| `version` | Optional; pins one exact release |
| `reason` | One line on why the package is finished rather than stalled; "it is fine" is sent back |

## The PHAR

`build/build-phar.sh` builds `build/lockrot.phar` reproducibly; the comment at its top lists what it
pins. It needs the network to fetch Box, which it checks against a pinned sha256. CI rebuilds the
archive on a second PHP version (`phar-reproducible` in `ci.yml`) and fails when the bytes differ.

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
PATH=/tmp/composer-pin:$PATH build/build-phar.sh       # fetches the Box version it pins and checks its sha256
sha256sum build/lockrot.phar                           # compare with the release's lockrot.phar.sha256
```

`<version>` is the Composer version the release was built with: the `composer:<version>` that
`.github/workflows/phar.yml` pins at that tag, in its `tools:` entry. Your global Composer stays as
it is.

## The HTML report page

The page `--format=html` writes is built in
[somework/lockrot-report](https://github.com/somework/lockrot-report). lockrot vendors one release
of it as `resources/report/report.html` and `manifest.json`, so the PHAR builds without node.

```bash
tools/report/update-renderer vX.Y.Z
```

- The script needs `gh`. It verifies the release's build provenance with `gh attestation verify`,
  then the page against the sha256 its manifest states, then that the manifest names the requested
  version. `tests/Unit/Output/RendererManifestTest.php` rechecks the page against the manifest on
  every run.
- `--from-dir DIR` vendors a local build for development. It is not attested; never release with it.
- Do not edit the vendored page by hand: change the renderer, release it, update the pin.
- A bump gets one `html` entry under `### Changed` (`.claude/rules/changelog.md`, "The renderer
  entry").
- Which packages and facts go into the page is decided in lockrot, in `src/Html/ReportDocument.php`.

## The corpus

`tools/corpus/` audits a finished run against the Packagist data that run read, re-derived by code
independent of lockrot's, and reads every `--explain` page against the JSON it was rendered from.
It catches what the suite cannot: an assumption the tests share with the code.

Run it before a release that changes how release dates are chosen or trusted (`src/Data/`), the
signal rules (`src/Signal/`) or any sentence lockrot prints. A full run needs `GITHUB_TOKEN` and
the network, and takes hours:

```bash
tools/corpus/corpus fetch
tools/corpus/corpus run     --phar build/lockrot.phar --out head --today <YYYY-MM-DD>
tools/corpus/corpus explain --phar build/lockrot.phar --out head --today <YYYY-MM-DD>
tools/corpus/corpus check   --out head --explain-out head
```

- `--today` pins every age, so a threshold crossed by the calendar does not read as a code change.
- `tools/corpus/corpus diff base head` compares two runs; it refuses runs whose day, token mode or
  cache differ.
- The tool is stdlib-only Python on the version in `.python-version`, with nothing to install. It
  does not inherit lockrot's PHP floor.
- Nothing under `tools/corpus/` may import or shell out to lockrot's PHP to decide what an answer
  should be: a checker that asks its subject agrees with it by construction.

Exit codes, the checks and the files are in `tools/corpus/README.md`.

## Cutting a release

1. On a release branch:

    - set `Lockrot\Version::STRING` in `src/Version.php` to the new version;

    - rename `## [Unreleased]` in `CHANGELOG.md` to `## [X.Y.Z] - YYYY-MM-DD`, dated on the tag
      day, and write its summary (`.claude/rules/changelog.md`). Then open a new empty
      `## [Unreleased]` above it and update the compare links at the bottom of the file: the
      `[Unreleased]` link to `vX.Y.Z...HEAD`, and a new `[X.Y.Z]` link;

    - copy every `resources/lockrot-*.schema.json` file to
      `tests/fixtures/schema-evolution/schemas/X.Y.Z/` and pin their sha256 in `RELEASED_SCHEMAS`
      in `SchemaEvolutionTest`. `SchemaEvolutionTest` fails unless the releases `CHANGELOG.md`
      lists, the keys of `RELEASED_SCHEMAS` and the directories under `schemas/` match exactly.

2. Build the PHAR and check that `php build/lockrot.phar --version` prints the new version.
3. Merge the release pull request into `main` with CI green.
4. Tag the merge commit `vX.Y.Z` and push the tag. `.github/workflows/phar.yml` builds, signs and
   attests the PHAR, publishes the GitHub release and asks lockrot.dev to rebuild.
5. When the published release changes what lockrot writes (a schema differs from the previous
   release's), record it and pin the hashes ([Recording a release](#recording-a-release)).

## Related

- [What 1.0 freezes](docs/compatibility.md#what-10-freezes): the full contract a change must keep.
- [What lockrot does and does not do](SECURITY.md#what-lockrot-does-and-does-not-do): the list a
  change that writes or contacts something new has to update.
- [`tools/corpus/README.md`](tools/corpus/README.md): the corpus checks, their files and exit codes.
- [`tests/fixtures/README.md`](tests/fixtures/README.md): every recorded fixture set and its source.
- [Writing the changelog and the docs](#writing-the-changelog-and-the-docs): the rule files a docs
  or changelog change is reviewed against.
