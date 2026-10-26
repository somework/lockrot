### lockrot: 1 of 1 package flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `tubalmartin/cssmin` | v4.1.1 | old-promise · stale | `18 = old-promise 16 + stale 2 [¼ of 8]`<br>**Do:** test it on PHP 8.4 — v4.1.1 is its newest release<br>*old-promise:* released 2018-01-15 for PHP 5 (php "\>=5.3.2"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*stale:* last release 2018-01-15 (8.7 years ago) | direct |

flags: old-promise 1 · stale 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
libyears: 0.0 behind across the one package
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 8.3 to 8.5

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
