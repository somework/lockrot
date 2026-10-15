### lockrot: 1 of 1 packages flagged — high 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **high** | `roots/wp-config` | 1.0.0 | old-promise | `16 = old-promise 16`<br>**Do:** test it on PHP 8.4<br>*old-promise:* released 2018-08-10 for PHP 5 (php "\>=5.6"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*age:* installed 1.0.0 released 2018-08-10 (8.1 years ago), the newest on 1.x; newest release v2.0.0-beta.1 2026-03-21 (0.5 years ago); last push 2026-03-29 (0.5 years ago) | direct |

flags: old-promise 1
depth: every flagged package is named in composer.json · direct 1
libyears: 7.6 behind across the one package · 7.6 from direct requirements · furthest behind roots/wp-config 1.0.0 at 7.6
advisories: 1 package asked · packagist.org answered (0 on record for 0 packages)

<sub>1 package checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
