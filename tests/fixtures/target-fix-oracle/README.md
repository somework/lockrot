# Composer's answers on `target_fix`

`answers.tsv` holds the answer of `composer update <packages> --dry-run` for each fixture lock of
the `target_fix` oracle and each form of the update. `TargetFixComposerOracleTest` compares
`run.php_window.target_fix` with these answers. The test runs no Composer.

Columns, tab-separated:

1. The fixture lock, as `<group>/<directory>` under `tests/fixtures/`.
2. The target PHP minor X.Y. The run set composer.json's `config.platform.php` to X.Y.99, because
   `composer update` solves at that key.
3. The packages that the update named, in the order of the command.
4. The form: `listed`, `with-dependencies` (`--with-dependencies`) or `with-all-dependencies`
   (`--with-all-dependencies`).
5. The exit code: 0 resolves, 2 refuses.
6. The first problem line of a refusal, cut at 220 characters.

Recorded on 2026-10-22 with Composer 2.10.3 on PHP 8.5.11, on copies of the fixture locks at
7f3294a8. Each copy had its own directory outside the repository, and one run went at a time. The command was
`composer update <packages> [--with-dependencies|--with-all-dependencies] -v --dry-run --no-plugins --no-scripts '--ignore-platform-req=ext-*'`,
with its own `COMPOSER_HOME` and `TMPDIR`. fragility-ok: provenance of a recording

The refusal of `sites/drupal-site` comes from advisory blocking: Composer does not load the
`laminas/laminas-diactoros` releases that `drupal/core` and `drupal/core-recommended` require. A
Composer without advisory blocking (2.2 to 2.8) gives other holds.
