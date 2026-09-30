---
title: Example run — lockrot on wallabag's composer.lock
description: "A recorded lockrot run on wallabag's public composer.lock: the full table output, the same lock as JSON, and a clean run to compare it with."
---

# Example run

What lockrot prints on a real lock, and how to read it. Recorded on 2026-09-30 with lockrot 0.13.0
on a snapshot of [wallabag](https://github.com/wallabag/wallabag)'s public `composer.lock`, with
`GITHUB_TOKEN` set, so no repository check was capped, and `COLUMNS=120`, so the wrapping is fixed.

```bash
COLUMNS=120 php lockrot.phar --target-php=8.4
```

On your project: `composer lockrot`, or `php lockrot.phar -d path/to/project`.

```text
critical (3)
  abandoned    sensio/framework-extra-bundle v6.2.10  direct
               marked abandoned by its repository, replacement: Symfony; repository archived on GitHub; last release
               2023-02-24 (3.6 years ago); last push 2023-02-24 (3.6 years ago); pulls in 1 flagged package:
               doctrine/annotations (abandoned)
  silent       javibravo/simpleue 2.1.0  direct
               last release 2017-11-15 (8.9 years ago); last push 2017-11-18 (8.9 years ago); released 2017-11-15 for
               PHP 5 (php ">=5.5"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested
  silent       mnapoli/piwik-twig-extension 3.0.0  direct
               last release 2020-04-24 (6.4 years ago); last push 2020-04-28 (6.4 years ago); released 2020-04-24 for
               PHP 7 (php ">=7.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested

high (38)
  abandoned    behat/transliterator v1.5.0  via stof/doctrine-extensions-bundle › gedmo/doctrine-extensions
               marked abandoned by its repository; repository archived on GitHub; last release 2022-03-30 (4.5 years
               ago)
  abandoned    doctrine/annotations 2.0.2  via sensio/framework-extra-bundle
               marked abandoned by its repository
  abandoned    doctrine/cache 2.2.0  via doctrine/doctrine-bundle, also via craue/config-bundle,
               doctrine/doctrine-migrations-bundle, doctrine/orm and 2 more
               marked abandoned by its repository; last release 2022-05-20 (4.4 years ago)
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
               last release 2017-11-21 (8.9 years ago); last push 2017-12-18 (8.8 years ago); released 2017-11-21 for
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
               branch 2.x last released 2023-08-06 (3.2 years ago); 3.x released 3.0.0 (2026-09-15); require ^3.0 to
               follow; pulls in 1 flagged package: doctrine/cache (abandoned)
  left-behind  doctrine/event-manager 1.2.0  direct
               branch 1.x last released 2022-10-12 (4.0 years ago); 2.x released 2.1.1 (2026-01-29); require ^2.1 to
               follow
  left-behind  lcobucci/jwt 4.3.0  direct
               branch 4.x last released 2023-01-02 (3.7 years ago); 5.x released 5.6.0 (2025-10-17); require ^5.6 to
               follow
  left-behind  scheb/2fa-backup-code v5.13.2  direct
               branch 5.x last released 2022-01-03 (4.7 years ago); 7.x released v7.14.0 (2026-01-24); require ^7.14 to
               follow; the age of the package was not read (its newest releases are dated by a commit their tags share),
               so S2 and S8 could not measure it; pulls in 1 flagged package: symfony/security-guard (abandoned)
  left-behind  scheb/2fa-bundle v5.13.2  direct
               branch 5.x last released 2022-04-16 (4.5 years ago); 8.x released v8.6.1 (2026-07-10), needs php ~8.4.0
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
  left-behind  symfony/webpack-encore-bundle v1.17.2  direct
               branch 1.x last released 2023-09-26 (3.0 years ago); 2.x released 2.4.2 (2026-09-17); require ^2.4 to
               follow
  left-behind  spomky-labs/otphp v10.0.3  via scheb/2fa-google-authenticator
               branch 10.x last released 2022-03-17 (4.5 years ago); 11.x released 11.5.0 (2026-06-06); 2 security
               advisories affect v10.0.3 (PKSA-kbc7-dq62-pt7d, PKSA-qv5y-crcz-9nxw); fixed by 11.5.0; no fix expected on
               10.x
  old-promise  mgargano/simplehtmldom 1.5  direct
               released 2014-01-05 for PHP 5 (php ">=5.3.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4
               untested; last release 2014-01-05 (12.7 years ago); last push 2022-08-04 (4.2 years ago)

medium (7)
  pinned       wallabag/rulerz-bridge dev-master  via wallabag/rulerz-bundle
               pinned to branch snapshot dev-master
  left-behind  smalot/pdfparser v1.1.0  via j0k3r/graby
               branch 1.x last released 2021-08-03 (5.2 years ago); 2.x released v2.12.5 (2026-04-17)
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

low (3)
  stale        friendsofsymfony/oauth2-php 1.3.1  via friendsofsymfony/oauth-server-bundle
               last release 2021-04-06 (5.5 years ago)
  stale        willdurand/jsonp-callback-validator v2.0.0  via friendsofsymfony/jsrouting-bundle, also via
               friendsofsymfony/rest-bundle
               last release 2022-01-30 (4.7 years ago); last push 2023-07-29 (3.2 years ago)
  stale        willdurand/negotiation 3.1.0  via friendsofsymfony/rest-bundle
               last release 2022-01-30 (4.7 years ago); last push 2023-08-03 (3.2 years ago)

200 packages checked · abandoned 19 · silent 8 · pinned 4 · left-behind 13 · old-promise 1 · stale 6 ·
unknown 0 · finished 18 · ok 131
priority: critical 3 · high 38 · medium 7 · low 3
libyears: 181.7 behind across 194 of 200 packages · 113.6 from direct requirements ·
furthest behind phpdocumentor/reflection-common 2.2.0 at 5.4
pulled in by: wallabag/rulerz-bundle 15 · wallabag/rulerz 14 · wallabag/phpepub 5 ·
scheb/2fa-google-authenticator 3 · friendsofsymfony/oauth-server-bundle 2 · … and 19 more
1 security advisory on 1 package the report does not flag; see composer audit (it counts packages-dev too, which this
run skipped; pass --dev to include them)
Data as of 2026-09-30 (package repositories, repository hosts). Run composer lockrot --format=json for details.
```

## Reading the table {#the-table-format}

- **Groups.** One per [priority](verdicts.md#priority), highest first, with its count. `--all` adds
  a final `not flagged` group holding every other package.

- **A row's first line.** The verdict, the package and its installed version, then `direct`, `via`
  and the shortest chain from a direct requirement, or `?` when nothing in the project reaches it.
  `also via` names up to three other direct requirements that reach it, then `and N more`:
  `hoa/ruler` stays installed while either `wallabag/rulerz` or `wallabag/rulerz-bundle` does. With
  a [baseline](baseline.md), the verdict also reads `(baseline)` or `(was <verdict>)`.

- **The lines under it.** The evidence: what each signal found, with its dates. `pulls in N flagged
  packages:` on a direct requirement is [S7](verdicts.md#transitive-exposure).

- **The summary.** In order, each line only when it applies:

    - the count per verdict;
    - `priority:`, when something is flagged;
    - [`libyears:`](verdicts.md#libyears), on every run;
    - `pulled in by:`, the S7 totals per direct requirement;
    - the advisories on packages the report does not flag, left to `composer audit`
      ([security advisories](verdicts.md#security-advisories));
    - with a baseline, its summary line ([baseline.md](baseline.md)).

- **The footer.** `Data as of` is the date the report was generated, in UTC, followed by the
  sources; the clause changes when an answer came from lockrot's cache
  ([how fresh the data is](internals.md#two-sources-two-clocks)). Run notes follow as `note:` lines
  ([run notes](notes.md)), and with a baseline a `note:` line names its stale entries.

- **Width.** Lines wrap to `COLUMNS` when it is a positive integer; with `COLUMNS` unset, to the
  width Composer's console reports (with no terminal: 80, or none under Composer 2.2 LTS); otherwise
  to 120. Never below 40. A command or path in the summary stays on one line, so it can be wider. For a `table` report written to a file, see
  [Writing reports to files](configuration.md#writing-reports-to-files).

- **Colour.** On a terminal, a row's verdict label is red in the `critical` and `high` groups and
  yellow in `medium`.

## The same lock as JSON

`--format=json` writes every field of the report; the `html` page carries it as its embedded data's
`report` key ([`--format=html`](ci.md#-formathtml)). This is the same run's document, abridged: `…` marks each cut (signals S3, S4 and S7 of the first
finding, the rest of `exposure`, the other findings).

```json
{
    "$schema": "https://lockrot.dev/schema/report-1.json",
    "lockrot": {
        "version": "0.13.0",
        "schema": 1
    },
    "generated_at": "2026-09-30T19:18:50+00:00",
    "run": {
        "project": "wallabag/wallabag",
        "root_package": "wallabag/wallabag",
        "target_php": "8.4",
        "project_php": ">=8.2",
        "lock_file": "composer.lock",
        "fail_on": "none",
        "fail_on_kind": "none",
        "strict_network": false,
        "mode": "check",
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
        "left-behind": 13,
        "old-promise": 1,
        "stale": 6,
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
        "high": 38,
        "medium": 7,
        "low": 3,
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
    "exposure_rule": {
        "max_fan_in": 8
    },
    "unattributed": [],
    "libyears": {
        "total": 181.7,
        "direct_requirements": 113.59,
        "measured": 194,
        "unmeasured": {
            "branch_snapshot": 4,
            "no_stable_release_date": 2,
            "not_from_composer_repository": 0,
            "metadata_unavailable": 0
        },
        "furthest_behind": {
            "package": "phpdocumentor/reflection-common",
            "version": "2.2.0",
            "libyears": 5.37
        }
    },
    "baseline": null,
    "gate": {
        "fails": false,
        "tripped_by": [],
        "fail_on_applied": true
    },
    "notes": [],
    "note_details": [],
    "findings": [
        {
            "package": "sensio/framework-extra-bundle",
            "version": "v6.2.10",
            "verdict": "abandoned",
            "priority": "critical",
            "direct": true,
            "dev": false,
            "from_composer_repository": true,
            "origin": {
                "kind": "packagist",
                "registry": "packagist.org",
                "package_url": "https://packagist.org/packages/sensio/framework-extra-bundle",
                "local": false
            },
            "replacement": null,
            "replacement_url": null,
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
            "data_date": "2026-09-30T19:18:50+00:00",
            "libyears": 0,
            "libyears_unmeasured": null,
            "priority_basis": {
                "base": "critical",
                "steps": []
            },
            "no_fix_expected": [],
            "baseline": null,
            "gate": {
                "reaches_fail_on": false,
                "fails": false,
                "exempt_by": null
            }
        },
        …
    ]
}
```

- Every finding carries the keys the one shown carries;
  [schema.md](schema.md#what-the-report-schema-types) defines each. The cut signals have the shape
  S1 and S2 show, and S7's `data` names the packages it counts.

- `exposure` is the `pulled in by:` line in full, most first. `exposure_rule` and `unattributed`
  belong to the same count
  ([shared packages and `unattributed`](verdicts.md#shared-packages-and-unattributed)).

- sensio/framework-extra-bundle is `abandoned` and 0 libyears behind: its last release is the one
  installed. A verdict and [libyears](verdicts.md#libyears) measure different things.

- `gate.fails` is false: under `fail_on` `none` no finding reaches the threshold, so the run exits
  `0` ([exit codes](ci.md#exit-codes)).

- `notes` and `note_details` are empty: every source answered and no cap applied
  ([run notes](notes.md)).

## A clean run

Recorded with the same version, on the same date, on a fresh `cakephp/app` skeleton whose lock has
nothing to flag. The run exited `0` ([exit codes](ci.md#exit-codes)).

```text
No dependency rot found in 20 packages.

20 packages checked · abandoned 0 · silent 0 · pinned 0 · left-behind 0 · old-promise 0 · stale 0 · unknown 0 ·
finished 11 · ok 9
libyears: 0.9 behind across all 20 packages · 0.0 from direct requirements ·
furthest behind laminas/laminas-httphandlerrunner 2.13.0 at 0.9
Data as of 2026-09-30 (package repositories, repository hosts). Run composer lockrot --format=json for details.
```

## Related

- [verdicts.md](verdicts.md) — what each verdict, signal and priority in this output means
- [ci.md](ci.md) — every other output format, and the exit codes
- [baseline.md](baseline.md) — accept these findings so CI fails only on new ones
- [internals.md](internals.md) — where each date and the footer's sources come from
