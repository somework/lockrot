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
- `big-summary.json`, `phar-summary.json`, `skeletons-summary.json`, `summary.json`,
  `top500.json`, `liveness.py`, `pre8.py` — research artefacts kept for provenance. No test reads
  them.
