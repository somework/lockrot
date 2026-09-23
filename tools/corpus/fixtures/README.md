# Recorded fixtures

Everything here is lockrot's own output and Composer's own metadata, recorded from a real corpus run
and trimmed — never written by hand, never edited afterwards. A fixture that no longer matches the
source it was taken from is worth less than no fixture.

Recorded on **2026-09-23** from lockrot **0.11.0**, over the 39-project corpus pinned in
`../corpus.lock.json`, by:

    tools/corpus/record --work build/corpus --out head --explain-out head --semver-oracle

Total size is 608 KB, against the ~15 MB of HTTP envelopes already committed under
`tests/fixtures/http`, and it earns the same sentence those do: so the checks can be exercised
offline, in CI, with no corpus, no token and no network.

More than a third of that is one file: `provider-laravel~framework.json`, 238 KB and 1,294 versions,
which is what a real monorepo parent costs. It is kept whole rather than trimmed to the versions
this fixture happens to need, because `replaces` is a union over every version entry and the
shared-commit rule counts tags — a parent with a hand-picked version list would answer differently
from the one lockrot reads, which is the one thing a recorded fixture may not do.

## `explain/` — 10 pages and the documents they were rendered from

One pair per rendered shape, so that every catalogued phrase carrying a minimum has a document to
match and every check's selector fires at least once. `index.json` says which shape each covers.

| slug | what it covers |
|---|---|
| `gh_espocrm_espocrm@illuminate_macroable` | a parent dates the installed release |
| `BookStackApp_BookStack@symfony_polyfill-ctype` | the installed release is undated |
| `gh_librenms_librenms@librenms_laravel-vue-i18n-generator` | a branch snapshot |
| `PrestaShop_PrestaShop@greenlion_php-sql-parser` | no metadata block at all |
| `gh_SuiteCRM_SuiteCRM@phootwork_collection` | S10 says the age was not read |
| `BookStackApp_BookStack@aws_aws-crt-php` | libyears measured |
| `BookStackApp_BookStack@symfony_polyfill-php80` | libyears not measured |
| `BookStackApp_BookStack@bacon_bacon-qr-code` | a branch table with several rows |
| `BookStackApp_BookStack@symfony_polyfill-uuid` | the lock date is a commit its tags share |
| `drupal_drupal@drupal_core` | a lock entry with no date at all |
