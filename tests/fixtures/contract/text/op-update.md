### lockrot: 1 of 1 package flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `matomo/network` | 2.0.1 | old-promise | `16 = old-promise 16`<br>**Do:** `composer update matomo/network` (gets 2.0.3) — clears old-promise<br>*old-promise:* released 2020-10-05 for PHP 5 (php "\>=5.4"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*age:* installed 2.0.1 released 2020-10-05 (6.0 years ago); 2.x last released 2.0.3 2026-04-17 (0.5 years ago), its newest release; last push 2026-04-17 (0.5 years ago) | direct |

flags: old-promise 1
depth: every flagged package is named in composer.json · direct 1
libyears: 5.5 behind across the one package · 5.5 from direct requirements · furthest behind matomo/network 2.0.1 at 5.5
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)
php: the lock installs on PHP 7.2 to 8.5

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
