# Fixtures

Real composer.lock/composer.json snapshots used in the research (14.09.2026), fetched from default branches via GitHub API.
Copy them here with recorded Packagist/GitHub responses so tests run offline:
Monica, Firefly III, Matomo, BookStack, Koel, Snipe-IT, wallabag, Mautic, PrestaShop, Magento 2 (2.4-develop), nextcloud/3rdparty, drupal/drupal; framework skeletons (laravel/laravel, symfony/skeleton+webapp-pack, drupal/recommended-project, shopware/production, sylius-standard, laminas-mvc-skeleton, cakephp/app, yii2-app-basic, roots/bedrock).
Expected results: see docs/RESEARCH.md.

## HTTP fixtures (`http/p2`, `http/github`)

Recorded 2026-09-14 with `GITHUB_TOKEN=$(gh auth token) bin/record-fixtures` against the five
default acceptance fixtures (wallabag/wallabag, nextcloud/3rdparty, matomo-org/matomo,
laravel/laravel, BookStackApp/BookStack). Packagist p2 bodies are trimmed to the keys lockrot
reads (`name`, `version`, `version_normalized`, `time`, `abandoned`, `source`, `require`, `type`)
and GitHub repo bodies are trimmed to `full_name`, `archived`, `disabled`, `pushed_at`,
`default_branch`; `security-advisories` is stripped from p2 responses. Re-run the recorder to add
more fixture directories: `GITHUB_TOKEN=$(gh auth token) bin/record-fixtures [fixtureDir ...]` —
it skips files that are already recorded, so it only fetches what is missing.

Packagist/GitHub data moves over time. If an acceptance assertion in
`tests/Integration/AcceptanceTest.php` needs updating after a re-recording, diff the affected
package's signals/verdict before changing the expected number, and document the delta with a code
comment rather than editing the recorded fixture files by hand.
