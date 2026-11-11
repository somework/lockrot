### lockrot: 1 of 1 package flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `tubalmartin/cssmin` | v4.1.1 | old-promise | `16 = old-promise 16`<br>**Do:** require tubalmartin/cssmin ^5.0.0 — clears old-promise; 5.x needs php \>=7.2, which require.php allows; the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*old-promise:* released 2018-01-15 for PHP 5 (php "\>=5.3.2"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*age:* installed v4.1.1 released 2018-01-15 (8.7 years ago); newest release v5.0.0 2025-01-01 (1.7 years ago); last push 2023-12-22 (2.8 years ago) | direct |

flags: old-promise 1\
depth: every flagged package is named in composer.json · direct 1\
libyears: 7.0 behind across the one package · 7.0 from direct requirements · furthest behind `tubalmartin/cssmin` v4.1.1 at 7.0\
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)\
php: the lock installs on PHP 8.3 to 8.5

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run lockrot with `--format=json` for details.</sub>
