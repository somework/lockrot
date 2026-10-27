# Composer's answers on `target_fix`

`answers.tsv` holds the answer of `composer update <packages> --dry-run` for each fixture lock of
the `target_fix` oracle and each form of the update. `p2/` holds the p2 answers that lockrot's
`target_fix` solve of these locks asks. `TargetFixComposerOracleTest` runs that solve on `p2/` and
compares `run.php_window.target_fix` with these answers. The test runs no Composer.

Columns, tab-separated:

1. The fixture lock, as `<group>/<directory>` under `tests/fixtures/`.
2. The target PHP minor X.Y. The run set composer.json's `config.platform.php` to X.Y.99, because
   `composer update` solves at that key.
3. The packages that the update named, in the order of the command.
4. The form: `listed`, `with-dependencies` (`--with-dependencies`) or `with-all-dependencies`
   (`--with-all-dependencies`).
5. The exit code: 0 resolves, 2 refuses.
6. The first problem line of a refusal, cut at 220 characters.

Recorded on 2026-10-27 with Composer 2.10.3 on PHP 8.5.12, on copies of the fixture locks at
4b1a9161. Each copy had its own directory outside the repository, and one run went at a time. The command was
`composer update <packages> [--with-dependencies|--with-all-dependencies] -v --dry-run --no-plugins --no-scripts '--ignore-platform-req=ext-*'`,
with its own `COMPOSER_HOME` and `TMPDIR`. fragility-ok: provenance of a recording

`p2/` was recorded at the same moment with
`bin/record-fixtures --into=tests/fixtures/target-fix-oracle/p2 --before=2026-10-27T03:12:46+00:00 --names=<name,name~dev,…>`. fragility-ok: provenance of a recording
The names are the ones that the solve of each lock asked of packagist.org, and `p2/names.json` lists
them. A partial update loads only the names that it unlocks and their new dependencies, so a lock's
other names keep their locked versions. Packagist answers 404 to the virtual names
`php-http/async-client-implementation`, `php-http/client-implementation`,
`psr/http-client-implementation` and `psr/http-factory-implementation`. The advisory answers are the
ones that `TargetFixComposerOracleTest::ADVISORIES` names. `skeletons/laminas` has none: the root's
own php requirement refuses it.

The refusal of `sites/drupal-site` comes from advisory blocking: Composer does not load the
`laminas/laminas-diactoros` releases that `drupal/core` and `drupal/core-recommended` require. A
Composer without advisory blocking (2.2 to 2.8) gives other holds.
