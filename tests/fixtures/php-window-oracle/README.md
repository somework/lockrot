# Composer's answers on the PHP window

`results.tsv` holds one answer of `composer install --dry-run` per fixture lock, PHP minor and
set. `PhpWindowComposerOracleTest` compares the `minors` of `run.php_window` with these answers.
The test runs no Composer.

Columns, tab-separated:

1. The fixture lock, as `<group>__<directory>` under `tests/fixtures/`:
   `apps__BookStackApp_BookStack` is `tests/fixtures/apps/BookStackApp_BookStack`.
2. The PHP minor X.Y. The run set the lock's `platform-overrides.php` to X.Y.99, because
   `composer install` reads that key and not composer.json's `config.platform`.
3. The set: `all` is `with_dev`, and `no_dev` is `without_dev` (`--no-dev`).
4. The exit code: 0 installs, 2 refuses.
5. The first problem line of a refusal, cut at 220 characters.

A minor is in a set's window exactly when its exit code is 0.

Recorded on 2026-10-21 with Composer 2.10.3 on PHP 8.5.11, on copies of the fixture locks at
c84d8b77. Each copy had its own directory outside the repository, and one run went at a time. The command was
`composer install --dry-run --no-plugins --no-scripts '--ignore-platform-req=ext-*'`, with and
without `--no-dev`, and its own `COMPOSER_HOME` and `TMPDIR`. fragility-ok: provenance of a recording
