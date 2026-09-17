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

## Contributing a finished package

`resources/finished-packages.json` is the built-in allowlist of packages that are complete rather
than unmaintained: interface packages, polyfills, metapackages. Open a pull request that adds one
entry with a one-line `reason` saying why the package is finished rather than stalled.

    {"pattern": "psr/*", "reason": "PHP-FIG interface packages are complete by design; versions change only when the interface changes"}

`pattern` accepts `*` wildcards and `version` pins one exact release. A reason like "it is fine"
will be sent back; the reason is what a future maintainer reads when deciding whether the entry
still holds.

## Backward compatibility

The CLI (`composer lockrot` options), the `extra.lockrot` configuration keys, the output formats,
the baseline file and the exit codes are the public interface and follow semantic versioning. The
PHP classes under `src/` are not a public API and may change in any release.

## Commits and pull requests

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/): `feat:`,
`fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`, with the subject in the imperative.

A pull request should say what changed and why, come with tests, update the README when it changes
behaviour a user can see, and add a line to the `Unreleased` section of `CHANGELOG.md`.

## Documentation

`docs/` is the reference and changes with the code it describes: a new option, verdict or format
lands with its page in the same branch. `mkdocs build --strict` (`pip install -r docs/requirements.txt`)
is the linter CI runs on it. The public site, https://lockrot.dev, is built from the
[somework/lockrot.dev](https://github.com/somework/lockrot.dev) repository, which checks this one out
at its newest release tag — so a docs change appears there with the next release. The landing page
and the blog live in that repository, not here.
