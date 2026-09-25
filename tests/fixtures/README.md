# Fixtures

Real composer.lock/composer.json snapshots fetched from default branches via the GitHub API.
Copy them here with recorded Packagist/GitHub responses so tests run offline:
Monica, Firefly III, Matomo, BookStack, Koel, Snipe-IT, wallabag, Mautic, PrestaShop, Magento 2 (2.4-develop), nextcloud/3rdparty, drupal/drupal; framework skeletons (laravel/laravel, symfony/skeleton+webapp-pack, drupal/recommended-project, shopware/production, sylius-standard, laminas-mvc-skeleton, cakephp/app, yii2-app-basic, roots/bedrock).

## HTTP fixtures (`http/p2`, `http/github`)

Both sets were recorded with `GITHUB_TOKEN=$(gh auth token) bin/record-fixtures` against the five
default acceptance fixtures
(wallabag/wallabag, nextcloud/3rdparty, matomo-org/matomo, laravel/laravel,
BookStackApp/BookStack). Packagist p2 bodies are trimmed to the keys lockrot reads (`name`,
`version`, `version_normalized`, `time`, `abandoned`, `source`, `require`, `type`, `extra`) and
GitHub repo bodies are trimmed to `full_name`, `archived`, `disabled`, `pushed_at`,
`default_branch`; `security-advisories` is stripped from p2 responses. Both `{name}.json` and
`{name}~dev.json` are recorded for every package name in the fixture lock files, including 404s
and empty bodies (the status is stored in the envelope), so the fixture server can answer either
file for any name without falling through to the real Packagist endpoint. `extra` is kept
specifically so `extra.branch-alias` reaches the fixture server, since Composer's
`ComposerRepository` unwraps `AliasPackage` entries from it.

Re-run the recorder to add more fixture directories or fill in gaps:
`GITHUB_TOKEN=$(gh auth token) bin/record-fixtures [fixtureDir ...]` — it reuses any envelope
already on disk whose recorded `status` is not `0`; a `status: 0` envelope means a previous
recording run hit a transport failure (timeout, DNS, ...) and is fetched again rather than treated
as recorded.

Packagist/GitHub data moves over time. If an acceptance assertion in
`tests/Integration/AcceptanceTest.php` needs updating after a re-recording, diff the affected
package's signals/verdict before changing the expected number, and document the delta with a code
comment rather than editing the recorded fixture files by hand.

## Other fixtures

- `mini/` — a hand-written 4-package lock and its composer.json, shared by `LockFileTest`,
  `DependencyGraphTest`, `ProjectConfigTest` and `AnalyzerTest`.
- `baseline/` — valid and deliberately invalid baseline documents for `BaselineFileTest`.
- `sarif/` — the pinned SARIF 2.1.0 schema the SARIF output is validated against.
- `phar/` — a minimal PHP archive for the self-update tests; see its own README.
- `http/github-releases/` — three recorded `releases/latest` bodies for the self-update tests.
- `schema-evolution/` — what earlier releases published and wrote, for `SchemaEvolutionTest`.
  `schemas/<version>/` holds the report, explain, baseline and config schemas of each release from
  0.9.0 to 0.12.0, taken from its tag (the same content as the verified release PHAR carries,
  compacted there); the test pins their sha256. `<version>/` holds, per recorded release, the
  baseline file `--generate-baseline` wrote (`lockrot-schema-evolution-baseline.json`), the
  `--format=json` report compared against it and six `--explain … --format=json` documents,
  recorded on 2026-09-25 from the signed release PHARs of 0.9.0, 0.10.0 and 0.11.0 over a copy of
  `apps/wallabag_wallabag`, with network and a GitHub token, `--target-php=8.4` and
  `LOCKROT_TODAY=2026-09-25T00:00:00+00:00`, by
  `GITHUB_TOKEN=$(gh auth token) bin/record-schema-evolution <version> 2026-09-25`. The baseline ran
  on a Composer cache of its own and the report on an empty one, so the report is a first run's
  (`activity_cache_oldest_at: null`); the explanations read the cache the report filled
  (`from_cache: true`). Each version's `provenance.json` names the release, the PHAR's sha256, the
  PHP that ran it (8.5.10), the arguments of every run and each file's sha256; the test pins the
  sha256 of provenance.json and the release asset's digest, and checks the file hashes. The reports
  carry S1–S9 (0.9.0, 0.10.0) and S1–S10 (0.11.0), and the explanations between them the same;
  0.9.0's report has no `run` and no per-finding `baseline`, which the schema keeps optional for it.
  About 1.1 MB in all. A version is added, never re-recorded or edited: a document here that stops
  validating against the current schemas is a compatibility break to raise, not a fixture to
  refresh.
- `big-summary.json`, `phar-summary.json`, `skeletons-summary.json`, `summary.json`,
  `top500.json`, `liveness.py`, `pre8.py` — research artefacts kept for provenance. No test reads
  them.
