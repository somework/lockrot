---
title: lockrot example run — a 200-package composer.lock report
description: "One complete lockrot run against wallabag's public 200-package composer.lock: the table output, the same run as JSON, and what a clean run looks like."
---

# Example run

This is what one run prints, start to finish. To produce your own:

```bash
php lockrot.phar -d path/to/project --target-php=8.4
```

The output below comes from `tests/fixtures/apps/wallabag_wallabag`, a real, public 200-package
`composer.lock` carried in this repository for integration tests. wallabag is used because its lock
file is public and large, not to single it out. The run had `GITHUB_TOKEN` set, so repository
activity was checked for every candidate package, and `COLUMNS` was fixed at 120 so the wrapping is
reproducible.

## The table format

Findings are grouped by [priority](verdicts.md), highest first, and every line — rows and the closing
summary alike — wraps to the width of the terminal, so nothing has to be read sideways; a command the
footer names (`composer audit`, `composer lockrot --format=json`) is never split across two lines. The width
comes from `COLUMNS` when it is set, otherwise from the console itself, falling back to 120 columns
and never going below 40. `--all` adds a final `not flagged` group with everything else in the lock.

A transitive row names the direct requirement its shortest chain starts from, then — after
`also via` — the other direct requirements that reach it too, so `hoa/ruler` is shown to stay
installed as long as either `wallabag/rulerz` or `wallabag/rulerz-bundle` does. A direct
requirement that pulls flagged packages in says so in its evidence (`pulls in 15 flagged packages:
…`), and the `pulled in by:` line in the summary sums that up per direct requirement — see
[verdicts.md](verdicts.md#transitive-exposure).

"Data as of" in the footer is the date the report was generated, in UTC. Repository metadata is
revalidated on every run; repository activity comes from lockrot's 24-hour cache when it is fresh
enough, and when any of it did the footer says so and how old the oldest cached answer is —
`(package repositories; repository activity from lockrot's cache, up to 23 h old)` — with the
timestamp itself under `activity_cache_oldest_at` in `--format=json`. On a real terminal the
`critical` and `high` rows are marked in red and the `medium` rows in yellow.

```text
critical (3)
  abandoned    sensio/framework-extra-bundle v6.2.10  direct
               marked abandoned by its repository, replacement: Symfony; repository archived on GitHub; last release
               2023-02-24 (3.6 years ago); last push 2023-02-24 (3.6 years ago); released 2023-02-24, before PHP 8.4 GA
               (2024-11-21); php constraint ">=7.2.5" has no upper bound; pulls in 1 flagged package:
               doctrine/annotations (abandoned)
  silent       javibravo/simpleue 2.1.0  direct
               last release 2017-11-15 (8.8 years ago); last push 2017-11-18 (8.8 years ago); released 2017-11-15,
               before PHP 8.4 GA (2024-11-21); php constraint ">=5.5" has no upper bound
  silent       mnapoli/piwik-twig-extension 3.0.0  direct
               last release 2020-04-24 (6.4 years ago); last push 2020-04-28 (6.4 years ago); released 2020-04-24,
               before PHP 8.4 GA (2024-11-21); php constraint ">=7.0" has no upper bound

high (64)
  abandoned    behat/transliterator v1.5.0  via stof/doctrine-extensions-bundle › gedmo/doctrine-extensions
               marked abandoned by its repository; repository archived on GitHub; last release 2022-03-30 (4.5 years
               ago); released 2022-03-30, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2" has no upper bound
  abandoned    doctrine/annotations 2.0.2  via sensio/framework-extra-bundle
               marked abandoned by its repository
  abandoned    doctrine/cache 2.2.0  via doctrine/doctrine-bundle, also via craue/config-bundle,
               doctrine/doctrine-migrations-bundle, doctrine/orm and 2 more
               marked abandoned by its repository; last release 2022-05-20 (4.3 years ago)
  abandoned    hoa/compiler 3.17.08.08  via wallabag/rulerz › hoa/ruler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-08 (9.1 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/consistency 1.17.05.02  via wallabag/rulerz, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-29 (9.0 years
               ago); last push 2021-04-28 (5.4 years ago); released 2017-05-02, before PHP 8.4 GA (2024-11-21); php
               constraint ">=5.5.0" has no upper bound
  abandoned    hoa/event 1.17.01.13  via wallabag/rulerz › hoa/consistency › hoa/exception, also via
               wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-30 (9.0 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/exception 1.17.01.16  via wallabag/rulerz › hoa/consistency, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-30 (9.0 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/file 1.17.07.11  via wallabag/rulerz › hoa/ruler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-07-11 (9.2 years
               ago); last push 2018-01-23 (8.6 years ago)
  abandoned    hoa/iterator 2.17.01.10  via wallabag/rulerz › hoa/ruler › hoa/compiler, also via
               wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-10 (9.7 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/math 1.17.05.16  via wallabag/rulerz › hoa/ruler › hoa/compiler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-05-16 (9.3 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/protocol 1.17.01.14  via wallabag/rulerz › hoa/ruler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-14 (9.7 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/regex 1.17.01.13  via wallabag/rulerz › hoa/ruler › hoa/compiler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-13 (9.7 years
               ago); last push 2018-07-20 (8.2 years ago)
  abandoned    hoa/ruler 2.17.05.16  via wallabag/rulerz, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-05-16 (9.3 years
               ago); last push 2021-07-10 (5.2 years ago)
  abandoned    hoa/stream 1.17.02.21  via wallabag/rulerz › hoa/ruler › hoa/file, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-02-21 (9.6 years
               ago); last push 2021-08-09 (5.1 years ago)
  abandoned    hoa/ustring 4.17.01.16  via wallabag/rulerz › hoa/ruler › hoa/compiler › hoa/regex, also via
               wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-16 (9.7 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/visitor 2.17.01.16  via wallabag/rulerz › hoa/ruler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-16 (9.7 years
               ago); last push 2021-04-28 (5.4 years ago)
  abandoned    hoa/zformat 1.17.01.10  via wallabag/rulerz › hoa/ruler › hoa/compiler › hoa/math, also via
               wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-10 (9.7 years
               ago); last push 2018-02-12 (8.6 years ago)
  abandoned    symfony/security-guard v5.4.45  via symfony/security-bundle, also via
               friendsofsymfony/oauth-server-bundle, friendsofsymfony/user-bundle, scheb/2fa-backup-code and 4 more
               marked abandoned by its repository; repository archived on GitHub; released 2024-09-25, before PHP 8.4 GA
               (2024-11-21); php constraint ">=7.2.5" has no upper bound
  silent       grandt/binstring 1.0.0  via wallabag/phpepub › grandt/phpresizegif
               last release 2015-08-13 (11.1 years ago); last push 2015-08-13 (11.1 years ago); released 2015-08-13,
               before PHP 8.4 GA (2024-11-21); php constraint ">=5.0" has no upper bound
  silent       grandt/phpresizegif 1.0.3  via wallabag/phpepub
               last release 2015-05-10 (11.4 years ago); last push 2015-08-13 (11.1 years ago); released 2015-05-10,
               before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound
  silent       grandt/phpzipmerge 1.0.4  via wallabag/phpepub › phpzip/phpzip
               last release 2015-08-18 (11.1 years ago); last push 2015-08-18 (11.1 years ago); released 2015-08-18,
               before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound
  silent       grandt/relativepath 1.0.2  via wallabag/phpepub
               last release 2015-05-14 (11.3 years ago); last push 2020-04-01 (6.5 years ago); released 2015-05-14,
               before PHP 8.4 GA (2024-11-21); php constraint ">=5.0" has no upper bound
  silent       phpzip/phpzip 2.0.8  via wallabag/phpepub
               last release 2015-11-16 (10.8 years ago); last push 2015-11-16 (10.8 years ago); released 2015-11-16,
               before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound
  silent       pragmarx/random v0.2.2  via pragmarx/recovery
               last release 2017-11-21 (8.8 years ago); last push 2017-12-18 (8.7 years ago); released 2017-11-21,
               before PHP 8.4 GA (2024-11-21); php constraint ">=7.0" has no upper bound
  pinned       friendsofsymfony/oauth-server-bundle dev-master  direct
               pinned to branch snapshot dev-master; last release 2019-01-23 (7.6 years ago); pulls in 2 flagged
               packages: symfony/security-guard (abandoned), friendsofsymfony/oauth2-php (stale)
  pinned       wallabag/rulerz dev-master  direct
               pinned to branch snapshot dev-master; released 2023-12-24, before PHP 8.4 GA (2024-11-21); php constraint
               ">=7.4" has no upper bound; pulls in 14 flagged packages: hoa/compiler (abandoned), hoa/consistency
               (abandoned), hoa/event (abandoned), hoa/exception (abandoned), hoa/file (abandoned) and 9 more
  pinned       wallabag/rulerz-bundle dev-master  direct
               pinned to branch snapshot dev-master; released 2023-12-24, before PHP 8.4 GA (2024-11-21); php constraint
               ">=7.4" has no upper bound; pulls in 15 flagged packages: hoa/compiler (abandoned), hoa/consistency
               (abandoned), hoa/event (abandoned), hoa/exception (abandoned), hoa/file (abandoned) and 10 more
  left-behind  doctrine/event-manager 1.2.0  direct
               branch 1.x last released 2022-10-12 (3.9 years ago); 2.x released 2.1.1 (2026-01-29)
  left-behind  lcobucci/jwt 4.3.0  direct
               branch 4.x last released 2023-01-02 (3.7 years ago); 5.x released 5.6.0 (2025-10-17)
  left-behind  scheb/2fa-backup-code v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-01-24); pulls in 1
               flagged package: symfony/security-guard (abandoned)
  left-behind  scheb/2fa-bundle v5.13.2  direct
               branch 5.x last released 2022-04-16 (4.4 years ago); 8.x released v8.6.1 (2026-07-10); released
               2022-04-16, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound; pulls in 1
               flagged package: symfony/security-guard (abandoned)
  left-behind  scheb/2fa-email v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-06-08); pulls in 1
               flagged package: symfony/security-guard (abandoned)
  left-behind  scheb/2fa-google-authenticator v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-01-24); pulls in 3
               flagged packages: symfony/security-guard (abandoned), spomky-labs/otphp (left-behind),
               thecodingmachine/safe (left-behind)
  left-behind  scheb/2fa-trusted-device v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-01-24); pulls in 1
               flagged package: symfony/security-guard (abandoned)
  old-promise  defuse/php-encryption v2.4.0  direct
               released 2023-06-19, before PHP 8.4 GA (2024-11-21); php constraint ">=5.6.0" has no upper bound; last
               release 2023-06-19 (3.2 years ago)
  old-promise  gregwar/captcha-bundle v2.2.0  direct
               released 2022-01-11, before PHP 8.4 GA (2024-11-21); php constraint ">=7.1.3" has no upper bound
  old-promise  mgargano/simplehtmldom 1.5  direct
               released 2014-01-05, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound; last
               release 2014-01-05 (12.7 years ago); last push 2022-08-04 (4.1 years ago)
  old-promise  pragmarx/recovery v0.2.1  direct
               released 2021-08-15, before PHP 8.4 GA (2024-11-21); php constraint ">=7.0" has no upper bound; last
               release 2021-08-15 (5.1 years ago); pulls in 1 flagged package: pragmarx/random (silent)
  old-promise  spiriitlabs/form-filter-bundle v10.0.2  direct
               released 2024-08-23, before PHP 8.4 GA (2024-11-21); php constraint ">=7.4" has no upper bound; pulls in
               1 flagged package: doctrine/cache (abandoned)
  old-promise  symfony/asset v5.4.45  direct
               released 2024-10-22, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/browser-kit v5.4.45  direct
               released 2024-10-22, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/config v5.4.46  direct
               released 2024-10-30, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/console v5.4.47  direct
               released 2024-11-06, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/dependency-injection v5.4.48  direct
               released 2024-11-20, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/doctrine-bridge v5.4.48  direct
               released 2024-11-20, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/error-handler v5.4.46  direct
               released 2024-11-05, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/event-dispatcher v5.4.45  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/event-dispatcher-contracts v2.5.4  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/expression-language v5.4.45  direct
               released 2024-10-04, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/filesystem v5.4.45  direct
               released 2024-10-22, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/finder v5.4.45  direct
               released 2024-09-28, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/form v5.4.45  direct
               released 2024-10-08, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/framework-bundle v5.4.45  direct
               released 2024-10-22, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/google-mailer v5.4.45  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/intl v5.4.47  direct
               released 2024-11-08, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/monolog-bundle v3.10.0  direct
               released 2023-11-06, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/options-resolver v5.4.45  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/proxy-manager-bridge v5.4.45  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound; pulls
               in 1 flagged package: friendsofphp/proxy-manager-lts (old-promise)
  old-promise  symfony/security-bundle v5.4.45  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound; pulls
               in 1 flagged package: symfony/security-guard (abandoned)
  old-promise  symfony/templating v5.4.45  direct
               released 2024-10-22, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/translation-contracts v2.5.4  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/twig-bundle v5.4.45  direct
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/webpack-encore-bundle v1.17.2  direct
               released 2023-09-26, before PHP 8.4 GA (2024-11-21); php constraint ">=7.1.3" has no upper bound
  old-promise  wallabag/phpepub 4.0.10  direct
               released 2022-03-21, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3.0" has no upper bound; last
               release 2022-03-21 (4.5 years ago); pulls in 5 flagged packages: grandt/binstring (silent),
               grandt/phpresizegif (silent), grandt/phpzipmerge (silent), grandt/relativepath (silent), phpzip/phpzip
               (silent)

medium (14)
  pinned       wallabag/rulerz-bridge dev-master  via wallabag/rulerz-bundle
               pinned to branch snapshot dev-master; released 2023-12-24, before PHP 8.4 GA (2024-11-21); php constraint
               ">=7.4" has no upper bound
  left-behind  smalot/pdfparser v1.1.0  via j0k3r/graby
               branch 1.x last released 2021-08-03 (5.1 years ago); 2.x released v2.12.5 (2026-04-17); released
               2021-08-03, before PHP 8.4 GA (2024-11-21); php constraint ">=7.1" has no upper bound
  left-behind  spomky-labs/otphp v10.0.3  via scheb/2fa-google-authenticator
               branch 10.x last released 2022-03-17 (4.5 years ago); 11.x released 11.5.0 (2026-06-06)
  left-behind  symfony/psr-http-message-bridge v2.3.1  via sentry/sentry-symfony
               branch 2.x last released 2023-07-26 (3.1 years ago); 8.x released v8.1.0 (2026-05-29); released
               2023-07-26, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  left-behind  thecodingmachine/safe v2.5.0  via scheb/2fa-google-authenticator › spomky-labs/otphp
               branch 2.x last released 2023-04-05 (3.4 years ago); 3.x released v3.4.0 (2026-02-04)
  old-promise  clue/stream-filter v1.7.0  via j0k3r/graby › php-http/message
               released 2023-12-20, before PHP 8.4 GA (2024-11-21); php constraint ">=5.3" has no upper bound
  old-promise  friendsofphp/proxy-manager-lts v1.0.18  via symfony/proxy-manager-bridge
               released 2024-03-20, before PHP 8.4 GA (2024-11-21); php constraint ">=7.1" has no upper bound
  old-promise  symfony/property-access v5.4.45  via babdev/pagerfanta-bundle, also via craue/config-bundle,
               friendsofsymfony/oauth-server-bundle, friendsofsymfony/user-bundle and 12 more
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/serializer v5.4.45  via friendsofsymfony/jsrouting-bundle
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/service-contracts v2.5.4  via doctrine/doctrine-bundle, also via babdev/pagerfanta-bundle,
               craue/config-bundle, doctrine/doctrine-migrations-bundle and 40 more
               released 2024-09-25, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  symfony/var-dumper v5.4.48  via symfony/error-handler, also via babdev/pagerfanta-bundle,
               craue/config-bundle, doctrine/doctrine-bundle and 29 more
               released 2024-11-08, before PHP 8.4 GA (2024-11-21); php constraint ">=7.2.5" has no upper bound
  old-promise  willdurand/jsonp-callback-validator v2.0.0  via friendsofsymfony/jsrouting-bundle, also via
               friendsofsymfony/rest-bundle
               released 2022-01-30, before PHP 8.4 GA (2024-11-21); php constraint ">=7.1.0" has no upper bound; last
               release 2022-01-30 (4.6 years ago); last push 2023-07-29 (3.1 years ago)
  old-promise  willdurand/negotiation 3.1.0  via friendsofsymfony/rest-bundle
               released 2022-01-30, before PHP 8.4 GA (2024-11-21); php constraint ">=7.1.0" has no upper bound; last
               release 2022-01-30 (4.6 years ago); last push 2023-08-03 (3.1 years ago)
  stale        craue/config-bundle 2.7.0  direct
               last release 2023-08-06 (3.1 years ago); pulls in 1 flagged package: doctrine/cache (abandoned)

low (2)
  stale        friendsofsymfony/oauth2-php 1.3.1  via friendsofsymfony/oauth-server-bundle
               last release 2021-04-06 (5.4 years ago)
  stale        phpdocumentor/reflection-common 2.2.0  via nelmio/api-doc-bundle › phpdocumentor/reflection-docblock
               last release 2020-06-27 (6.2 years ago)

200 packages checked · abandoned 19 · silent 8 · pinned 4 · left-behind 11 · old-promise 38 · stale 3 ·
unknown 0 · finished 18 · ok 99
priority: critical 3 · high 64 · medium 14 · low 2
pulled in by: wallabag/rulerz-bundle 15 · wallabag/rulerz 14 · wallabag/phpepub 5 ·
scheb/2fa-google-authenticator 3 · friendsofsymfony/jsrouting-bundle 2 · … and 21 more
Data as of 2026-09-15 (package repositories, repository hosts). Run composer lockrot --format=json for details.
```

## The same run as JSON

`--format=json` is the only format that carries every field. Below is the document header and the
first finding, complete except for signals S3, S4, S5 and S7, and for the rest of the `exposure`
list, which `…` stands in for:

```json
{
    "lockrot": {
        "version": "0.7.0",
        "schema": 1
    },
    "generated_at": "2026-09-15T17:31:32+00:00",
    "activity_cache_oldest_at": null,
    "packages_checked": 200,
    "include_dev": false,
    "not_from_composer_repository": 0,
    "network_failures": false,
    "counts": {
        "abandoned": 19,
        "silent": 8,
        "pinned": 4,
        "left-behind": 11,
        "old-promise": 38,
        "stale": 3,
        "unknown": 0,
        "finished": 18,
        "ok": 99
    },
    "priorities": {
        "critical": 3,
        "high": 64,
        "medium": 14,
        "low": 2,
        "none": 117
    },
    "exposure": [
        {
            "package": "wallabag/rulerz-bundle",
            "flagged": 15
        },
        {
            "package": "wallabag/rulerz",
            "flagged": 14
        },
        {
            "package": "wallabag/phpepub",
            "flagged": 5
        },
        …
    ],
    "baseline": null,
    "notes": [],
    "findings": [
        {
            "package": "sensio/framework-extra-bundle",
            "version": "v6.2.10",
            "verdict": "abandoned",
            "priority": "critical",
            "direct": true,
            "dev": false,
            "signals": [
                {
                    "id": "S1",
                    "level": "high",
                    "summary": "marked abandoned by its repository, replacement: Symfony",
                    "data": {
                        "replacement": "Symfony"
                    }
                },
                {
                    "id": "S2",
                    "level": "warn",
                    "summary": "last release 2023-02-24 (3.6 years ago)",
                    "data": {
                        "last_release": "2023-02-24T14:57:12+00:00",
                        "last_version": "v6.2.10",
                        "years": 3.6
                    }
                },
                …
            ],
            "chain": [
                "sensio/framework-extra-bundle"
            ],
            "direct_dependents": [
                "sensio/framework-extra-bundle"
            ],
            "evidence": "marked abandoned by its repository, replacement: Symfony; repository archived on GitHub; last release 2023-02-24 (3.6 years ago); last push 2023-02-24 (3.6 years ago); released 2023-02-24, before PHP 8.4 GA (2024-11-21); php constraint \">=7.2.5\" has no upper bound; pulls in 1 flagged package: doctrine/annotations (abandoned)",
            "allowlist_reason": null,
            "note": null,
            "data_date": "2026-09-15T17:31:32+00:00"
        },
        …
    ]
}
```

The cut signals have the same shape as S1 and S2; S7 carries the packages it names under `data`. So
do the other 199 findings, each with its own `priority`, `direct`, `dev`, `signals`, `chain`,
`direct_dependents`, `evidence`, `allowlist_reason`, `note` and `data_date`. `exposure` is the
`pulled in by:` line in full — every direct requirement that pulls in a flagged transitive package,
with how many, most first.

## A clean run

Most projects are not wallabag. Run against `tests/fixtures/skeletons/cakephp`, a 20-package lock
with nothing to report, the same command prints:

```text
No dependency rot found in 20 packages.

20 packages checked · abandoned 0 · silent 0 · pinned 0 · left-behind 0 · old-promise 0 · stale 0 · unknown 0 ·
finished 11 · ok 9
Data as of 2026-09-15 (package repositories, repository hosts). Run composer lockrot --format=json for details.
```

The `priority:` line is printed only when the run flagged something, so a clean run does not carry
one. The exit code is `0`; see [ci.md](ci.md).

## Related

- [verdicts.md](verdicts.md) — what each verdict and priority means
- [ci.md](ci.md) — the other five output formats
- [baseline.md](baseline.md) — accepting the findings above so CI fails only on new ones
