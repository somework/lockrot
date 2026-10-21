### lockrot: 1 of 2 packages flagged — critical 1

**gate: fails** · --fail-on=high · 1 finding fails it · target php 8.4 (--target-php)

| Verdict | Package | Version | Flags | Why | Via |
|---|---|---|---|---|---|
| **critical** | `geoip2/geoip2` | v2.4.5 | left-behind · old-promise · vulnerable | `36 = left-behind 16 + old-promise 4 [¼ of 16] + vulnerable 16 [high advisory]`<br>**Do:** raise require.php to \>=8.1, then require geoip2/geoip2 ^3.4 — clears left-behind, old-promise and 2 advisories; 3.x needs php \>=8.1, require.php allows 7.2.5 (a major raise); the target php 8.4 runs it; a new major brings its own requirements, which lockrot does not solve<br>*left-behind:* branch 2.x last released 2022-08-05 (4.2 years ago); 3.x released v3.4.0 (2026-07-16), needs php \>=8.1 above the project's php \>=7.2.5; no releasing branch within reach<br>*old-promise:* released 2017-01-31 for PHP 5 (php "\>=5.3.1"), before PHP 8 existed (8.0 GA 2020-11-26); admits 8.4 untested<br>*vulnerable:* 2 advisories: 1 high, 1 medium — 1 of 2 fixed on 2.x; worst: CVE-2026-00011 an advisory only the next major fixes (‡)<br>pulls in 1 flagged package: maxmind/web-service-common (left-behind) | direct |

flags: left-behind 1 · old-promise 1 · vulnerable 1 · 1 package carries more than one
depth: every flagged package is named in composer.json · direct 1
security: 1 vulnerable package · 2 advisories: 1 high, 1 medium
libyears: 9.5 behind across the one package · 9.5 from direct requirements · furthest behind geoip2/geoip2 v2.4.5 at 9.5
advisories: 1 package asked · packagist.org answered (2 on record for 1 package)
php: the lock installs on PHP 7.2 to 8.5

<sub>2 packages checked — data as of 2026-10-01 (package repositories, repository hosts) · score model 1. Run `composer lockrot --format=json` for details.</sub>
