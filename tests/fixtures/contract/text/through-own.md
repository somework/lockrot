### lockrot: 1 of 3 packages flagged — medium 1

**gate: passes** · --fail-on=high · no finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| medium | `voku/stop-words` | 2.0.1 | old-promise · stale | `9 = (old-promise 16 + stale 2 [¼ of 8]) ÷ 2 transitive`<br>**Do:** test it on PHP 8.4 — 2.0.1 is its newest release; it comes in through mautic/core-lib (jbroadway/urlify requires it): replace or update mautic/core-lib<br>*old-promise:* released 2018-11-23 for PHP 7 (php "\>=7.0.0"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*stale:* last release 2018-11-23 (7.9 years ago) | mautic/core-lib |

flags: old-promise 1 · stale 1 · 1 package carries more than one
depth: 1 of 1 flagged package is named by no line of composer.json · 2 hops 1 · deepest: `voku/stop-words`, 2 hops (`mautic/core-lib` › `jbroadway/urlify` › `voku/stop-words`)
libyears: 0.0 behind across the one package
pulled in by: `mautic/core-lib` 1
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.0 to 8.5

- note: 1 package is not from a Composer repository: its release metadata and repository activity were not read

<sub>3 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
