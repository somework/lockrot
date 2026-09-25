# Contributing to lockrot

## Getting set up

    git clone https://github.com/somework/lockrot.git
    cd lockrot
    composer install

Four commands make up the local check suite. CI runs them on every PHP/Composer pair of the matrix and adds three gates on top: a coverage threshold over the core directories (`phpunit.core-coverage.xml.dist`, 94 % of clover elements), an Infection mutation-score threshold over the verdict engine, the signals, the analyzer and the output formats (`infection.json5`, MSI 97), and composer-require-checker (`composer-require-checker.json`):

| Command | What it does |
|---|---|
| `composer test` | PHPUnit. On PHP 7.4 and 8.0 use `vendor/bin/phpunit -c phpunit9.xml.dist`. |
| `composer stan` | PHPStan over `src/`, then over `tests/` with its own config. |
| `composer cs` | php-cs-fixer in `--dry-run --diff` mode. |
| `composer cs-fix` | The same fixer, applying the changes. |

The report page is not built here. It lives in its own repository,
[somework/lockrot-report](https://github.com/somework/lockrot-report), with its own tests in real
browsers; lockrot vendors a release of it as `resources/report/report.html` and `manifest.json`, so
the PHAR is still built without node. To move to a new release:

    tools/report/update-renderer v1.2.3

The script verifies the release's build provenance with `gh attestation verify`, then the page
against the sha256 its manifest states, and `RendererManifestTest` checks the second part on every
run. Do not edit the vendored page by hand: change the renderer, release it, update the pin. What
goes into the page — which packages, which facts — is decided here, in `src/Html/ReportDocument.php`.

## What the code has to run on

Three floors are not negotiable, and CI enforces all three across a 7.4–8.5 × Composer 2.2/latest
matrix.

- **PHP 7.4 is the language floor.** No `match`, no enums, no constructor promotion, no `readonly`,
  no union types in signatures, no `str_contains()` without a guard.
- **Composer 2.2 is the plugin-API floor** (`"composer-plugin-api": "^2.2"`). The tested pair is
  Composer 2.2.25 and 2.10.3, and anything the newer Composer added needs a version guard.
- **Only the symfony/console 2.8 API.** `require-dev` resolves symfony/console 5.4, but Composer 2.2
  LTS bundles 2.8.52, and the plugin runs inside the Composer process against whatever that process
  bundles. Console API added after 2.8 will pass locally and fail on the 2.2 row of the matrix.

## Fixtures are recorded, not written

`tests/fixtures/` holds real `composer.lock` and `composer.json` snapshots from public projects,
plus the Packagist and GitHub HTTP responses that go with them, so the suite runs offline. Regenerate
them with `bin/record-fixtures` (it takes `GITHUB_TOKEN` from the environment); see
`tests/fixtures/README.md` for what each set covers and when it was last recorded. Do not hand-edit a
recorded lock file or a recorded response — a fixture that no longer matches the source it was taken
from is worth less than no fixture.

The README demo (`docs/assets/lockrot-demo.gif`) is recorded the same way, from a real run:
`bin/record-demo` captures it with asciinema and renders it with agg — its header says what to
export first. Re-record it when the table output changes shape, not for every release.

`tests/fixtures/schema-evolution/` holds what earlier releases published and wrote, and
`SchemaEvolutionTest` holds the current schemas to it: a schema change that would reject a document
an older lockrot wrote, or stop listing a field one carries, fails there. Two kinds of fixture:

- `schemas/<version>/` — the four `resources/lockrot-*.schema.json` files as that release's tag holds
  them. A release adds its own: copy them in (`git show v<version>:resources/lockrot-report.schema.json`
  and so on, or from `resources/` in the release PR itself) and pin their sha256 in
  `RELEASED_SCHEMAS`. The test compares every release's changelog heading with this list, so a
  release without its schemas fails.
- `<version>/` — what a release's signed PHAR wrote, recorded by
  `GITHUB_TOKEN=$(gh auth token) bin/record-schema-evolution <version> <today>`, which verifies the
  PHAR first. Pin the new `provenance.json` sha256 and the release asset's digest
  (`gh release view v<version> --json assets`) in the test, with the signals the recording carries.

Both are frozen — the script refuses to record a version again, and the test checks every file
against a pinned hash — so a failure there is a compatibility break to fix in the schema change, not
in the fixture.

## Contributing a finished package

`resources/finished-packages.json` is the built-in allowlist of packages that are complete rather
than unmaintained: interface packages, polyfills, metapackages. Open a pull request that adds one
entry with a one-line `reason` saying why the package is finished rather than stalled.

    {"pattern": "psr/*", "reason": "PHP-FIG interface packages are complete by design; versions change only when the interface changes"}

`pattern` accepts `*` wildcards and `version` pins one exact release. A reason like "it is fine"
will be sent back; the reason is what a future maintainer reads when deciding whether the entry
still holds.

## Backward compatibility

The public interface follows semantic versioning. It is:

- the CLI (`composer lockrot` options) and its exit codes;
- the `extra.lockrot` configuration keys;
- the machine-readable output formats (`json`, `sarif`, `gitlab`, `github`);
- the baseline file.

`table`, `markdown` and `html` are for people and may change in any release. The PHP classes under
`src/` are not a public API and may change in any release too: every class, interface, trait and
enum there is marked `@internal`, and `tests/Unit/PublicApiTest.php` fails on one that is not.
PHPStan reports a use of an `@internal` class from code outside its root namespace, `Lockrot\`;
code declared under `Lockrot\` itself, `Lockrot\Extension\` included, gets no warning. IDEs flag
such uses too, by rules of their own.

The namespace `Lockrot\Extension\` is reserved. Nothing is declared in it, and the same test fails
on a class that is, or on a `src/Extension/` directory, in any letter case: PHP matches namespaces
without regard to case. The reservation keeps the name free; it is not a promise that anything will
be published there, or in what form.

What 1.0 will freeze, and what it will not, is drafted in
[`docs/compatibility.md`](docs/compatibility.md): the closed sets of verdicts and priorities and
their order, finding identity, the severity mapping, the names reserved for extensions, and the
deprecation policy.

From 0.13 on, a change to the verdict or the priority lockrot gives a package goes into a minor
release, never a patch, and gets a line under `### Verdict changes` in `CHANGELOG.md`. The one
exception is a curated-data fix that only removes a false verdict, which may ship in a patch.

## What lockrot writes

lockrot writes the files you name — reports with `--output`, the baseline with `--generate-baseline`
— each through a temporary file beside it that is renamed over it, so it needs write access to that
directory; its activity cache, under Composer's cache directory; and, with `self-update`, the PHAR.
It never writes `composer.json` or `composer.lock`. An absolute path is written where it points, in
the project or not, and a run interrupted mid-write can leave a `*.tmp` file beside the target.

That paragraph is a promise the README, `SECURITY.md` and the docs make, so a change that writes
anything else — a new file, a created directory, a cache in another place — is a change to the
promise and needs the maintainer's decision first, not only a review. Every project file goes
through `Lockrot\Filesystem\AtomicWriter`.

## Commits and pull requests

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/): `feat:`,
`fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`, with the subject in the imperative.

A pull request should say what changed and why, come with tests, update the README when it changes
behaviour a user can see, and add a line to the `Unreleased` section of `CHANGELOG.md`.

## The PHAR

`build/build-phar.sh` builds `build/lockrot.phar` reproducibly; the comment at its top lists what
it pins. The archive's dependencies are locked in `build/phar/composer.lock`, which is committed:
when a dependency of the archive changes — a new requirement in `composer.json`, or a bump of
`composer/composer` for the PHAR — run `composer update` in `build/phar/` and commit the lock with
the change. CI builds the archive on two machines and fails when the bytes differ.

## The corpus

lockrot's suite, its mutation gate and its schemas all share one blind spot: they prove lockrot
agrees with itself. `tools/corpus/` is the second reading — it takes a finished run and audits every
claim against the Packagist data that run read, derived again by code that never touches lockrot's,
and reads every `--explain` page against the JSON it was rendered from. Both of those caught a real
bug the day they were written, and neither could have been written as a unit test, because a unit
test encodes the same assumption the code does.

It needs `GITHUB_TOKEN`, the network and a couple of hours over 39 real projects:

    tools/corpus/corpus fetch
    tools/corpus/corpus run     --phar build/lockrot.phar --out head --today 2026-09-23
    tools/corpus/corpus explain --phar build/lockrot.phar --out head --today 2026-09-23
    tools/corpus/corpus check   --out head --explain-out head

Exit 0 means every check ran and found nothing; 1 means it found something; 2 means it cannot tell
you either way — a check that selected far less than it was measured to, a corrupt cached document,
an empty tree. `--today` pins every "years ago", without which the same lock crosses a threshold
between two runs and the calendar gets filed as a code change. `corpus diff base head` compares two
archives and refuses two runs whose day, token mode or cache differ.

Run it before a release that changes the date-trust layer, the signal rules, or any sentence lockrot
prints — not for every release.

The offline half runs on every pull request and on every push to main, and needs none of that:
`tools/corpus/corpus selftest`, under a second, which is what proves the checks have not quietly
stopped checking — not that lockrot's wording has not moved, which only a re-record or a corpus run
can say. The tool is stdlib-only
Python on the version in `.python-version`, with nothing to install — the same arrangement as the
node checks above — and it does not inherit lockrot's PHP 7.4 floor. Nothing under `tools/corpus/`
may import or shell out to lockrot's PHP to decide what an answer should be; a checker that asks the
subject what it is about agrees with it by construction. `tools/corpus/README.md` says why that
matters and what each file does.

The third check of the same family is an ordinary unit test and needs no corpus:
`tests/Unit/Signal/Rule/NotCheckedReachabilityTest.php` asks each rule whether a signal S10 names as
blocked could have fired at all.

## Documentation

`docs/` is the reference and changes with the code it describes: a new option, verdict or format
lands with its page in the same branch. `mkdocs build --strict` (`pip install -r docs/requirements.txt`)
is the linter CI runs on it. The public site, https://lockrot.dev, is built from the
[somework/lockrot.dev](https://github.com/somework/lockrot.dev) repository, which checks this one out
at its newest release tag — so a docs change appears there with the next release. The landing page
and the blog live in that repository, not here.
