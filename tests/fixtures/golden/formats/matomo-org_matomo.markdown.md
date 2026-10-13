### lockrot: dependency rot in 7 of 52 packages

| Priority | Package | Version | Verdict | Evidence | Via |
|---|---|---|---|---|---|
| critical | `lox/xhprof` | dev-master | **abandoned** | marked abandoned by its repository; repository archived on GitHub; last push 2022-07-25 (4.1 years ago); released 2015-08-31 for PHP 5 (php "\>=5.2.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested; pinned to branch snapshot dev-master | direct |
| critical | `mustangostang/spyc` | 0.6.3 | **silent** | last release 2019-09-10 (7.0 years ago); last push 2020-03-16 (6.5 years ago); released 2019-09-10 for PHP 5 (php "\>=5.3.1"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested | direct |
| high | `pear/console_getopt` | v1.4.3 | **silent** | last release 2019-11-20 (6.8 years ago); last push 2021-08-11 (5.1 years ago) | matomo/decompress › pear/archive\_tar › pear/pear-core-minimal |
| high | `matomo/referrer-spam-list` | dev-master | **pinned** | pinned to branch snapshot dev-master | direct |
| high | `matomo/searchengine-and-social-list` | dev-master | **pinned** | pinned to branch snapshot dev-master; last release 2023-01-20 (3.6 years ago) | direct |
| medium | `pear/pear_exception` | v1.0.2 | **stale** | last release 2021-03-21 (5.5 years ago) | direct |
| low | `matomo/doctrine-cache-fork` | 1.10.4 | **stale** | last release 2021-09-13 (5.0 years ago); last push 2021-09-13 (5.0 years ago) | matomo/cache |

libyears: 0.9 behind across 47 of 52 packages · 0.1 from direct requirements · furthest behind maxmind-db/reader v1.13.1 at 0.8

pulled in by: matomo/cache 1 · matomo/decompress 1

<sub>52 packages checked · abandoned 1 · silent 2 · pinned 2 · left-behind 0 · old-promise 0 · stale 2 · unknown 0 · finished 9 · ok 36 — data as of 2026-09-14 (package repositories, repository hosts). Run `composer lockrot --format=json` for details.</sub>
