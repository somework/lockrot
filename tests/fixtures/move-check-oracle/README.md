# Composer's answers on worlds with advisory blocking

`worlds.json` holds designed worlds that no report case needs, each with the Composer CLI's answer to
every request. `MoveCheckComposerOracleTest` serves each world over HTTP, asks lockrot's move checker
the same requests and compares the answers, as it does for the `solver_world` of each train 7d case
in `flags/cases.json`. A world has the shape of a `solver_world`, with two more keys:

- `config`: keys of composer.json `config`. World `blocking` sets `audit.block-insecure` to true.
- `advisories`: the advisory records of each name. The repository publishes them in the package's
  p2 file, under `security-advisories.metadata: true`.

World `blocking` has three advisories that Composer's `SecurityAdvisoryPoolFilter` must apply:

- The locked `acme/tool` 1.0.0 is affected. The baseline keeps it, because Composer asks no advisory
  of a locked package that the update does not name.
- `acme/dep` 1.1.0 and 1.2.0 are affected. An update that unlocks `acme/dep` installs 1.1.1, and
  `acme/lib` 1.2.0, which requires `acme/dep ^1.2`, has no installable release.

Without blocking, the same requests install `acme/dep` 1.2.0 and resolve `acme/lib ^1.2.0`.

Recorded on 2026-10-29 with Composer 2.10.3 on PHP 8.5.12, with the recorder of the train 7d answers. fragility-ok: provenance of a recording
Each request ran in its own directory outside the repository, with its own `COMPOSER_HOME`,
`TMPDIR` and an empty cache, one run at a time. Each command had
`--dry-run --no-plugins --no-scripts --no-interaction '--ignore-platform-req=ext-*'`.
`config.platform.php` was the world's `platform_php`. The baseline is `composer update lockrot/baseline`.
A move asks `composer update <package>:<constraint>` inline, then with `--with-dependencies`, then
with `--with-all-dependencies`, and stops at the first form that installs the newest release that
the constraint admits. Otherwise the form that installs the highest release stands. A refusal's
holds are the rule lines of the problem in every form and of `composer prohibits <package> <version> --locked`.
