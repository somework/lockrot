---
title: lockrot example run — a 200-package composer.lock report
description: "One complete lockrot run against wallabag's public 200-package composer.lock: the table output, the same lock as JSON, and what a clean run looks like."
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
               2023-02-24 (3.6 years ago); last push 2023-02-24 (3.6 years ago); pulls in 1 flagged package:
               doctrine/annotations (abandoned)
  silent       javibravo/simpleue 2.1.0  direct
               last release 2017-11-15 (8.9 years ago); last push 2017-11-18 (8.8 years ago); released 2017-11-15 for
               PHP 5 (php ">=5.5"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  silent       mnapoli/piwik-twig-extension 3.0.0  direct
               last release 2020-04-24 (6.4 years ago); last push 2020-04-28 (6.4 years ago); released 2020-04-24 for
               PHP 7 (php ">=7.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested

high (37)
  abandoned    behat/transliterator v1.5.0  via stof/doctrine-extensions-bundle › gedmo/doctrine-extensions
               marked abandoned by its repository; repository archived on GitHub; last release 2022-03-30 (4.5 years
               ago)
  abandoned    doctrine/annotations 2.0.2  via sensio/framework-extra-bundle
               marked abandoned by its repository
  abandoned    doctrine/cache 2.2.0  via doctrine/doctrine-bundle, also via craue/config-bundle,
               doctrine/doctrine-migrations-bundle, doctrine/orm and 2 more
               marked abandoned by its repository; last release 2022-05-20 (4.3 years ago)
  abandoned    hoa/compiler 3.17.08.08  via wallabag/rulerz › hoa/ruler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-08 (9.1 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/consistency 1.17.05.02  via wallabag/rulerz, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-29 (9.1 years
               ago); last push 2021-04-28 (5.4 years ago); released 2017-05-02 for PHP 5 (php ">=5.5.0"), before PHP 8
               existed (8.0 GA 2020-11-26); admits 8.4 untested
  abandoned    hoa/event 1.17.01.13  via wallabag/rulerz › hoa/consistency › hoa/exception, also via
               wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-30 (9.1 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/exception 1.17.01.16  via wallabag/rulerz › hoa/consistency, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-08-30 (9.1 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/file 1.17.07.11  via wallabag/rulerz › hoa/ruler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-07-11 (9.2 years
               ago); last push 2018-01-23 (8.7 years ago)
  abandoned    hoa/iterator 2.17.01.10  via wallabag/rulerz › hoa/ruler › hoa/compiler, also via
               wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-10 (9.7 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/math 1.17.05.16  via wallabag/rulerz › hoa/ruler › hoa/compiler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-05-16 (9.4 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/protocol 1.17.01.14  via wallabag/rulerz › hoa/ruler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-14 (9.7 years
               ago); last push 2021-04-29 (5.4 years ago)
  abandoned    hoa/regex 1.17.01.13  via wallabag/rulerz › hoa/ruler › hoa/compiler, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-01-13 (9.7 years
               ago); last push 2018-07-20 (8.2 years ago)
  abandoned    hoa/ruler 2.17.05.16  via wallabag/rulerz, also via wallabag/rulerz-bundle
               marked abandoned by its repository; repository archived on GitHub; last release 2017-05-16 (9.4 years
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
               marked abandoned by its repository; repository archived on GitHub
  silent       grandt/binstring 1.0.0  via wallabag/phpepub › grandt/phpresizegif
               last release 2015-08-13 (11.1 years ago); last push 2015-08-13 (11.1 years ago); released 2015-08-13 for
               PHP 5 (php ">=5.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  silent       grandt/phpresizegif 1.0.3  via wallabag/phpepub
               last release 2015-05-10 (11.4 years ago); last push 2015-08-13 (11.1 years ago); released 2015-05-10 for
               PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  silent       grandt/phpzipmerge 1.0.4  via wallabag/phpepub › phpzip/phpzip
               last release 2015-08-18 (11.1 years ago); last push 2015-08-18 (11.1 years ago); released 2015-08-18 for
               PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  silent       grandt/relativepath 1.0.2  via wallabag/phpepub
               last release 2015-05-14 (11.4 years ago); last push 2020-04-01 (6.5 years ago); released 2015-05-14 for
               PHP 5 (php ">=5.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  silent       phpzip/phpzip 2.0.8  via wallabag/phpepub
               last release 2015-11-16 (10.9 years ago); last push 2015-11-16 (10.9 years ago); released 2015-11-16 for
               PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  silent       pragmarx/random v0.2.2  via pragmarx/recovery
               last release 2017-11-21 (8.8 years ago); last push 2017-12-18 (8.8 years ago); released 2017-11-21 for
               PHP 7 (php ">=7.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  pinned       friendsofsymfony/oauth-server-bundle dev-master  direct
               pinned to branch snapshot dev-master; last release 2019-01-23 (7.7 years ago); pulls in 2 flagged
               packages: symfony/security-guard (abandoned), friendsofsymfony/oauth2-php (stale)
  pinned       wallabag/rulerz dev-master  direct
               pinned to branch snapshot dev-master; pulls in 14 flagged packages: hoa/compiler (abandoned),
               hoa/consistency (abandoned), hoa/event (abandoned), hoa/exception (abandoned), hoa/file (abandoned) and 9
               more
  pinned       wallabag/rulerz-bundle dev-master  direct
               pinned to branch snapshot dev-master; pulls in 15 flagged packages: hoa/compiler (abandoned),
               hoa/consistency (abandoned), hoa/event (abandoned), hoa/exception (abandoned), hoa/file (abandoned) and
               10 more
  left-behind  craue/config-bundle 2.7.0  direct
               branch 2.x last released 2023-08-06 (3.1 years ago); 3.x released 3.0.0 (2026-09-15); require ^3.0 to
               follow; pulls in 1 flagged package: doctrine/cache (abandoned)
  left-behind  doctrine/event-manager 1.2.0  direct
               branch 1.x last released 2022-10-12 (3.9 years ago); 2.x released 2.1.1 (2026-01-29); require ^2.1 to
               follow
  left-behind  lcobucci/jwt 4.3.0  direct
               branch 4.x last released 2023-01-02 (3.7 years ago); 5.x released 5.6.0 (2025-10-17); require ^5.6 to
               follow
  left-behind  scheb/2fa-backup-code v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-01-24); require ^7.14 to
               follow; the age of the package was not read (its newest releases are dated by a commit their tags share),
               so S2 and S8 could not measure it; pulls in 1 flagged package: symfony/security-guard (abandoned)
  left-behind  scheb/2fa-bundle v5.13.2  direct
               branch 5.x last released 2022-04-16 (4.4 years ago); 8.x released v8.6.1 (2026-07-10), needs php ~8.4.0
               || ~8.5.0 above the project's php >=8.2; 7.x released v7.14.0 (2026-06-12); require ^7.14 to follow;
               pulls in 1 flagged package: symfony/security-guard (abandoned)
  left-behind  scheb/2fa-email v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-06-08); require ^7.14 to
               follow; pulls in 1 flagged package: symfony/security-guard (abandoned)
  left-behind  scheb/2fa-google-authenticator v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-01-24); require ^7.14 to
               follow; the age of the package was not read (its newest releases are dated by a commit their tags share),
               so S2 and S8 could not measure it; pulls in 3 flagged packages: symfony/security-guard (abandoned),
               spomky-labs/otphp (left-behind), thecodingmachine/safe (left-behind)
  left-behind  scheb/2fa-trusted-device v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-01-24); require ^7.14 to
               follow; the age of the package was not read (its newest releases are dated by a commit their tags share),
               so S2 and S8 could not measure it; pulls in 1 flagged package: symfony/security-guard (abandoned)
  left-behind  spomky-labs/otphp v10.0.3  via scheb/2fa-google-authenticator
               branch 10.x last released 2022-03-17 (4.5 years ago); 11.x released 11.5.0 (2026-06-06); 2 security
               advisories affect v10.0.3 (PKSA-kbc7-dq62-pt7d, PKSA-qv5y-crcz-9nxw); fixed by 11.5.0; no fix expected on
               10.x
  old-promise  mgargano/simplehtmldom 1.5  direct
               released 2014-01-05 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
               untested; last release 2014-01-05 (12.7 years ago); last push 2022-08-04 (4.1 years ago)

medium (7)
  pinned       wallabag/rulerz-bridge dev-master  via wallabag/rulerz-bundle
               pinned to branch snapshot dev-master
  left-behind  smalot/pdfparser v1.1.0  via j0k3r/graby
               branch 1.x last released 2021-08-03 (5.1 years ago); 2.x released v2.12.5 (2026-04-17)
  left-behind  symfony/psr-http-message-bridge v2.3.1  via sentry/sentry-symfony
               branch 2.x last released 2023-07-26 (3.2 years ago); 8.x released v8.1.0 (2026-05-29), needs php >=8.4.1
               above the project's php >=8.2; 7.x released v7.4.8 (2026-03-24)
  left-behind  thecodingmachine/safe v2.5.0  via scheb/2fa-google-authenticator › spomky-labs/otphp
               branch 2.x last released 2023-04-05 (3.5 years ago); 3.x released v3.4.0 (2026-02-04)
  stale        defuse/php-encryption v2.4.0  direct
               last release 2023-06-19 (3.3 years ago)
  stale        pragmarx/recovery v0.2.1  direct
               last release 2021-08-15 (5.1 years ago); pulls in 1 flagged package: pragmarx/random (silent)
  stale        wallabag/phpepub 4.0.10  direct
               last release 2022-03-21 (4.5 years ago); pulls in 5 flagged packages: grandt/binstring (silent),
               grandt/phpresizegif (silent), grandt/phpzipmerge (silent), grandt/relativepath (silent), phpzip/phpzip
               (silent)

low (4)
  stale        friendsofsymfony/oauth2-php 1.3.1  via friendsofsymfony/oauth-server-bundle
               last release 2021-04-06 (5.5 years ago)
  stale        phpdocumentor/reflection-common 2.2.0  via nelmio/api-doc-bundle › phpdocumentor/reflection-docblock
               last release 2020-06-27 (6.2 years ago)
  stale        willdurand/jsonp-callback-validator v2.0.0  via friendsofsymfony/jsrouting-bundle, also via
               friendsofsymfony/rest-bundle
               last release 2022-01-30 (4.6 years ago); last push 2023-07-29 (3.2 years ago)
  stale        willdurand/negotiation 3.1.0  via friendsofsymfony/rest-bundle
               last release 2022-01-30 (4.6 years ago); last push 2023-08-03 (3.1 years ago)

200 packages checked · abandoned 19 · silent 8 · pinned 4 · left-behind 12 · old-promise 1 · stale 7 ·
unknown 0 · finished 18 · ok 131
priority: critical 3 · high 37 · medium 7 · low 4
libyears: 171.3 behind across 194 of 200 packages · 111.7 from direct requirements ·
furthest behind smalot/pdfparser v1.1.0 at 4.7
pulled in by: wallabag/rulerz-bundle 15 · wallabag/rulerz 14 · wallabag/phpepub 5 ·
scheb/2fa-google-authenticator 3 · friendsofsymfony/oauth-server-bundle 2 · … and 20 more
Data as of 2026-09-23 (package repositories, repository hosts). Run composer lockrot --format=json for details.
```

## The same lock as JSON

`--format=json` is the only format that carries every field. Below is the document header and the
first finding, complete except for signals S3, S4 and S7, and for the rest of the `exposure`
list, which `…` stands in for:

```json
{
    "$schema": "https://lockrot.dev/schema/report-1.json",
    "lockrot": {
        "version": "0.11.0",
        "schema": 1
    },
    "generated_at": "2026-09-23T08:32:48+00:00",
    "run": {
        "project": "wallabag/wallabag",
        "target_php": "8.4",
        "lock_file": "composer.lock",
        "fail_on": "none",
        "thresholds": {
            "release-warn-years": 3,
            "release-high-years": 5,
            "push-warn-years": 3,
            "push-high-years": 5
        },
        "flagged_verdicts": [
            "abandoned",
            "silent",
            "pinned",
            "left-behind",
            "old-promise",
            "stale"
        ]
    },
    "activity_cache_oldest_at": null,
    "packages_checked": 200,
    "include_dev": false,
    "not_from_composer_repository": 0,
    "network_failures": false,
    "counts": {
        "abandoned": 19,
        "silent": 8,
        "pinned": 4,
        "left-behind": 12,
        "old-promise": 1,
        "stale": 7,
        "unknown": 0,
        "finished": 18,
        "ok": 131
    },
    "abandoned": {
        "total": 19,
        "with_replacement": 0
    },
    "priorities": {
        "critical": 3,
        "high": 37,
        "medium": 7,
        "low": 4,
        "none": 149
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
    "libyears": {
        "total": 171.34,
        "direct_requirements": 111.72,
        "measured": 194,
        "unmeasured": {
            "branch_snapshot": 4,
            "no_stable_release_date": 2,
            "not_from_composer_repository": 0,
            "metadata_unavailable": 0
        },
        "furthest_behind": {
            "package": "smalot/pdfparser",
            "version": "v1.1.0",
            "libyears": 4.7
        }
    },
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
            "replacement": null,
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
                        "years": 3.6,
                        "dated_by": null
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
            "evidence": "marked abandoned by its repository, replacement: Symfony; repository archived on GitHub; last release 2023-02-24 (3.6 years ago); last push 2023-02-24 (3.6 years ago); pulls in 1 flagged package: doctrine/annotations (abandoned)",
            "allowlist_reason": null,
            "note": null,
            "data_date": "2026-09-23T08:32:48+00:00",
            "libyears": 0,
            "baseline": null
        },
        …
    ]
}
```

The cut signals have the same shape as S1 and S2; S7 carries the packages it names under `data`. So
do the other 199 findings, each with its own `priority`, `direct`, `dev`, `replacement`, `signals`,
`chain`, `direct_dependents`, `evidence`, `allowlist_reason`, `note`, `data_date`, `libyears` and
`baseline`. `exposure` is the
`pulled in by:` line in full — every direct requirement that pulls in an attributable flagged transitive
package, with how many, most first. From 0.13.0 the document also carries `exposure_rule`, the cap that list
is drawn by (`{"max_fan_in": 8}`), and `unattributed`, the flagged packages reached from more direct
requirements than that and so counted under none; see
[transitive exposure](verdicts.md#transitive-exposure). `libyears` is the [libyears block](verdicts.md#libyears), summed from the
findings: sensio/framework-extra-bundle is abandoned and *zero* libyears behind — its last release is
the one installed — which is the point of keeping the two numbers apart.

## A clean run

Most projects are not wallabag. Run against `tests/fixtures/skeletons/cakephp`, a 20-package lock
with nothing to report, the same command prints:

```text
No dependency rot found in 20 packages.

20 packages checked · abandoned 0 · silent 0 · pinned 0 · left-behind 0 · old-promise 0 · stale 0 · unknown 0 ·
finished 11 · ok 9
libyears: 0.9 behind across all 20 packages · 0.0 from direct requirements ·
furthest behind laminas/laminas-httphandlerrunner 2.13.0 at 0.9
Data as of 2026-09-23 (package repositories, repository hosts). Run composer lockrot --format=json for details.
```

The `priority:` line is printed only when the run flagged something, so a clean run does not carry
one. The exit code is `0`; see [ci.md](ci.md).

## Related

- [verdicts.md](verdicts.md) — what each verdict and priority means
- [ci.md](ci.md) — the other five output formats
- [baseline.md](baseline.md) — accepting the findings above so CI fails only on new ones
