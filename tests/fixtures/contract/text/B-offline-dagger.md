### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high,unchecked · 1 finding fails it (high 1, unchecked 1) · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `symfony/monolog-bridge` | v2.6.13 | left-behind · old-promise | `20 = left-behind 16 + old-promise 4 [¼ of 16]`<br>**Do:** require symfony/monolog-bridge ^5.4 — clears left-behind and old-promise; 5.x needs php \>=7.2.5, which require.php allows; the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 2.x last released 2019-11-13 (6.9 years ago); 6.x released v6.4.45 (2026-08-30), needs php \>=8.1 above the project's php \>=7.2.5; 5.x released v5.4.52 (2026-05-07)<br>*old-promise:* released 2016-01-14 for PHP 5 (php "\>=5.3.3"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>checks missing: S9 (offline) | direct |

flags: left-behind 1 · old-promise 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: no vulnerable package · advisories not checked
libyears: 10.6 behind across the one package · 10.6 from direct requirements · furthest behind symfony/monolog-bridge v2.6.13 at 10.6
advisories: 0 packages asked, 1 not asked (offline) · packagist.org not asked (offline)

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
